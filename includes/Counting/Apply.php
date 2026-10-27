<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\Movement;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Ledger\WriteThrough;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * The drift-guarded relative-variance apply (§6.3 "Apply"). ONLY for status=review
 * (or a stale `applying` claim, see below).
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
 * Claim: line writes stay allowed in review (a second device may still be
 * scanning), so before the lines that are posted are read, the count is flipped
 * review → `applying` in one conditional UPDATE. Every line write
 * (CountLines) holds a shared lock on the count row and re-checks its status, so
 * a write either committed before the claim (and is in the lines read after it)
 * or sees `applying` and is refused (REST 409). Nothing lands in between. The
 * claim is released back to review when the apply stops short of the ledger
 * (confirm needed, recount, deactivated location) or the ledger refuses; it
 * becomes `applied` once the batch is posted. A claim left behind by a request
 * that died is stale after CLAIM_TTL and the next apply takes it over. Any
 * throwable after the claim is caught: with nothing posted the claim goes back
 * to review; with the batch posted (a write-through or listener threw after the
 * commit) the count stays `applying` with its claim open for an immediate retry.
 * A batch that is already posted, after a death or a throwable, is detected by
 * its movements and only finished: pending write-throughs to WooCommerce are
 * completed (WriteThrough::completeIfPending) before the count is marked
 * applied, so `_stock` never lags the ledger behind an `applied` count (the next
 * checkout's absorber would otherwise book the gap as `external` and erase the
 * correction).
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

    /** Seconds after which an `applying` claim is treated as abandoned. */
    public const CLAIM_TTL = 600;

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
        if ($status !== Counts::STATUS_REVIEW && $status !== Counts::STATUS_APPLYING) {
            return self::error(\__('Only a count in review can be applied.', 'kaupang-stock'));
        }

        // A batch that is already in the ledger (an apply that posted and then
        // died or threw) is only finished: no first look, whose drift check
        // would flag every line the batch itself moved.
        $wasPosted = self::postedMovements($countId) !== [];

        // A first look without the claim: the confirm dialog and every refusal
        // come from here, and counting on other devices carries on meanwhile.
        // A stale `applying` count is claimed first and then planned, so a
        // refusal hands it back to review, where its advice can be followed.
        if ($status === Counts::STATUS_REVIEW && !$wasPosted) {
            $plan = self::plan($countId, $count, $confirmed);
            if (isset($plan['result'])) {
                return $plan['result'];
            }
        }

        $stamp = self::claim($countId);
        if ($stamp === null) {
            return self::notClaimed($countId);
        }

        // From here on every throwable is caught: the count must never be left
        // in a state no later apply can recover from.
        try {
            // Under the claim no line can change. A batch an earlier, interrupted
            // apply already posted is finished, never posted twice.
            $existing = self::postedMovements($countId);
            if ($existing !== []) {
                return self::finish($countId, $stamp, $existing);
            }

            // Re-read: what is posted is what the lines hold now, scans that
            // landed since the first look included.
            $plan = self::plan($countId, (array) Counts::find($countId), $confirmed);
            if (isset($plan['result'])) {
                self::release($countId, $stamp);
                return $plan['result'];
            }

            // Post the batch. Zero-delta counts (all counted == expected) reach
            // here with an empty intent set and apply cleanly with 0 movements.
            // recordBatch() commits the batch, then writes it through to Woo;
            // a throwable from the write-through comes after the commit.
            $posted = 0;
            if (!empty($plan['intents'])) {
                $posted = count(Ledger::recordBatch($plan['intents']));
            }
        } catch (\Throwable $e) {
            return self::failed($countId, $stamp, $e);
        }

        self::markApplied($countId, $stamp);

        return [
            'status'    => 'ok',
            'issues'    => $plan['issues'], // echo any acknowledged drift/uncounted rows
            'multi'     => $plan['multi'],
            'locations' => $plan['summary'],
            'movements' => $posted,
            'message'   => sprintf(
                /* translators: %d: number of stock movements posted. */
                \_n('Count applied — %d movement posted.', 'Count applied — %d movements posted.', $posted, 'kaupang-stock'),
                $posted
            ),
        ];
    }

    /**
     * Finish a batch an interrupted apply posted: complete every write-through
     * to WooCommerce it may have left pending (the §3 retry guarantee,
     * WriteThrough::completeIfPending), then mark the count applied. Nothing is
     * posted again. Called under our claim.
     *
     * @param Movement[] $movements
     * @return array{status:'ok',issues:array,movements:int,finished:int,message:string}
     */
    private static function finish(int $countId, string $stamp, array $movements): array {
        if (Settings::activeMode()) {
            foreach ($movements as $movement) {
                if (Reasons::isOwned($movement->reason)) {
                    WriteThrough::completeIfPending($movement);
                }
            }
        }
        self::markApplied($countId, $stamp);
        $n = count($movements);
        Logger::notice('count_apply_finished', ['count' => $countId, 'movements' => $n]);
        return [
            'status'    => 'ok',
            'issues'    => [],
            'movements' => 0,
            'finished'  => $n,
            'message'   => sprintf(
                /* translators: %d: number of stock movements an earlier, interrupted apply had posted. */
                \_n(
                    'Count applied. An earlier apply had already posted its %d movement and was interrupted; it is now finished and WooCommerce stock is brought up to date. Nothing was posted twice.',
                    'Count applied. An earlier apply had already posted its %d movements and was interrupted; it is now finished and WooCommerce stock is brought up to date. Nothing was posted twice.',
                    $n,
                    'kaupang-stock'
                ),
                $n
            ),
        ];
    }

    /**
     * A throwable after the claim. Nothing posted: the claim goes back to
     * review (counting may resume, apply may be retried). The batch posted
     * (the throwable came from the write-through or a movement listener):
     * the count stays `applying`, since its lines are what was posted, but the
     * claim is opened for an immediate retry, which only finishes it.
     *
     * @return array{status:'error',issues:array,movements:int,message:string}
     */
    private static function failed(int $countId, string $stamp, \Throwable $e): array {
        global $wpdb;
        $posted = false;
        try {
            $posted = self::postedMovements($countId) !== [];
        } catch (\Throwable $ignored) {
            // Cannot tell: keep the claim; it goes stale after CLAIM_TTL.
            Logger::error('count_apply_failed', ['count' => $countId, 'error' => $e->getMessage(), 'posted' => 'unknown']);
            return self::error(self::retryLaterMessage());
        }
        Logger::error('count_apply_failed', ['count' => $countId, 'error' => $e->getMessage(), 'posted' => $posted]);
        if (!$posted) {
            self::release($countId, $stamp);
            return self::error(sprintf(
                /* translators: %s: error detail. */
                \__('Could not apply the count: %s', 'kaupang-stock'),
                $e->getMessage()
            ));
        }
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::counts() . ' SET applied_at = NULL WHERE id = %d AND status = %s AND applied_at = %s',
            $countId,
            Counts::STATUS_APPLYING,
            $stamp
        ));
        return self::error(sprintf(
            /* translators: %s: error detail. */
            \__('The stock movements were posted, but bringing WooCommerce stock up to date failed: %s. Use Bruk telling again to finish; nothing will be posted twice.', 'kaupang-stock'),
            $e->getMessage()
        ));
    }

    /** Why the claim was not taken, with when a retry is possible. */
    private static function notClaimed(int $countId): array {
        $now    = Counts::find($countId);
        $status = $now === null ? '' : (string) $now['status'];
        if ($status === Counts::STATUS_APPLIED) {
            return self::ok(0, \__('Count already applied.', 'kaupang-stock'));
        }
        if ($status === Counts::STATUS_APPLYING) {
            $from = self::retryFrom($now);
            return self::error($from === null
                ? self::retryLaterMessage()
                : sprintf(
                    /* translators: %s: local date and time from which an interrupted apply can be retried. */
                    \__('This count is being applied right now. If that apply was interrupted, Bruk telling can finish it from %s.', 'kaupang-stock'),
                    $from
                ));
        }
        return self::error(\__('This count was reopened or cancelled meanwhile. Reload the page to see where it stands.', 'kaupang-stock'));
    }

    private static function retryLaterMessage(): string {
        return sprintf(
            /* translators: %d: minutes. */
            \__('This count is being applied. If that apply was interrupted, Bruk telling can finish it %d minutes after it started.', 'kaupang-stock'),
            (int) ceil(self::CLAIM_TTL / 60)
        );
    }

    /**
     * For an `applying` count: the local time from which the next apply may
     * take the claim over, or '' when that is possible now (an apply that
     * threw after posting opens its claim at once). Null when unknown.
     *
     * @param array<string,mixed>|null $count
     */
    public static function retryFrom(?array $count): ?string {
        if ($count === null || (string) $count['status'] !== Counts::STATUS_APPLYING) {
            return null;
        }
        $at = (string) ($count['applied_at'] ?? '');
        if ($at === '') {
            return '';
        }
        $from = strtotime($at . ' UTC');
        if ($from === false) {
            return null;
        }
        $from += self::CLAIM_TTL;
        return $from <= time() ? '' : \get_date_from_gmt(gmdate('Y-m-d H:i:s', $from), 'H:i');
    }

    /**
     * Read the lines and decide: either a `result` to return as is (a refusal or
     * confirm_required), or the intents to post with the issues and the
     * per-location summary. Reads only; the caller holds or takes the claim.
     *
     * @param array<string,mixed> $count
     * @return array{result?:array,intents?:MovementIntent[],issues?:array,summary?:array,multi?:bool}
     */
    private static function plan(int $countId, array $count, bool $confirmed): array {
        $lines     = Counts::lines($countId);
        $locations = Counts::locations($countId);
        $multi     = count($locations) > 1;

        // Every location row once per apply, not one query per line or issue.
        $known = [];
        foreach (Locations::all() as $loc) {
            $known[(int) $loc['id']] = $loc;
        }
        $nameOf = static fn (int $id): string => $locations[$id] ?? (isset($known[$id]) ? (string) $known[$id]['name'] : '#' . $id);

        // Counted lines on a location deactivated since the count was created
        // cannot post (Ledger::validate refuses an inactive location) — say so
        // plainly before the ledger is reached.
        $inactive = [];
        foreach ($lines as $line) {
            $loc = Counts::lineLocation($line);
            if ($line['counted'] !== null && (int) ($known[$loc]['active'] ?? 0) !== 1) {
                $inactive[$loc] = ($inactive[$loc] ?? 0) + 1;
            }
        }
        if ($inactive !== []) {
            $names = [];
            foreach ($inactive as $loc => $n) {
                $names[] = sprintf('%s (%d)', $nameOf($loc), $n);
            }
            return ['result' => self::error(sprintf(
                /* translators: %s: comma-separated "location (number of counted lines)" list. */
                \__('Counted lines are on a location that has been deactivated since the count was created: %s. Mark those lines uncounted, or reactivate the location, to apply.', 'kaupang-stock'),
                implode(', ', $names)
            ))];
        }

        // Recount-flagged lines block apply until re-counted or overridden (§6.3).
        $blocked = [];
        foreach ($lines as $line) {
            if ((int) $line['recount'] === 1) {
                $blocked[] = self::issueRow($line, 'recount', $nameOf);
            }
        }
        if (!empty($blocked)) {
            return ['result' => [
                'status'    => 'error',
                'issues'    => $blocked,
                'movements' => 0,
                'message'   => \__('Some lines are flagged for recount. Re-count or override them before applying.', 'kaupang-stock'),
            ]];
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
            $summary[$locationId] ??= ['location_id' => $locationId, 'location' => $nameOf($locationId), 'lines' => 0, 'counted' => 0, 'movements' => 0];
            $summary[$locationId]['lines']++;

            if ($line['counted'] === null) {
                // Uncounted lines post NOTHING but are surfaced as issues that
                // require the combined confirm (§6.3). One-location counts list
                // them inline as before; multi-location ones decide per location
                // once every line has been seen.
                if ($multi) {
                    $uncountedBy[$locationId][] = $line;
                } else {
                    $issues[] = self::issueRow($line, 'uncounted', $nameOf);
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
                $issues[] = self::issueRow($line, 'drift', $nameOf, $onHand);
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
                $issues[] = self::issueRow($line, 'uncounted', $nameOf);
            }
        }
        $summary = array_values($summary);

        // Drift and/or uncounted lines need an explicit acknowledgment first.
        if (!empty($issues) && !$confirmed) {
            return ['result' => [
                'status'    => 'confirm_required',
                'issues'    => $issues,
                'movements' => 0,
                'message'   => \__('Stock moved for some lines since the count started, or some lines were never counted. Review and confirm to apply anyway.', 'kaupang-stock'),
                'multi'     => $multi,
                'locations' => $summary,
            ]];
        }

        return ['intents' => $intents, 'issues' => $issues, 'summary' => $summary, 'multi' => $multi];
    }

    /**
     * Take the count for this apply: review → applying, or a stale applying
     * claim taken over. One conditional UPDATE, so of two racing applies only
     * one wins. The claim is the (applied_by, applied_at) stamp — those columns
     * are only meaningful once the count is applied, and markApplied() rewrites
     * them. Returns the stamp, or null when the count could not be claimed.
     */
    private static function claim(int $countId): ?string {
        global $wpdb;
        $stamp = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - self::CLAIM_TTL);
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::counts() . '
                SET status = %s, applied_by = %d, applied_at = %s
              WHERE id = %d
                AND (status = %s OR (status = %s AND (applied_at IS NULL OR applied_at < %s)))',
            Counts::STATUS_APPLYING,
            \get_current_user_id(),
            $stamp,
            $countId,
            Counts::STATUS_REVIEW,
            Counts::STATUS_APPLYING,
            $stale
        ));
        return $n === 1 ? $stamp : null;
    }

    /** Give the claim back (review again, counting may resume). Only our own claim. */
    private static function release(int $countId, string $stamp): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::counts() . '
                SET status = %s, applied_by = NULL, applied_at = NULL
              WHERE id = %d AND status = %s AND applied_by = %d AND applied_at = %s',
            Counts::STATUS_REVIEW,
            $countId,
            Counts::STATUS_APPLYING,
            \get_current_user_id(),
            $stamp
        ));
    }

    /**
     * The movements this count's batch already put in the ledger (an apply
     * that posted and died or threw before markApplied). The batch is one
     * transaction, so one movement means all of them.
     *
     * @return Movement[]
     */
    private static function postedMovements(int $countId): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT m.id FROM ' . Schema::movements() . ' m
               JOIN ' . Schema::countLines() . " l ON l.id = m.ref_id
              WHERE m.ref_type = 'count_line' AND l.count_id = %d
              ORDER BY m.id",
            $countId
        ));
        $out = [];
        foreach ((array) $ids as $id) {
            $movement = Ledger::find((int) $id);
            if ($movement !== null) {
                $out[] = $movement;
            }
        }
        return $out;
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

    /** Stamp the count applied (terminal) with attribution — from our own claim only. */
    private static function markApplied(int $countId, string $stamp): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::counts() . '
                SET status = %s, applied_by = %d, applied_at = %s
              WHERE id = %d AND status = %s AND applied_at = %s',
            Counts::STATUS_APPLIED,
            \get_current_user_id(),
            gmdate('Y-m-d H:i:s'),
            $countId,
            Counts::STATUS_APPLYING,
            $stamp
        ));
    }

    /**
     * A summary row for the combined dialog / blocked list.
     *
     * @param array<string,mixed>   $line
     * @param callable(int):string  $nameOf location id → name, resolved once per apply
     * @return array{line_id:int,product_id:int,location_id:int,location:string,label:string,expected:float,on_hand:float,counted:?float,kind:string}
     */
    private static function issueRow(array $line, string $kind, callable $nameOf, ?float $onHand = null): array {
        $productId  = (int) $line['product_id'];
        $locationId = Counts::lineLocation($line);
        $expected   = (float) $line['expected'];
        return [
            'line_id'     => (int) $line['id'],
            'product_id'  => $productId,
            'location_id' => $locationId,
            'location'    => $nameOf($locationId),
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
