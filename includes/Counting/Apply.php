<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
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
 * Per location: a line is (product, location), so each intent carries its line's
 * location_id and the drift check reads that location's on-hand. The whole count
 * is still ONE batch — apply is the count, never one location of it. A location
 * with no counted line posts nothing; in a multi-location count it surfaces as ONE
 * "not counted" issue instead of one row per line. Counted lines on a location
 * deactivated since creation refuse the apply up front (the Ledger would refuse
 * the batch anyway).
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

        $lines     = Counts::lines($countId);
        $locations = Counts::locations($countId);
        $multi     = count($locations) > 1;

        // Counted lines on a location deactivated since the count was created
        // cannot post (Ledger::validate refuses an inactive location) — say so
        // plainly before the ledger is reached.
        $inactive = [];
        foreach ($lines as $line) {
            $loc = Counts::lineLocation($line);
            if ($line['counted'] !== null && !Locations::isActive($loc)) {
                $inactive[$loc] = ($inactive[$loc] ?? 0) + 1;
            }
        }
        if ($inactive !== []) {
            $names = [];
            foreach ($inactive as $loc => $n) {
                $names[] = sprintf('%s (%d)', $locations[$loc] ?? Locations::name($loc), $n);
            }
            return self::error(sprintf(
                /* translators: %s: comma-separated "location (number of counted lines)" list. */
                \__('Counted lines are on a location that has been deactivated since the count was created: %s. Reactivate the location to apply, or cancel the count.', 'kaupang-stock'),
                implode(', ', $names)
            ));
        }

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
        $summary = [];
        foreach ($locations as $loc => $name) {
            $summary[$loc] = ['location_id' => $loc, 'location' => $name, 'lines' => 0, 'counted' => 0, 'movements' => 0];
        }
        $uncountedBy = [];
        foreach ($lines as $line) {
            $productId  = (int) $line['product_id'];
            $locationId = Counts::lineLocation($line);
            $expected   = (float) $line['expected'];
            $summary[$locationId] ??= ['location_id' => $locationId, 'location' => Locations::name($locationId), 'lines' => 0, 'counted' => 0, 'movements' => 0];
            $summary[$locationId]['lines']++;

            if ($line['counted'] === null) {
                // Uncounted lines post NOTHING but are surfaced as issues that
                // require the combined confirm (§6.3). One-location counts list
                // them inline as before; multi-location ones decide per location
                // once every line has been seen.
                if ($multi) {
                    $uncountedBy[$locationId][] = $line;
                } else {
                    $issues[] = self::issueRow($line, 'uncounted');
                }
                continue;
            }
            $summary[$locationId]['counted']++;

            $counted = (float) $line['counted'];

            // Drift: the live balance AT THIS LOCATION moved away from the
            // snapshot since creation. The posted delta is unchanged (relative
            // math is correct either way); the row is informational.
            $onHand = Balances::onHand($productId, $locationId);
            if (abs($onHand - $expected) > 1e-9) {
                $issues[] = self::issueRow($line, 'drift', $onHand);
            }

            $delta = $counted - $expected;
            if (abs($delta) < 1e-9) {
                continue; // zero-delta lines apply cleanly with no movement
            }
            $summary[$locationId]['movements']++;

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
                'count_line:' . (int) $line['id'] . ':apply',
                $locationId
            );
        }

        // Multi-location uncounted lines: per line, except a location where
        // nothing at all was counted — that is one row ("not counted — no
        // change"), not a list of every line there.
        foreach ($uncountedBy as $locationId => $uncounted) {
            if ($summary[$locationId]['counted'] === 0) {
                $issues[] = [
                    'line_id'     => 0,
                    'product_id'  => 0,
                    'location_id' => $locationId,
                    'location'    => $summary[$locationId]['location'],
                    'label'       => $summary[$locationId]['location'],
                    'expected'    => 0.0,
                    'on_hand'     => 0.0,
                    'counted'     => null,
                    'lines'       => count($uncounted),
                    'kind'        => 'location_uncounted',
                ];
                continue;
            }
            foreach ($uncounted as $line) {
                $issues[] = self::issueRow($line, 'uncounted');
            }
        }
        $summary = array_values($summary);

        // Drift and/or uncounted lines need an explicit acknowledgment first.
        if (!empty($issues) && !$confirmed) {
            return [
                'status'    => 'confirm_required',
                'issues'    => $issues,
                'movements' => 0,
                'message'   => \__('Stock moved for some lines since the count started, or some lines were never counted. Review and confirm to apply anyway.', 'kaupang-stock'),
                'multi'     => $multi,
                'locations' => $summary,
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
            'multi'     => $multi,
            'locations' => $summary,
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
     * @return array{line_id:int,product_id:int,location_id:int,location:string,label:string,expected:float,on_hand:float,counted:?float,kind:string}
     */
    private static function issueRow(array $line, string $kind, ?float $onHand = null): array {
        $productId  = (int) $line['product_id'];
        $locationId = Counts::lineLocation($line);
        $expected   = (float) $line['expected'];
        return [
            'line_id'     => (int) $line['id'],
            'product_id'  => $productId,
            'location_id' => $locationId,
            'location'    => Locations::name($locationId),
            'label'       => ProductSearch::label($productId),
            'expected'    => $expected,
            'on_hand'     => $onHand ?? Balances::onHand($productId, $locationId),
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
