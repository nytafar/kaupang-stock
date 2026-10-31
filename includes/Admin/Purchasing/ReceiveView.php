<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin\Purchasing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Admin\PurchasingPage;

/**
 * Receive view (?view=receive&po=<id>): the grid that POSTs to the REST `receive` route.
 */
final class ReceiveView {

    public static function render(): void {
        $poId = (int) ($_GET['po'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        $po   = $poId > 0 ? PurchaseOrders::find($poId) : null;
        if ($po === null) {
            echo '<div class="notice notice-error"><p>' . \esc_html__('Purchase order not found.', 'kaupang-stock') . '</p></div>';
            return;
        }

        $lines  = Lines::forPoWithReceived($poId);
        $status = PurchaseOrders::derivedStatus($po, $lines);
        $open   = $status === PurchaseOrders::STATUS_ORDERED || $status === PurchaseOrders::STATUS_PARTIAL;

        // The token is minted at LOAD and printed into the form: it becomes the
        // movement batch id AND per-line idempotency keys, so a double-click /
        // resend / replay dedupes to one posting.
        $token = \wp_generate_uuid4();
        // Site-local "now" for the default of the operator-editable occurred_at.
        $nowLocal = \wp_date('Y-m-d\TH:i');
        ?>
        <h1 class="wp-heading-inline"><?php echo \esc_html(sprintf(/* translators: %d: purchase order number */ \__('Receive goods — purchase order #%d', 'kaupang-stock'), $poId)); ?></h1>
        <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'edit', 'po' => $poId])); ?>" class="page-title-action"><?php \esc_html_e('Back to order', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />

        <?php if (!$open): ?>
            <div class="notice notice-warning inline"><p>
                <?php \esc_html_e('This purchase order is not open for receiving.', 'kaupang-stock'); ?>
            </p></div>
            <?php return; ?>
        <?php endif; ?>

        <?php if (!Settings::activeMode()): ?>
            <div class="notice notice-warning inline"><p>
                <?php \esc_html_e('Receiving writes stock and requires active mode. It is disabled while the ledger runs in shadow mode — enable active mode in the stock settings.', 'kaupang-stock'); ?>
            </p></div>
        <?php endif; ?>

        <form class="ks-receive"
              data-ks-receive
              data-po="<?php echo (int) $poId; ?>"
              data-token="<?php echo \esc_attr($token); ?>"
              <?php echo Settings::activeMode() ? '' : 'data-disabled="1"'; ?>>
            <p class="description"><?php \esc_html_e('Quantities are pre-filled with what remains. Adjust for a partial delivery, then confirm. The server re-checks each remaining quantity at submit.', 'kaupang-stock'); ?></p>

            <?php $withCosts = \Kaupang\Stock\Costing\Costing::enabled(); ?>
            <div class="ks-tablewrap">
            <table class="wp-list-table widefat striped ks-receive-grid">
                <thead>
                    <tr>
                        <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Ordered', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Received', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Remaining', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Receive now', 'kaupang-stock'); ?></th>
                        <?php if ($withCosts): ?>
                            <th class="ks-num"><?php \esc_html_e('Unit cost ex-VAT (kr)', 'kaupang-stock'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line):
                        $lineId    = (int) $line['id'];
                        $remaining = (float) $line['remaining'];
                        ?>
                        <tr data-line="<?php echo (int) $lineId; ?>"
                            data-product="<?php echo (int) $line['product_id']; ?>"
                            data-label="<?php echo \esc_attr((string) $line['product_label']); ?>"
                            data-ordered="<?php echo \esc_attr((string) (float) $line['qty_ordered']); ?>"
                            data-received="<?php echo \esc_attr((string) (float) $line['received']); ?>"
                            data-remaining="<?php echo \esc_attr((string) $remaining); ?>">
                            <td><?php echo \esc_html((string) $line['product_label']); ?></td>
                            <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $line['qty_ordered'])); ?></td>
                            <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $line['received'])); ?></td>
                            <td class="ks-num ks-remaining"><?php echo \esc_html(PurchasingPage::qty($remaining)); ?></td>
                            <td class="ks-num">
                                <input type="number" step="1" min="0" class="small-text ks-receive-qty"
                                       data-line="<?php echo (int) $lineId; ?>"
                                       value="<?php echo \esc_attr(self::inputQty($remaining)); ?>"
                                       <?php echo Settings::activeMode() ? '' : 'disabled'; ?> />
                            </td>
                            <?php if ($withCosts): ?>
                                <td class="ks-num">
                                    <input type="number" step="0.01" min="0" class="small-text ks-receive-cost"
                                           data-line="<?php echo (int) $lineId; ?>"
                                           value="<?php echo isset($line['unit_cost_ore']) && $line['unit_cost_ore'] !== null ? \esc_attr(number_format(((int) $line['unit_cost_ore']) / 100, 2, '.', '')) : ''; ?>"
                                           title="<?php \esc_attr_e('Actual unit cost ex-VAT for THIS delivery — pre-filled from the PO line; adjust if the invoice differs.', 'kaupang-stock'); ?>"
                                           <?php echo Settings::activeMode() ? '' : 'disabled'; ?> />
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <table class="form-table" role="presentation">
                <?php if (\Kaupang\Stock\Locations::isMulti()): ?>
                <tr>
                    <th scope="row"><label for="ks-receive-location"><?php \esc_html_e('Location', 'kaupang-stock'); ?></label></th>
                    <td>
                        <select id="ks-receive-location" class="ks-receive-location"<?php echo Settings::activeMode() ? '' : ' disabled'; ?>>
                            <?php foreach (\Kaupang\Stock\Locations::all(true) as $location): ?>
                                <option value="<?php echo (int) $location['id']; ?>" <?php \selected((int) $location['id'], Balances::defaultLocationId()); ?>><?php echo \esc_html((string) $location['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php \esc_html_e('Which location these goods arrive at. Partial receipts of the same order can go to different locations.', 'kaupang-stock'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><label for="ks-occurred"><?php \esc_html_e('Received at', 'kaupang-stock'); ?></label></th>
                    <td>
                        <input type="datetime-local" id="ks-occurred" class="ks-occurred" value="<?php echo \esc_attr($nowLocal); ?>"
                               <?php echo Settings::activeMode() ? '' : 'disabled'; ?> />
                        <p class="description"><?php \esc_html_e('When the goods physically arrived. Defaults to now; set it back for "the goods came last week".', 'kaupang-stock'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary ks-receive-submit" <?php \disabled(!Settings::activeMode()); ?>>
                    <?php \esc_html_e('Receive goods', 'kaupang-stock'); ?>
                </button>
                <span class="ks-receive-feedback" role="status" aria-live="polite"></span>
            </p>
        </form>

        <!-- Combined over-receipt / changed-PO confirm dialog, populated from the server's issue rows. -->
        <dialog class="ks-confirm" data-ks-confirm>
            <h2 data-ks-confirm-title></h2>
            <p data-ks-confirm-intro></p>
            <table class="widefat striped"><tbody data-ks-confirm-rows></tbody></table>
            <div class="ks-confirm-actions">
                <button type="button" class="button" data-ks-confirm-cancel></button>
                <button type="button" class="button button-primary" data-ks-confirm-ok></button>
            </div>
        </dialog>
        <?php
    }

    /** Pre-fill value for a receive input: the remaining qty, integer-formatted. */
    private static function inputQty(float $remaining): string {
        if ($remaining <= 0) {
            return '0';
        }
        return abs($remaining - round($remaining)) < 1e-9
            ? (string) (int) round($remaining)
            : (string) $remaining;
    }
}
