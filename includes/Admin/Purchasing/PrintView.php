<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin\Purchasing;

use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\SupplierProducts;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Support\ProductSearch;
use Kaupang\Stock\Admin\PurchasingPage;

/**
 * Printable purchase order (?view=print&po=<id>), the document sent to the supplier.
 */
final class PrintView {

    public static function render(): void {
        $poId = (int) ($_GET['po'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        $po   = $poId > 0 ? PurchaseOrders::find($poId) : null;
        if ($po === null) {
            echo '<div class="notice notice-error"><p>' . \esc_html__('Purchase order not found.', 'kaupang-stock') . '</p></div>';
            return;
        }
        $supplierId = (int) ($po['supplier_id'] ?? 0);
        $supplier   = Suppliers::find($supplierId);
        $lines      = Lines::forPoWithReceived($poId);
        $dateUtc    = (string) (($po['ordered_at'] ?? '') ?: ($po['created_at'] ?? ''));
        $date       = $dateUtc !== '' ? \get_date_from_gmt($dateUtc, (string) \get_option('date_format')) : '—';
        ?>
        <div class="ks-print-actions">
            <a class="button" href="<?php echo \esc_url(PurchasingPage::url(['view' => 'edit', 'po' => $poId])); ?>"><?php \esc_html_e('Back to order', 'kaupang-stock'); ?></a>
            <button type="button" class="button button-primary" onclick="window.print()"><?php \esc_html_e('Print / Save as PDF', 'kaupang-stock'); ?></button>
        </div>
        <article class="ks-po-print">
            <header>
                <p class="ks-print-kicker"><?php \esc_html_e('Ordering list', 'kaupang-stock'); ?></p>
                <h1><?php echo \esc_html((string) ($supplier['name'] ?? Suppliers::name($supplierId))); ?></h1>
                <dl>
                    <div><dt><?php \esc_html_e('PO', 'kaupang-stock'); ?></dt><dd>#<?php echo $poId; ?></dd></div>
                    <div><dt><?php \esc_html_e('Date', 'kaupang-stock'); ?></dt><dd><?php echo \esc_html($date); ?></dd></div>
                    <?php if (!empty($po['supplier_ref'])): ?>
                        <div><dt><?php \esc_html_e('Supplier reference', 'kaupang-stock'); ?></dt><dd><?php echo \esc_html((string) $po['supplier_ref']); ?></dd></div>
                    <?php endif; ?>
                </dl>
            </header>
            <table>
                <thead><tr>
                    <th><?php \esc_html_e('Supplier product name', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Supplier SKU', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Our product name', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Our SKU', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Qty ordered', 'kaupang-stock'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($lines as $line):
                    $productId = (int) $line['product_id'];
                    $identity  = SupplierProducts::find($supplierId, $productId);
                    $product   = \wc_get_product($productId);
                    $ourName   = $product instanceof \WC_Product ? $product->get_name() : ProductSearch::label($productId);
                    $ourSku    = $product instanceof \WC_Product ? $product->get_sku() : '';
                    ?>
                    <tr>
                        <td><?php echo \esc_html((string) ($identity['supplier_name'] ?? '')); ?></td>
                        <td><?php echo \esc_html((string) ($identity['supplier_sku'] ?? '')); ?></td>
                        <td><?php echo \esc_html($ourName); ?></td>
                        <td><?php echo \esc_html($ourSku); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $line['qty_ordered'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </article>
        <?php
    }
}
