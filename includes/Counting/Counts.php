<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Observe\Seeder;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Support\ProductSearch;

/**
 * Count DOCUMENT CRUD + lifecycle (§1.3, §6.3). A count is a two-phase snapshot:
 * capture (`open`) → review → applied|cancelled, with review → open allowed for
 * more counting. Its rows live in the plugin's own {prefix}kaupang_stock_counts /
 * _count_lines tables, so direct $wpdb here is correct — they are our documents.
 * Stock MOVEMENTS are never touched here; the apply step (Apply.php) is the only
 * bridge from a count to the Ledger.
 *
 * A line is (product, location): creation writes one line per stock-managed
 * product × active location, each snapshotting `expected = Balances::onHand($pid,
 * $loc)` AT CREATION — the count-time truth the relative-variance apply keys on (a
 * sale after the shelf was counted survives). Which locations a count covers is
 * its lines; a location created later is not in an older count. A count with one
 * location (every single-location store, and every count migrated from schema v6)
 * looks and behaves exactly as before. Blind by default: the review UI hides
 * `expected` until the operator flips "Vis forventet".
 */
final class Counts {

    public const STATUS_OPEN      = 'open';
    public const STATUS_REVIEW    = 'review';
    /** Transient: an apply has claimed the count and is posting it (Apply::claim()). No line write succeeds. */
    public const STATUS_APPLYING  = 'applying';
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_CANCELLED = 'cancelled';

    public const SCOPE_ALL      = 'all';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_MANUAL   = 'manual';

    /* ------------------------------ Reads --------------------------------- */

    /** @return array<string,mixed>|null one count document row */
    public static function find(int $countId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::counts() . ' WHERE id = %d',
            $countId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * All counts, newest first (the list screen).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(int $limit = 200): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::counts() . ' ORDER BY id DESC LIMIT %d',
            max(1, min(1000, $limit))
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * The count's lines with product labels resolved (list/capture/review grids).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function lines(int $countId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::countLines() . ' WHERE count_id = %d ORDER BY id ASC',
            $countId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * The locations a count covers, in Locations::all() order (default first),
     * inactive ones included — a location deactivated since creation keeps its
     * lines. Read from the lines; a count with no lines covers the default.
     *
     * @return array<int,string> location id => name
     */
    public static function locations(int $countId): array {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT location_id FROM ' . Schema::countLines() . ' WHERE count_id = %d',
            $countId
        )));
        // Defensive: v7 backfills every line to a location, so no stored row
        // reads 0 (before the migration the column does not exist at all).
        $ids = array_values(array_unique(array_map(static fn (int $id): int => $id > 0 ? $id : Balances::defaultLocationId(), $ids)));
        if ($ids === []) {
            $ids = [Balances::defaultLocationId()];
        }
        $out = [];
        foreach (Locations::all() as $loc) {
            if (in_array((int) $loc['id'], $ids, true)) {
                $out[(int) $loc['id']] = (string) $loc['name'];
            }
        }
        foreach ($ids as $id) {
            $out[$id] ??= Locations::name($id);
        }
        return $out;
    }

    /** True when the count spans more than one location (the per-location UI). */
    public static function isMulti(int $countId): bool {
        return count(self::locations($countId)) > 1;
    }

    /** A line's location id; a missing or 0 location_id resolves to the default. */
    public static function lineLocation(array $line): int {
        $id = (int) ($line['location_id'] ?? 0);
        return $id > 0 ? $id : Balances::defaultLocationId();
    }

    /** @return array<string,mixed>|null one line row */
    public static function line(int $lineId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::countLines() . ' WHERE id = %d',
            $lineId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Progress counters for the list/review header: total lines and how many have
     * been counted / flagged for recount.
     *
     * @return array{lines:int,counted:int,recount:int}
     */
    public static function progress(int $countId): array {
        global $wpdb;
        // `lines` is a MySQL/MariaDB reserved word — backtick the alias or the
        // whole query fails as a syntax error, get_row() returns null, and every
        // caller reads progress as 0 (the spurious empty_scope warning of 2026-07).
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS `lines`,
                    SUM(CASE WHEN counted IS NOT NULL THEN 1 ELSE 0 END) AS counted,
                    SUM(CASE WHEN recount = 1 THEN 1 ELSE 0 END) AS recount
             FROM ' . Schema::countLines() . ' WHERE count_id = %d',
            $countId
        ), ARRAY_A);
        return [
            'lines'   => (int) ($row['lines'] ?? 0),
            'counted' => (int) ($row['counted'] ?? 0),
            'recount' => (int) ($row['recount'] ?? 0),
        ];
    }

    /* ------------------------------ Create -------------------------------- */

    /**
     * Create a count and snapshot its lines. Product set = stock-managed ids
     * (Seeder::stockManagedProductIds()), narrowed by scope; one line per product
     * × active location. `expected` per line is that location's live on-hand at
     * creation (0 where the product has never been). Returns the new count id.
     *
     * `locations` (CLI/tests only, no UI) narrows to a subset of the active
     * locations; unknown or inactive ids are ignored, and an empty result falls
     * back to every active location.
     *
     * All or nothing: the document and its lines are written in one transaction,
     * and any failed insert rolls the whole count back and throws. A count that
     * silently lost its second location's lines would look complete and apply
     * as a one-location count.
     *
     * @param array{scope?:string,category?:int,products?:int[],blind?:bool,note?:string,locations?:int[]} $args
     * @throws \RuntimeException when the count or one of its lines cannot be written
     */
    public static function create(array $args): int {
        global $wpdb;

        $scope = isset($args['scope']) ? (string) $args['scope'] : self::SCOPE_ALL;
        if (!in_array($scope, [self::SCOPE_ALL, self::SCOPE_CATEGORY, self::SCOPE_MANUAL], true)) {
            $scope = self::SCOPE_ALL;
        }
        $blind = array_key_exists('blind', $args) ? (bool) $args['blind'] : true;
        $note  = isset($args['note']) ? mb_substr(trim((string) $args['note']), 0, 255) : '';

        $productIds  = self::resolveProductIds($scope, $args);
        $scopeLabel  = self::scopeLabel($scope, $args, count($productIds));
        $locationIds = self::createLocations($args);
        // One read for every (product, location) balance instead of N×M.
        $balances = $productIds !== [] ? Balances::rows($productIds) : [];

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        try {
            $ok = $wpdb->insert(Schema::counts(), [
                'status'     => self::STATUS_OPEN,
                'blind'      => $blind ? 1 : 0,
                'scope'      => $scopeLabel,
                'note'       => $note !== '' ? $note : null,
                'created_by' => \get_current_user_id(),
                'created_at' => $now,
            ], ['%s', '%d', '%s', '%s', '%d', '%s']);
            $countId = (int) $wpdb->insert_id;
            if ($ok === false || $countId <= 0) {
                throw new \RuntimeException('Count insert failed: ' . $wpdb->last_error);
            }

            foreach ($productIds as $productId) {
                foreach ($locationIds as $locationId) {
                    $expected = (float) ($balances[$productId][$locationId]['on_hand'] ?? 0);
                    $ok = $wpdb->insert(Schema::countLines(), [
                        'count_id'    => $countId,
                        'product_id'  => $productId,
                        'location_id' => $locationId,
                        'expected'    => number_format($expected, 3, '.', ''),
                        'recount'     => 0,
                    ], ['%d', '%d', '%d', '%s', '%d']);
                    if ($ok !== 1) {
                        throw new \RuntimeException(sprintf(
                            'Count line insert failed (product %d, location %d): %s',
                            $productId,
                            $locationId,
                            $wpdb->last_error
                        ));
                    }
                }
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            Logger::error('count_create_failed', ['error' => $e->getMessage()]);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }

        return $countId;
    }

    /**
     * The locations a new count covers: every active location (default first),
     * optionally narrowed by `locations`.
     *
     * @param array{locations?:int[]} $args
     * @return int[]
     */
    private static function createLocations(array $args): array {
        $active = array_map(static fn (array $l): int => (int) $l['id'], Locations::all(true));
        if ($active === []) {
            $active = [Balances::defaultLocationId()];
        }
        if (!empty($args['locations'])) {
            $wanted = array_map('intval', (array) $args['locations']);
            $picked = array_values(array_filter($active, static fn (int $id): bool => in_array($id, $wanted, true)));
            if ($picked !== []) {
                return $picked;
            }
        }
        return $active;
    }

    /**
     * The stock-managed product ids in scope. Category scope keeps a stock-managed
     * id when it (a product) carries the term, OR when its parent (a variation's
     * post_parent) carries it — variations belong to a category via their parent.
     *
     * @param array{category?:int,products?:int[]} $args
     * @return int[]
     */
    public static function resolveProductIds(string $scope, array $args): array {
        $managed = Seeder::stockManagedProductIds();
        if (empty($managed)) {
            return [];
        }

        if ($scope === self::SCOPE_MANUAL) {
            $picked  = array_values(array_filter(array_map('intval', (array) ($args['products'] ?? []))));
            $allowed = array_fill_keys($managed, true);
            // Only stock-managed ids may be counted — a manual pick of an
            // unmanaged product would have no ledger balance to reconcile.
            return array_values(array_filter($picked, static fn (int $id): bool => isset($allowed[$id])));
        }

        if ($scope === self::SCOPE_CATEGORY) {
            $termId = (int) ($args['category'] ?? 0);
            if ($termId <= 0) {
                return $managed;
            }
            return self::filterByCategory($managed, $termId);
        }

        return $managed; // all
    }

    /**
     * Keep only ids whose product (or, for a variation, whose parent product) is in
     * the given product_cat term. One indexed query over term_relationships joined
     * to posts — no WC_Product loop.
     *
     * @param int[] $productIds
     * @return int[]
     */
    private static function filterByCategory(array $productIds, int $termId): array {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($ids)) {
            return [];
        }
        $in = implode(',', $ids);

        // term_taxonomy_id for this product_cat term.
        $ttId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
             WHERE term_id = %d AND taxonomy = 'product_cat' LIMIT 1",
            $termId
        ));
        if ($ttId <= 0) {
            return [];
        }

        // A candidate matches when the object with the term is the id itself (a
        // product) or its post_parent (a variation's parent). posts.post_parent is
        // 0 for top-level products, so the second join simply never matches there.
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->term_relationships} tr_self
                    ON tr_self.object_id = p.ID AND tr_self.term_taxonomy_id = %d
             LEFT JOIN {$wpdb->term_relationships} tr_parent
                    ON tr_parent.object_id = p.post_parent AND tr_parent.term_taxonomy_id = %d
             WHERE p.ID IN ($in)
               AND (tr_self.object_id IS NOT NULL OR tr_parent.object_id IS NOT NULL)",
            $ttId,
            $ttId
        ));
        return array_map('intval', (array) $rows);
    }

    /* ------------------------------ Lifecycle ----------------------------- */

    /**
     * Move a count to review ("Til gjennomgang"). Only from open. Returns true on a
     * real transition.
     */
    public static function toReview(int $countId): bool {
        return self::transition($countId, [self::STATUS_OPEN], self::STATUS_REVIEW);
    }

    /** Reopen a count for more counting. Only from review. */
    public static function reopen(int $countId): bool {
        return self::transition($countId, [self::STATUS_REVIEW], self::STATUS_OPEN);
    }

    /** Cancel a count (terminal). Allowed from open or review, never once applied. */
    public static function cancel(int $countId): bool {
        return self::transition(
            $countId,
            [self::STATUS_OPEN, self::STATUS_REVIEW],
            self::STATUS_CANCELLED,
            true
        );
    }

    /**
     * One conditional UPDATE, so a transition can never overwrite a status that
     * changed since it was read (an apply claiming the count, a second tab).
     *
     * @param string[] $from allowed current statuses
     */
    private static function transition(int $countId, array $from, string $to, bool $stampClosed = false): bool {
        global $wpdb;
        // The schema has no closed_at on counts; applied_by/applied_at are only
        // stamped by Apply. Cancellation is a pure status flip.
        $in = implode(',', array_fill(0, count($from), '%s'));
        $n  = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::counts() . " SET status = %s WHERE id = %d AND status IN ($in)",
            $to,
            $countId,
            ...$from
        ));
        return $n === 1;
    }

    /**
     * Append a line to the count note trail (override records, apply summaries).
     * The note column is 255 chars — trails are trimmed from the FRONT so the most
     * recent entries survive.
     */
    public static function appendNote(int $countId, string $entry): void {
        global $wpdb;
        $count = self::find($countId);
        if ($count === null) {
            return;
        }
        $entry   = trim($entry);
        $current = trim((string) ($count['note'] ?? ''));
        $joined  = $current === '' ? $entry : $current . ' | ' . $entry;
        if (mb_strlen($joined) > 255) {
            $joined = mb_substr($joined, mb_strlen($joined) - 255);
        }
        $wpdb->update(Schema::counts(), ['note' => $joined], ['id' => $countId], ['%s'], ['%d']);
    }

    /* ------------------------------ Helpers ------------------------------- */

    /** Human scope label stored on the count row (for the list screen). */
    private static function scopeLabel(string $scope, array $args, int $productCount): string {
        if ($scope === self::SCOPE_CATEGORY) {
            $termId = (int) ($args['category'] ?? 0);
            $term   = $termId > 0 ? \get_term($termId, 'product_cat') : null;
            $name   = ($term instanceof \WP_Term) ? $term->name : (string) $termId;
            /* translators: 1: category name, 2: number of products. */
            return mb_substr(sprintf(\__('Category: %1$s (%2$d products)', 'kaupang-stock'), $name, $productCount), 0, 255);
        }
        if ($scope === self::SCOPE_MANUAL) {
            /* translators: %d: number of products. */
            return mb_substr(sprintf(\__('Manual selection (%d products)', 'kaupang-stock'), $productCount), 0, 255);
        }
        /* translators: %d: number of products. */
        return mb_substr(sprintf(\__('All stock-managed products (%d)', 'kaupang-stock'), $productCount), 0, 255);
    }

    /** "Title (SKU)" label for a line's product, delegated to ProductSearch. */
    public static function label(int $productId): string {
        return ProductSearch::label($productId);
    }
}
