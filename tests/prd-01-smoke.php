<?php

use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\SupplierProducts;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Schema;

defined('ABSPATH') || exit(1);

global $wpdb;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('PRD-01 assertion failed: ' . $message);
    }
};

$productId = 0;
$supplierId = 0;
$poIds = [];

try {
    Schema::maybeUpgrade();

    // Create a catalog-only draft without WC_Product::save(): that method emits
    // stock hooks even when manage_stock is off, which would make the observer
    // retain an audit balance row after this otherwise self-cleaning test.
    $productId = (int) wp_insert_post([
        'post_type'   => 'product',
        'post_status' => 'draft',
        'post_title'  => 'KSTEST PRD-01 product',
    ]);
    $assert($productId > 0, 'test product was created');
    update_post_meta($productId, '_sku', 'KSTEST-PRD01-' . wp_generate_password(8, false, false));

    $supplierId = Suppliers::create([
        'name'    => 'KSTEST PRD-01 supplier',
        'country' => 'NO',
        'active'  => 1,
    ]);
    $assert($supplierId > 0, 'test supplier was created');

    $catalogId = SupplierProducts::save($supplierId, $productId, 'SUP-001', 'Supplier test product');
    $assert($catalogId > 0, 'supplier product was created');

    $sameId = SupplierProducts::save($supplierId, $productId, 'SUP-002', 'Supplier product updated');
    $assert($sameId === $catalogId, 'save updates the unique supplier/product row');

    $mapping = SupplierProducts::find($supplierId, $productId);
    $assert($mapping !== null, 'supplier identity can be read back');
    $assert((string) $mapping['supplier_sku'] === 'SUP-002', 'supplier SKU is reused');
    $assert((string) $mapping['supplier_name'] === 'Supplier product updated', 'supplier name is reused');

    $catalog = SupplierProducts::forSupplier($supplierId);
    $assert(count($catalog) === 1, 'supplier catalog lists the mapped product exactly once');
    $assert((int) $catalog[0]['product_id'] === $productId, 'catalog row points to the test product');

    $links = SupplierProducts::forProduct($productId);
    $assert(count($links) === 1, 'product panel lookup lists the supplier link');
    $assert((string) $links[0]['supplier_label'] === 'KSTEST PRD-01 supplier', 'product lookup includes supplier label');

    $poIds[] = PurchaseOrders::create(['supplier_id' => $supplierId, 'supplier_ref' => 'KSTEST-ONE']);
    [$lineId, $merged] = Lines::add($poIds[0], $productId, 2, null, null);
    $assert($lineId > 0 && $merged === false, 'first PO line is created');
    [$sameLineId, $merged] = Lines::add($poIds[0], $productId, 3, null, null);
    $assert($sameLineId === $lineId && $merged === true, 'duplicate PO product merges into its line');
    $line = Lines::find($lineId);
    $assert($line !== null && (float) $line['qty_ordered'] === 5.0, 'merged line quantity is correct');

    $poIds[] = PurchaseOrders::create(['supplier_id' => $supplierId, 'supplier_ref' => 'KSTEST-TWO']);
    Lines::add($poIds[1], $productId, 1, null, null);
    $reused = SupplierProducts::find($supplierId, $productId);
    $assert($reused !== null && (string) $reused['supplier_sku'] === 'SUP-002', 'identity is reused on a later PO');

    SupplierProducts::delete($supplierId, $productId);
    $assert(SupplierProducts::find($supplierId, $productId) === null, 'catalog deletion uses SupplierProducts and succeeds');

    WP_CLI::success('PRD-01 supplier catalog / PO-line smoke test passed.');
} finally {
    if ($supplierId > 0 && $productId > 0) {
        SupplierProducts::delete($supplierId, $productId);
    }
    foreach ($poIds as $poId) {
        $wpdb->delete(Schema::purchaseOrderLines(), ['po_id' => (int) $poId], ['%d']);
        $wpdb->delete(Schema::purchaseOrders(), ['id' => (int) $poId], ['%d']);
    }
    if ($supplierId > 0) {
        $wpdb->delete(Schema::suppliers(), ['id' => $supplierId], ['%d']);
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
    }
}
