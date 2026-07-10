<?php
declare(strict_types=1);

namespace Kaupang\Stock\Support;

/**
 * Small shared product finder for admin forms (adjustment form, PO lines,
 * count scope picks) and the scanner path (keyboard-wedge SKU scans). Direct
 * SQL — these screens never loop WC_Product objects.
 */
final class ProductSearch {

    /**
     * SKU exact match first (the scanner contract: a scan IS a SKU in v1),
     * then SKU prefix + title LIKE.
     *
     * @return array<int,array{id:int,title:string,sku:string}>
     */
    public static function search(string $term, int $limit = 20): array {
        global $wpdb;
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . $wpdb->esc_like($term) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS id, p.post_title AS title, COALESCE(sku.meta_value, '') AS sku,
                    (COALESCE(sku.meta_value, '') = %s) AS exact_sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (sku.meta_value LIKE %s OR p.post_title LIKE %s)
             ORDER BY exact_sku DESC, p.post_title ASC
             LIMIT %d",
            $term,
            $like,
            $like,
            max(1, min(100, $limit))
        ), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[] = [
                'id'    => (int) $row['id'],
                'title' => (string) $row['title'],
                'sku'   => (string) $row['sku'],
            ];
        }
        return $out;
    }

    /** Exact-SKU resolution for scan input; null when unknown or ambiguous. */
    public static function bySku(string $sku): ?int {
        global $wpdb;
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')
             LIMIT 2",
            $sku
        ));
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /** "Title (SKU)" display label without loading a WC_Product. */
    public static function label(int $productId): string {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.post_title AS title, COALESCE(sku.meta_value, '') AS sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             WHERE p.ID = %d",
            $productId
        ), ARRAY_A);
        if (!is_array($row)) {
            return '#' . $productId;
        }
        $title = (string) $row['title'];
        $sku   = (string) $row['sku'];
        return $sku !== '' ? sprintf('%s (%s)', $title, $sku) : $title;
    }
}
