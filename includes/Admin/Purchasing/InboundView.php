<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin\Purchasing;

defined('ABSPATH') || exit;

use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Admin\PurchasingPage;

/**
 * Inbound view (?view=inbound): every open purchase-order line across all orders.
 */
final class InboundView {

    public static function render(): void {
        $rows = PurchaseOrders::inboundLines();
        ?>
        <h1 class="wp-heading-inline"><?php echo \esc_html(\__('Inbound', 'kaupang-stock')); ?></h1>
        <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'list'])); ?>" class="page-title-action"><?php \esc_html_e('Back to list', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />
        <p class="description"><?php \esc_html_e('Every open purchase-order line across all orders — the single "what is coming" answer.', 'kaupang-stock'); ?></p>

        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Ordered', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Received', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Remaining', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('ETA', 'kaupang-stock'); ?></th>
                    <th><?php echo \esc_html(\__('Supplier', 'kaupang-stock')); ?></th>
                    <th><?php \esc_html_e('PO', 'kaupang-stock'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><?php \esc_html_e('Nothing incoming.', 'kaupang-stock'); ?></td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo \esc_html((string) $row['product_label']); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $row['ordered'])); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $row['received'])); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $row['remaining'])); ?></td>
                        <td><?php echo \esc_html(PurchasingPage::localDate((string) $row['eta'])); ?></td>
                        <td><?php echo \esc_html(Suppliers::name((int) $row['supplier_id'])); ?></td>
                        <td><a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'edit', 'po' => (int) $row['po_id']])); ?>">#<?php echo (int) $row['po_id']; ?></a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }
}
