<?php
declare(strict_types=1);

namespace Kaupang\Stock\Purchasing;

use Kaupang\Stock\Schema;

/**
 * Supplier-specific product identity and catalog.
 *
 * These rows are ordinary purchasing master data, not stock ledger data. This
 * class is deliberately the only write path for the supplier_products table.
 */
final class SupplierProducts {

    /** @return array<string,mixed>|null */
    public static function find(int $supplierId, int $productId): ?array {
        global $wpdb;
        if ($supplierId <= 0 || $productId <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::supplierProducts() . ' WHERE supplier_id = %d AND product_id = %d',
            $supplierId,
            $productId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** All catalog rows for one supplier. @return array<int,array<string,mixed>> */
    public static function forSupplier(int $supplierId): array {
        global $wpdb;
        if ($supplierId <= 0) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::supplierProducts()
            . ' WHERE supplier_id = %d ORDER BY COALESCE(supplier_name, \'\') ASC, COALESCE(supplier_sku, \'\') ASC, id ASC',
            $supplierId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** All supplier links for one product. @return array<int,array<string,mixed>> */
    public static function forProduct(int $productId): array {
        return self::forProducts([$productId]);
    }

    /**
     * Supplier links for several products, enriched with the supplier name.
     *
     * @param int[] $productIds
     * @return array<int,array<string,mixed>>
     */
    public static function forProducts(array $productIds): array {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = 'SELECT sp.*, s.name AS supplier_label FROM ' . Schema::supplierProducts() . ' sp'
            . ' INNER JOIN ' . Schema::suppliers() . ' s ON s.id = sp.supplier_id'
            . " WHERE sp.product_id IN ($placeholders) ORDER BY s.name ASC, sp.id ASC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Create or update one catalog row. Empty identity fields are retained as
     * NULL: the row itself is what makes the product part of the supplier picker.
     */
    public static function save(int $supplierId, int $productId, ?string $supplierSku, ?string $supplierName): int {
        global $wpdb;
        if ($supplierId <= 0 || $productId <= 0) {
            throw new \InvalidArgumentException('A supplier and product are required');
        }
        $sku  = self::clean($supplierSku, 100);
        $name = self::clean($supplierName, 200);
        $existing = self::find($supplierId, $productId);

        if ($existing !== null) {
            $ok = $wpdb->update(
                Schema::supplierProducts(),
                ['supplier_sku' => $sku, 'supplier_name' => $name],
                ['id' => (int) $existing['id']],
                ['%s', '%s'],
                ['%d']
            );
            if ($ok === false) {
                throw new \RuntimeException('Supplier product update failed: ' . $wpdb->last_error);
            }
            return (int) $existing['id'];
        }

        $ok = $wpdb->insert(
            Schema::supplierProducts(),
            [
                'supplier_id'   => $supplierId,
                'product_id'    => $productId,
                'supplier_sku'  => $sku,
                'supplier_name' => $name,
            ],
            ['%d', '%d', '%s', '%s']
        );
        if ($ok === false || $wpdb->insert_id <= 0) {
            throw new \RuntimeException('Supplier product insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /** Delete one catalog link (used by maintenance and self-cleaning tests). */
    public static function delete(int $supplierId, int $productId): void {
        global $wpdb;
        if ($supplierId <= 0 || $productId <= 0) {
            return;
        }
        $wpdb->delete(
            Schema::supplierProducts(),
            ['supplier_id' => $supplierId, 'product_id' => $productId],
            ['%d', '%d']
        );
    }

    private static function clean(?string $value, int $max): ?string {
        $value = mb_substr(\sanitize_text_field((string) $value), 0, $max);
        return $value !== '' ? $value : null;
    }
}
