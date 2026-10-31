<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin\Purchasing;

use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Admin\PurchasingPage;

/**
 * "Lager → Innkjøp" list view (default): every purchase order with its derived status.
 */
final class ListView {

    public static function render(): void {
        $pos = PurchaseOrders::all();
        ?>
        <h1 class="wp-heading-inline"><?php echo \esc_html(\__('Purchasing', 'kaupang-stock')); ?></h1>
        <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'edit', 'po' => 'new'])); ?>" class="page-title-action"><?php \esc_html_e('New purchase order', 'kaupang-stock'); ?></a>
        <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'inbound'])); ?>" class="page-title-action"><?php echo \esc_html(\__('Inbound', 'kaupang-stock')); ?></a>
        <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'suppliers'])); ?>" class="page-title-action"><?php echo \esc_html(\__('Suppliers', 'kaupang-stock')); ?></a>
        <hr class="wp-header-end" />

        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php \esc_html_e('PO', 'kaupang-stock'); ?></th>
                    <th><?php echo \esc_html(\__('Supplier', 'kaupang-stock')); ?></th>
                    <th><?php \esc_html_e('Status', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Lines', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('ETA', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Reference', 'kaupang-stock'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pos === []): ?>
                    <tr><td colspan="7"><?php \esc_html_e('No purchase orders yet.', 'kaupang-stock'); ?></td></tr>
                <?php else: foreach ($pos as $po):
                    $poId    = (int) $po['id'];
                    $lines   = Lines::forPoWithReceived($poId);
                    $status  = PurchaseOrders::derivedStatus($po, $lines);
                    $editUrl = PurchasingPage::url(['view' => 'edit', 'po' => $poId]);
                    ?>
                    <tr>
                        <td><a href="<?php echo \esc_url($editUrl); ?>"><strong>#<?php echo (int) $poId; ?></strong></a></td>
                        <td><?php echo \esc_html(Suppliers::name(isset($po['supplier_id']) ? (int) $po['supplier_id'] : null)); ?></td>
                        <td><?php PurchasingPage::statusChip($status); ?></td>
                        <td><?php echo (int) count($lines); ?></td>
                        <td><?php echo \esc_html(PurchasingPage::localDate((string) ($po['eta'] ?? ''))); ?></td>
                        <td><?php echo \esc_html((string) ($po['supplier_ref'] ?? '')); ?></td>
                        <td>
                            <a href="<?php echo \esc_url($editUrl); ?>"><?php \esc_html_e('Open', 'kaupang-stock'); ?></a>
                            <?php if ($status === PurchaseOrders::STATUS_ORDERED || $status === PurchaseOrders::STATUS_PARTIAL): ?>
                                &nbsp;|&nbsp;<a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'receive', 'po' => $poId])); ?>"><?php \esc_html_e('Receive goods', 'kaupang-stock'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }
}
