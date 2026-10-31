<?php

use Kaupang\Stock\Counting\Import;
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
$fallbackId = 0;
$foreignId = 0;

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

    // The locked-PO rule lives in the model: everything except draft/cancelled is locked.
    $poIds[] = PurchaseOrders::create(['supplier_id' => $supplierId, 'supplier_ref' => 'KSTEST-LOCK']);
    $lockPo  = $poIds[count($poIds) - 1];
    $assert(PurchaseOrders::isLocked($lockPo) === false, 'a draft PO is not locked');
    foreach ([PurchaseOrders::STATUS_ORDERED, PurchaseOrders::STATUS_PARTIAL, PurchaseOrders::STATUS_RECEIVED] as $locked) {
        $wpdb->update(Schema::purchaseOrders(), ['status' => $locked], ['id' => $lockPo], ['%s'], ['%d']);
        $assert(PurchaseOrders::isLocked($lockPo) === true, 'a ' . $locked . ' PO is locked');
    }
    $wpdb->update(Schema::purchaseOrders(), ['status' => PurchaseOrders::STATUS_CANCELLED], ['id' => $lockPo], ['%s'], ['%d']);
    $assert(PurchaseOrders::isLocked($lockPo) === false, 'a cancelled PO is not locked');

    // Counting import owns the CSV parse: BOM strip, ';' sniff, SKU → product.
    $sku    = (string) get_post_meta($productId, '_sku', true);
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF" . "sku;counted\n" . $sku . ";7\nKSTEST-NO-SUCH-SKU-1;3\nKSTEST-NO-SUCH-SKU-2;4\n");
    rewind($handle);
    $parsed = Import::parse($handle);
    fclose($handle);
    $assert($parsed['lines'] === [$productId => 7.0], 'the known SKU line maps to its product id with its counted qty');
    $assert(count($parsed['errors']) === 2, 'both unknown SKUs are reported as errors');

    // PO document (stock#3): the supplier's language, buyer header, supplier-facing columns only.
    $fallbackId = (int) wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'KSTEST PRD-01 fallback product']);
    $foreignId  = Suppliers::create(['name' => 'KSTEST PRD-01 supplier SE', 'country' => 'SE', 'active' => 1]);
    $assert($fallbackId > 0 && $foreignId > 0, 'print fixtures were created');
    $ourSku = (string) get_post_meta($productId, '_sku', true);
    $printHtml = static function (int $poId): array {
        $_GET = ['page' => 'kaupang-stock-purchasing', 'view' => 'print', 'po' => (string) $poId];
        ob_start();
        \Kaupang\Stock\Admin\Purchasing\PrintView::render();
        $html = (string) ob_get_clean();
        $at   = (int) strpos($html, '<article');
        return [substr($html, 0, $at), substr($html, $at)]; // [user-locale chrome, supplier document]
    };
    $nb = ['Innkjøpsordre', 'Ordrenr.', 'Dato', 'Leverandør', 'Fakturaadresse', 'Leveringsadresse', 'Leverandørens produktnavn', 'Leverandørens SKU', 'Antall bestilt', '15. mars 2026'];
    $en = ['Purchase Order', 'Order no.', 'Date', 'Supplier', 'Invoice address', 'Delivery address', 'Supplier product name', 'Supplier SKU', 'Qty ordered', '15. March 2026'];
    $has = static fn (string $html, array $needles): array => array_values(array_filter($needles, static fn (string $n): bool => strpos($html, $n) === false));
    $hasAny = static fn (string $html, array $needles): array => array_values(array_filter($needles, static fn (string $n): bool => strpos($html, '>' . $n . '<') !== false));
    $blog = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    foreach (['NO' => $supplierId, 'SE' => $foreignId] as $country => $sid) {
        SupplierProducts::save($sid, $productId, 'SUP-' . $country, 'Supplier name ' . $country);
        SupplierProducts::save($sid, $fallbackId, 'SUP-FB-' . $country, ''); // no supplier name: falls back to ours
        $poIds[] = $po = PurchaseOrders::create(['supplier_id' => $sid, 'supplier_ref' => 'KSTEST-PRINT-' . $country]);
        Lines::add($po, $productId, 4, null, null);
        Lines::add($po, $fallbackId, 2, null, null);
        $wpdb->update(Schema::purchaseOrders(), ['created_at' => '2026-03-15 10:00:00', 'ordered_at' => null], ['id' => $po], ['%s', '%s'], ['%d']);

        // A Norwegian supplier printed by an English-locale user, a Swedish one by a Norwegian-locale user.
        $userLocale = $country === 'NO' ? 'en_US' : 'nb_NO';
        $switched   = switch_to_locale($userLocale);
        [$chrome, $doc] = $printHtml($po);
        $back = __('Back to order', 'kaupang-stock');
        $switched && restore_previous_locale();
        [$want, $not] = $country === 'NO' ? [$nb, $en] : [$en, $nb];
        $assert($has($doc, $want) === [], "$country print is fully in its language; missing: " . implode(', ', $has($doc, $want)));
        $assert($hasAny($doc, array_diff($not, $want)) === [], "$country print has no other-language label; found: " . implode(', ', $hasAny($doc, array_diff($not, $want))));
        $assert(strpos($chrome, $back) !== false && $back === ($userLocale === 'nb_NO' ? 'Tilbake til ordren' : 'Back to order'), "$country print: action buttons stay in the user's locale ($userLocale)");
        $assert(substr_count($doc, $blog) === 2, "$country print: the store is both invoice and delivery address");
        $assert(strpos($doc, 'Supplier name ' . $country) !== false && strpos($doc, '>KSTEST PRD-01 fallback product<') !== false, "$country print: supplier product name, else ours");
        $assert(strpos($doc, 'KSTEST PRD-01 product') === false && strpos($doc, $ourSku) === false
            && $hasAny($doc, ['Our product name', 'Our SKU', 'Vårt produktnavn', 'Vår SKU']) === [], "$country print: no internal product name/SKU column");
    }
    $assert(strpos($doc, 'Norway') !== false && strpos($doc, 'Norge') === false, 'SE print: the buyer country is shown, in English');

    WP_CLI::success('PRD-01 supplier catalog / PO-line smoke test passed.');
} finally {
    if ($supplierId > 0 && $productId > 0) {
        SupplierProducts::delete($supplierId, $productId);
    }
    foreach ([$supplierId, $foreignId] as $sid) {
        $sid > 0 && $wpdb->delete(Schema::supplierProducts(), ['supplier_id' => $sid], ['%d']);
    }
    $foreignId > 0 && $wpdb->delete(Schema::suppliers(), ['id' => $foreignId], ['%d']);
    $fallbackId > 0 && wp_delete_post($fallbackId, true);
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
