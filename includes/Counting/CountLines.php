<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Locations;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * Count LINE write logic (§6.3 capture/review). Three ways a line's `counted`
 * changes:
 *
 *  - set (absolute): manual per-row qty entry — `counted` becomes exactly N.
 *  - increment (atomic): the scanner path — a repeated scan does an in-SQL
 *    `counted = COALESCE(counted,0) + N`. Lost updates are the inFlow anti-pattern,
 *    so the addition happens in the database, never read-modify-write in PHP.
 *  - clear: back to uncounted (a mis-scan into the wrong location, or counted
 *    lines on a location deactivated since creation). Per line, or every
 *    counted line of one location of a count.
 *
 * Set and increment stamp counted_by/counted_at and recompute the recount flag
 * against the variance threshold; clear empties all three and the flag. In
 * review a clear is recorded on the count's note trail, and clearing a line
 * flagged for recount needs a note, as an override does. The
 * override path (accepting a flagged value as-is) writes a trail note, not
 * `counted` — a fresh count pass is the only thing that changes what was counted
 * (who-counted-what integrity).
 *
 * Every write runs in a short transaction that first takes a shared lock on the
 * parent count row and re-checks its status there. Apply claims the count with an
 * UPDATE of that row (exclusive lock) before it reads the lines, so a write either
 * commits before the claim and is posted, or sees `applying` and is refused —
 * never a 200 whose value the apply does not see.
 *
 * These are the plugin's own document rows, so direct $wpdb is correct; no stock
 * movement is written here — Apply.php is the only bridge to the Ledger.
 */
final class CountLines {

    /** Statuses in which a count's lines may be counted or cleared. */
    private const WRITABLE = [Counts::STATUS_OPEN, Counts::STATUS_REVIEW];

    /**
     * Absolute set of `counted`. Returns the fresh line row, or null when the line
     * is missing / the parent count is not open|review.
     *
     * @return array<string,mixed>|null
     */
    public static function setCounted(int $lineId, float $counted): ?array {
        return self::guarded($lineId, self::WRITABLE, static function () use ($lineId, $counted): bool {
            global $wpdb;
            $ok = $wpdb->update(
                Schema::countLines(),
                [
                    'counted'    => number_format(max(0, $counted), 3, '.', ''),
                    'counted_by' => \get_current_user_id(),
                    'counted_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $lineId],
                ['%s', '%d', '%s'],
                ['%d']
            );
            return $ok !== false && self::recomputeRecount($lineId) !== null;
        });
    }

    /**
     * Atomic increment (scanner). The addition is done in SQL so two near-
     * simultaneous scans of the same code cannot lose an update. Returns the fresh
     * line row (post-increment), or null when not writable.
     *
     * @return array<string,mixed>|null
     */
    public static function increment(int $lineId, float $by): ?array {
        return self::guarded($lineId, self::WRITABLE, static function () use ($lineId, $by): bool {
            global $wpdb;
            $table = Schema::countLines();
            $ok    = $wpdb->query($wpdb->prepare(
                "UPDATE $table
                    SET counted = COALESCE(counted, 0) + %f,
                        counted_by = %d,
                        counted_at = %s
                  WHERE id = %d",
                $by,
                \get_current_user_id(),
                gmdate('Y-m-d H:i:s'),
                $lineId
            ));
            return $ok !== false && self::recomputeRecount($lineId) !== null;
        });
    }

    /**
     * Set a line back to uncounted: counted, counted_by, counted_at and the
     * recount flag cleared. Open or review only. Returns the fresh row, or null
     * when not writable.
     *
     * In review the clear is part of the record, like an override: it appends a
     * trail entry to the count note (who, which line, what it held, the note),
     * and a line flagged for recount needs a note, as accepting it would. Both
     * are decided under the count lock, on the status the write commits in.
     *
     * @return array<string,mixed>|null
     * @throws NoteRequiredException in review, for a flagged line with a blank note (nothing written)
     */
    public static function clear(int $lineId, string $note = ''): ?array {
        $note  = trim($note);
        $trail = null;
        $row   = self::guarded($lineId, self::WRITABLE, static function (string $status) use ($lineId, $note, &$trail): bool {
            global $wpdb;
            $table = Schema::countLines();
            if ($status === Counts::STATUS_REVIEW) {
                $line = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d FOR UPDATE", $lineId), ARRAY_A);
                if (is_array($line) && $line['counted'] !== null) {
                    if ((int) $line['recount'] === 1 && $note === '') {
                        throw new NoteRequiredException(\__('This line is flagged for recount. A note is required to mark it uncounted.', 'kaupang-stock'));
                    }
                    $trail = $line;
                }
            }
            return $wpdb->query($wpdb->prepare(
                "UPDATE $table SET counted = NULL, counted_by = NULL, counted_at = NULL, recount = 0 WHERE id = %d",
                $lineId
            )) !== false;
        });
        if ($row !== null && $trail !== null) {
            Counts::appendNote((int) $trail['count_id'], sprintf(
                /* translators: 1: line id, 2: product label (with location), 3: user display name, 4: the counted quantity that was cleared. */
                \__('[uncounted] line #%1$d, %2$s, %3$s: was %4$s', 'kaupang-stock'),
                $lineId,
                self::trailLabel($trail),
                self::trailWho(),
                self::qty((float) $trail['counted'])
            ) . ($note !== '' ? ' — ' . $note : ''));
        }
        return $row;
    }

    /**
     * Clear every counted line of one location of a count (a whole location
     * scanned into the wrong column, or a location deactivated since creation).
     * Returns how many lines were cleared, or null when the count is not open|
     * review. In review it leaves one trail entry, and needs a note when one of
     * the lines is flagged for recount (see clear()).
     *
     * @throws NoteRequiredException in review, when a flagged line is among them and the note is blank
     */
    public static function clearLocation(int $countId, int $locationId, string $note = ''): ?int {
        if (!Settings::get('counting_enabled')) {
            return null;
        }
        $note    = trim($note);
        $cleared = null;
        $inReview = false;
        $ok = self::withCountLock($countId, self::WRITABLE, static function (string $status) use ($countId, $locationId, $note, &$cleared, &$inReview): bool {
            global $wpdb;
            $table = Schema::countLines();
            if ($status === Counts::STATUS_REVIEW) {
                $inReview = true;
                $flagged  = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE count_id = %d AND location_id = %d AND counted IS NOT NULL AND recount = 1 FOR UPDATE",
                    $countId,
                    $locationId
                ));
                if ($flagged > 0 && $note === '') {
                    throw new NoteRequiredException(\__('Some of these lines are flagged for recount. A note is required to mark them uncounted.', 'kaupang-stock'));
                }
            }
            $n = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET counted = NULL, counted_by = NULL, counted_at = NULL, recount = 0
                  WHERE count_id = %d AND location_id = %d AND counted IS NOT NULL",
                $countId,
                $locationId
            ));
            $cleared = $n === false ? null : (int) $n;
            return $n !== false;
        });
        if ($ok && $inReview && $cleared > 0) {
            Counts::appendNote($countId, sprintf(
                /* translators: 1: location name, 2: number of lines, 3: user display name. */
                \_n('[uncounted] %1$s: %2$d line, %3$s', '[uncounted] %1$s: %2$d lines, %3$s', (int) $cleared, 'kaupang-stock'),
                Locations::name($locationId),
                (int) $cleared,
                self::trailWho()
            ) . ($note !== '' ? ' — ' . $note : ''));
        }
        return $ok ? $cleared : null;
    }

    /**
     * The product label a trail entry names a line by; in a multi-location count
     * the line is one location of the product, so the location is named too.
     *
     * @param array<string,mixed> $line
     */
    public static function trailLabel(array $line): string {
        $label = ProductSearch::label((int) $line['product_id']);
        if (Counts::isMulti((int) $line['count_id'])) {
            $label .= ' @ ' . Locations::name(Counts::lineLocation($line));
        }
        return $label;
    }

    /** The current user as a trail entry names them. */
    public static function trailWho(): string {
        $user = \wp_get_current_user();
        return $user instanceof \WP_User && $user->display_name !== '' ? $user->display_name : ('#' . \get_current_user_id());
    }

    private static function qty(float $n): string {
        return abs($n - round($n)) < 1e-9 ? (string) (int) round($n) : rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    /**
     * Clear the recount flag without touching `counted` (the override outcome —
     * the operator has accepted the counted value). Review status only.
     *
     * @return array<string,mixed>|null
     */
    public static function clearRecountFlag(int $lineId): ?array {
        return self::guarded($lineId, [Counts::STATUS_REVIEW], static function () use ($lineId): bool {
            global $wpdb;
            return $wpdb->update(
                Schema::countLines(),
                ['recount' => 0],
                ['id' => $lineId],
                ['%d'],
                ['%d']
            ) !== false;
        });
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
     * Run one line write under the count lock. Counting must be enabled at all
     * (master flag), the line must exist, and its count must be in $statuses at
     * the moment the write commits. Returns the fresh row, or null.
     *
     * @param string[]        $statuses
     * @param callable(string):bool $write gets the status the write commits in
     * @return array<string,mixed>|null
     */
    private static function guarded(int $lineId, array $statuses, callable $write): ?array {
        if (!Settings::get('counting_enabled')) {
            return null;
        }
        $line = Counts::line($lineId);
        if ($line === null) {
            return null;
        }
        if (!self::withCountLock((int) $line['count_id'], $statuses, $write)) {
            return null;
        }
        return Counts::line($lineId);
    }

    /**
     * START TRANSACTION, read the count's status with a shared lock (LOCK IN
     * SHARE MODE: MariaDB and MySQL both accept it), run $write only when the
     * status is allowed, COMMIT; anything else rolls back. The shared lock makes
     * Apply's claim (an UPDATE of the same row) wait for writes in flight, and a
     * write that starts after the claim reads `applying`.
     *
     * @param string[]        $statuses
     * @param callable(string):bool $write gets the status read under the lock; may throw (rolled back, rethrown)
     */
    private static function withCountLock(int $countId, array $statuses, callable $write): bool {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $status = $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . Schema::counts() . ' WHERE id = %d LOCK IN SHARE MODE',
                $countId
            ));
            if ($status === null || !in_array((string) $status, $statuses, true) || !$write((string) $status)) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $wpdb->query('COMMIT');
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
