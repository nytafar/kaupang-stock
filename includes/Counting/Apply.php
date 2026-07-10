<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Support\ProductSearch;

/**
 * The drift-guarded relative-variance apply (§6.3 "Apply"). ONLY for status=review.
 *
 * Per counted line the posted movement is `delta = counted − expected` — the
 * count-time variance, NOT an absolute "set". Relative deltas mean a sale that
 * happened AFTER the shelf was counted survives the apply (Odoo's drift bug, solved
 * structurally); an absolute set would silently undo it. Only non-zero deltas become
 * MovementIntents; the whole apply is ONE Ledger::recordBatch() call.
 *
 * Drift guard: before posting we compare each line's live Balances::onHand() to its
 * snapshot `expected`. Any line where they differ (or any uncounted line) is an
 * "issue"; if issues exist and !$confirmed we return confirm_required so the UI can
 * show ONE combined summary dialog (drill-down links + a single "Bruk likevel").
 * The acknowledgment is informational — the relative math needs no correction, so
 * confirming simply proceeds with the same deltas.
 *
 * Idempotency is two-layered: a hard status gate (an applied count re-applies as ok
 * with 0 new movements) plus per-line idempotency keys `count_line:{id}:apply` that
 * dedupe at the Ledger even if the status gate is somehow bypassed.
 *
 * @return array{status:string,issues:array,movements:int,message:string}
 */
final class Apply {

    /**
     * @return array{status:'ok'|'confirm_required'|'error',issues:array,movements:int,message:string}
     */
    public static function apply(int $countId, bool $confirmed): array {
        $count = Counts::find($countId);
        if ($count === null) {
            return self::error(\__('Count not found.', 'kaupang-stock'));
        }

        $status = (string) $count['status'];

        // Hard idempotency gate: an already-applied count is a no-op success. The
        // ledger keys would dedupe anyway, but this avoids re-posting work and
        // re-stamping applied_by/applied_at.
        if ($status === Counts::STATUS_APPLIED) {
            return self::ok(0, \__('Count already applied.', 'kaupang-stock'));
        }
        if ($status !== Counts::STATUS_REVIEW) {
            return self::error(\__('Only a count in review can be applied.', 'kaupang-stock'));
        }

        $lines = Counts::lines($countId);

        // Recount-flagged lines block apply until re-counted or overridden (§6.3).
        $blocked = [];
        foreach ($lines as $line) {
            if ((int) $line['recount'] === 1) {
                $blocked[] = self::issueRow($line, 'recount');
            }
        }
        if (!empty($blocked)) {
            return [
                'status'    => 'error',
                'issues'    => $blocked,
                'movements' => 0,
                'message'   => \__('Some lines are flagged for recount. Re-count or override them before applying.', 'kaupang-stock'),
            ];
        }

        // Build the relative-variance intents and collect drift/uncounted issues.
        $intents = [];
        $issues  = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $expected  = (float) $line['expected'];

            if ($line['counted'] === null) {
                // Uncounted lines post NOTHING but are surfaced as issues that
                // require the combined confirm (§6.3).
                $issues[] = self::issueRow($line, 'uncounted');
                continue;
            }

            $counted = (float) $line['counted'];

            // Drift: the live balance moved away from the snapshot since creation.
            // The posted delta is unchanged (relative math is correct either way);
            // the row is informational for the operator's acknowledgment.
            $onHand = Balances::onHand($productId);
            if (abs($onHand - $expected) > 1e-9) {
                $issues[] = self::issueRow($line, 'drift', $onHand);
            }

            $delta = $counted - $expected;
            if (abs($delta) < 1e-9) {
                continue; // zero-delta lines apply cleanly with no movement
            }

            $intents[] = new MovementIntent(
                $productId,
                $delta,
                Reasons::COUNT,
                'count_line',
                (int) $line['id'],
                null,
                null,
                null,
                '',
                self::batchNote($countId, $count),
                null,
                'count_line:' . (int) $line['id'] . ':apply'
            );
        }

        // Drift and/or uncounted lines need an explicit acknowledgment first.
        if (!empty($issues) && !$confirmed) {
            return [
                'status'    => 'confirm_required',
                'issues'    => $issues,
                'movements' => 0,
                'message'   => \__('Stock moved for some lines since the count started, or some lines were never counted. Review and confirm to apply anyway.', 'kaupang-stock'),
            ];
        }

        // Post the batch. Zero-delta counts (all counted == expected) reach here
        // with an empty intent set and apply cleanly with 0 movements.
        $posted = 0;
        try {
            if (!empty($intents)) {
                $movements = Ledger::recordBatch($intents);
                $posted    = count($movements);
            }
        } catch (LedgerException $e) {
            Logger::error('count_apply_failed', ['count' => $countId, 'error' => $e->getMessage()]);
            return self::error(sprintf(
                /* translators: %s: error detail from the ledger. */
                \__('Could not apply the count: %s', 'kaupang-stock'),
                $e->getMessage()
            ));
        }

        self::markApplied($countId);

        return [
            'status'    => 'ok',
            'issues'    => $issues, // echo any acknowledged drift/uncounted rows
            'movements' => $posted,
            'message'   => sprintf(
                /* translators: %d: number of stock movements posted. */
                \_n('Count applied — %d movement posted.', 'Count applied — %d movements posted.', $posted, 'kaupang-stock'),
                $posted
            ),
        ];
    }

    /**
     * One shared batch-level note: "Varetelling #N" plus the optional operator
     * note captured on the count document.
     */
    private static function batchNote(int $countId, array $count): string {
        $note = sprintf(\__('Varetelling #%d', 'kaupang-stock'), $countId);
        $op   = trim((string) ($count['note'] ?? ''));
        if ($op !== '') {
            $note .= ' — ' . $op;
        }
        return mb_substr($note, 0, 255);
    }

    /** Stamp the count applied (terminal) with attribution. */
    private static function markApplied(int $countId): void {
        global $wpdb;
        $wpdb->update(
            Schema::counts(),
            [
                'status'     => Counts::STATUS_APPLIED,
                'applied_by' => \get_current_user_id(),
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $countId],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * A summary row for the combined dialog / blocked list.
     *
     * @param array<string,mixed> $line
     * @return array{line_id:int,product_id:int,label:string,expected:float,on_hand:float,counted:?float,kind:string}
     */
    private static function issueRow(array $line, string $kind, ?float $onHand = null): array {
        $productId = (int) $line['product_id'];
        $expected  = (float) $line['expected'];
        return [
            'line_id'    => (int) $line['id'],
            'product_id' => $productId,
            'label'      => ProductSearch::label($productId),
            'expected'   => $expected,
            'on_hand'    => $onHand ?? Balances::onHand($productId),
            'counted'    => $line['counted'] === null ? null : (float) $line['counted'],
            'kind'       => $kind, // drift | uncounted | recount
        ];
    }

    /** @return array{status:'ok',issues:array,movements:int,message:string} */
    private static function ok(int $movements, string $message): array {
        return ['status' => 'ok', 'issues' => [], 'movements' => $movements, 'message' => $message];
    }

    /** @return array{status:'error',issues:array,movements:int,message:string} */
    private static function error(string $message): array {
        return ['status' => 'error', 'issues' => [], 'movements' => 0, 'message' => $message];
    }
}
