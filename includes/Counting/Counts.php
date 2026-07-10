<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Ledger\Balances;
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
 * Lines snapshot `expected = Balances::onHand($productId)` AT CREATION — the
 * count-time truth the relative-variance apply keys on (a sale after the shelf was
 * counted survives). Blind by default: the review UI hides `expected` until the
 * operator flips "Vis forventet".
 */
final class Counts {

    public const STATUS_OPEN      = 'open';
    public const STATUS_REVIEW    = 'review';
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
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS lines,
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
     * (Seeder::stockManagedProductIds()), narrowed by scope. `expected` per line
     * is the live on-hand at creation. Returns the new count id.
     *
     * @param array{scope?:string,category?:int,products?:int[],blind?:bool,note?:string} $args
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

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(Schema::counts(), [
            'status'     => self::STATUS_OPEN,
            'blind'      => $blind ? 1 : 0,
            'scope'      => $scopeLabel,
            'note'       => $note !== '' ? $note : null,
            'created_by' => \get_current_user_id(),
            'created_at' => $now,
        ], ['%s', '%d', '%s', '%s', '%d', '%s']);

        $countId = (int) $wpdb->insert_id;
        if ($countId <= 0) {
            return 0;
        }

        foreach ($productIds as $productId) {
            $expected = Balances::onHand($productId);
            $wpdb->insert(Schema::countLines(), [
                'count_id'   => $countId,
                'product_id' => $productId,
                'expected'   => number_format($expected, 3, '.', ''),
                'recount'    => 0,
            ], ['%d', '%d', '%s', '%d']);
        }

        return $countId;
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
     * @param string[] $from allowed current statuses
     */
    private static function transition(int $countId, array $from, string $to, bool $stampClosed = false): bool {
        global $wpdb;
        $count = self::find($countId);
        if ($count === null || !in_array((string) $count['status'], $from, true)) {
            return false;
        }
        $data    = ['status' => $to];
        $formats = ['%s'];
        // The schema has no closed_at on counts; applied_by/applied_at are only
        // stamped by Apply. Cancellation is a pure status flip.
        $updated = $wpdb->update(Schema::counts(), $data, ['id' => $countId], $formats, ['%d']);
        return $updated !== false;
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
