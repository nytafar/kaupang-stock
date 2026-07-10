<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

/**
 * Count LINE write logic (§6.3 capture/review). Two ways a line's `counted`
 * changes:
 *
 *  - set (absolute): manual per-row qty entry — `counted` becomes exactly N.
 *  - increment (atomic): the scanner path — a repeated scan does an in-SQL
 *    `counted = COALESCE(counted,0) + N`. Lost updates are the inFlow anti-pattern,
 *    so the addition happens in the database, never read-modify-write in PHP.
 *
 * Both stamp counted_by/counted_at and recompute the recount flag against the
 * variance threshold. The override path (accepting a flagged value as-is) lives in
 * the REST controller because it writes a trail note, not `counted` — a fresh count
 * pass is the only thing that changes what was counted (who-counted-what integrity).
 *
 * These are the plugin's own document rows, so direct $wpdb is correct; no stock
 * movement is written here — Apply.php is the only bridge to the Ledger.
 */
final class CountLines {

    /**
     * Absolute set of `counted`. Returns the fresh line row, or null when the line
     * is missing / the parent count is not open|review.
     *
     * @return array<string,mixed>|null
     */
    public static function setCounted(int $lineId, float $counted): ?array {
        if (!self::writable($lineId)) {
            return null;
        }
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            Schema::countLines(),
            [
                'counted'    => number_format(max(0, $counted), 3, '.', ''),
                'counted_by' => \get_current_user_id(),
                'counted_at' => $now,
            ],
            ['id' => $lineId],
            ['%s', '%d', '%s'],
            ['%d']
        );
        return self::recomputeRecount($lineId);
    }

    /**
     * Atomic increment (scanner). The addition is done in SQL so two near-
     * simultaneous scans of the same code cannot lose an update. Returns the fresh
     * line row (post-increment), or null when not writable.
     *
     * @return array<string,mixed>|null
     */
    public static function increment(int $lineId, float $by): ?array {
        if (!self::writable($lineId)) {
            return null;
        }
        global $wpdb;
        $now   = gmdate('Y-m-d H:i:s');
        $table = Schema::countLines();
        $wpdb->query($wpdb->prepare(
            "UPDATE $table
                SET counted = COALESCE(counted, 0) + %f,
                    counted_by = %d,
                    counted_at = %s
              WHERE id = %d",
            $by,
            \get_current_user_id(),
            $now,
            $lineId
        ));
        return self::recomputeRecount($lineId);
    }

    /**
     * Clear the recount flag without touching `counted` (the override outcome —
     * the operator has accepted the counted value). Review status only.
     *
     * @return array<string,mixed>|null
     */
    public static function clearRecountFlag(int $lineId): ?array {
        $line = Counts::line($lineId);
        if ($line === null) {
            return null;
        }
        global $wpdb;
        $wpdb->update(
            Schema::countLines(),
            ['recount' => 0],
            ['id' => $lineId],
            ['%d'],
            ['%d']
        );
        return Counts::line($lineId);
    }

    /**
     * Recompute the recount flag for a line from its current counted vs expected:
     * |counted − expected| > (variance_threshold_pct/100 × max(expected, 1)) → 1.
     * An uncounted line (counted IS NULL) is never flagged. Returns the fresh row.
     *
     * @return array<string,mixed>|null
     */
    public static function recomputeRecount(int $lineId): ?array {
        $line = Counts::line($lineId);
        if ($line === null) {
            return null;
        }
        $flag = self::shouldRecount(
            $line['counted'] === null ? null : (float) $line['counted'],
            (float) $line['expected']
        ) ? 1 : 0;

        if ((int) $line['recount'] !== $flag) {
            global $wpdb;
            $wpdb->update(
                Schema::countLines(),
                ['recount' => $flag],
                ['id' => $lineId],
                ['%d'],
                ['%d']
            );
            $line['recount'] = $flag;
        }
        return $line;
    }

    /**
     * The variance rule (spec §6.3): relative to expected, with a floor of 1 so a
     * count from expected 0 is not a division-by-zero / always-flagged edge. An
     * uncounted line is never a recount candidate.
     */
    public static function shouldRecount(?float $counted, float $expected): bool {
        if ($counted === null) {
            return false;
        }
        $pct       = (float) Settings::get('variance_threshold_pct', 20);
        $tolerance = ($pct / 100) * max(abs($expected), 1.0);
        return abs($counted - $expected) > $tolerance + 1e-9;
    }

    /**
     * A line is writable (capture/manual save) only while its parent count is open
     * or in review — spec gates the increment/set ops to {open, review}.
     */
    private static function writable(int $lineId): bool {
        // Master flag: counting must be enabled at all.
        if (!Settings::get('counting_enabled')) {
            return false;
        }
        $line = Counts::line($lineId);
        if ($line === null) {
            return false;
        }
        $count = Counts::find((int) $line['count_id']);
        if ($count === null) {
            return false;
        }
        return in_array((string) $count['status'], [Counts::STATUS_OPEN, Counts::STATUS_REVIEW], true);
    }
}
