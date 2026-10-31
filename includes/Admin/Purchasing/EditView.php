<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin\Purchasing;

defined('ABSPATH') || exit;

use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\SupplierProducts;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;
use Kaupang\Stock\Admin\Menu;
use Kaupang\Stock\Admin\Screen;
use Kaupang\Stock\Admin\PurchasingPage;

/**
 * PO editor view (?view=edit&po=<id>|new): header, lines, supplier catalog, product
 * picker and receipt history, plus the admin-post handlers and async REST line
 * callbacks that mutate a PO (registered from PurchasingPage / Rest\Controller).
 */
final class EditView {

    public const SAVE_PO     = 'kaupang_stock_save_po';
    public const ORDER_PO    = 'kaupang_stock_order_po';
    public const CANCEL_PO   = 'kaupang_stock_cancel_po';
    public const SAVE_LINE   = 'kaupang_stock_save_po_line';
    public const DELETE_LINE = 'kaupang_stock_delete_po_line';

    public static function render(): void {
        $isNew = (isset($_GET['po']) && (string) $_GET['po'] === 'new'); // phpcs:ignore WordPress.Security.NonceVerification
        $poId  = $isNew ? 0 : (int) ($_GET['po'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        $po    = $poId > 0 ? PurchaseOrders::find($poId) : null;

        if ($poId > 0 && $po === null) {
            echo '<div class="notice notice-error"><p>' . \esc_html__('Purchase order not found.', 'kaupang-stock') . '</p></div>';
            return;
        }

        $lines    = $po !== null ? Lines::forPoWithReceived($poId) : [];
        $status   = $po !== null ? PurchaseOrders::derivedStatus($po, $lines) : PurchaseOrders::STATUS_DRAFT;
        $locked   = $po !== null && PurchaseOrders::locks($status);
        $isDraft  = $status === PurchaseOrders::STATUS_DRAFT;
        $isClosed = $status === PurchaseOrders::STATUS_CANCELLED || $status === PurchaseOrders::STATUS_RECEIVED;
        $supplierId = (int) ($po['supplier_id'] ?? 0);
        ?>
        <h1 class="wp-heading-inline">
            <?php echo $po === null ? \esc_html__('New purchase order', 'kaupang-stock') : \esc_html(sprintf(/* translators: %d: purchase order number */ \__('Purchase order #%d', 'kaupang-stock'), $poId)); ?>
        </h1>
        <?php if ($po !== null): ?>
            <span class="ks-inline-status"><?php PurchasingPage::statusChip($status); ?></span>
            <?php if ($lines !== []): ?>
                <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'print', 'po' => $poId])); ?>" class="page-title-action" target="_blank" rel="noopener"><?php \esc_html_e('Purchase Order', 'kaupang-stock'); ?></a>
            <?php endif; ?>
        <?php endif; ?>
        <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'list'])); ?>" class="page-title-action"><?php \esc_html_e('Back to list', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />

        <div class="ks-po-grid">
            <div class="ks-po-header">
                <h2><?php echo \esc_html(\__('Details', 'kaupang-stock')); ?></h2>
                <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_PO); ?>" />
                    <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
                    <?php \wp_nonce_field(self::SAVE_PO); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ks-supplier"><?php echo \esc_html(\__('Supplier', 'kaupang-stock')); ?></label></th>
                            <td><?php self::supplierSelect((int) ($po['supplier_id'] ?? 0)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ks-ref"><?php \esc_html_e('Supplier reference', 'kaupang-stock'); ?></label></th>
                            <td><input type="text" id="ks-ref" name="supplier_ref" class="regular-text" maxlength="100"
                                       value="<?php echo \esc_attr((string) ($po['supplier_ref'] ?? '')); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ks-eta"><?php \esc_html_e('ETA', 'kaupang-stock'); ?></label></th>
                            <td><input type="date" id="ks-eta" name="eta" value="<?php echo \esc_attr((string) ($po['eta'] ?? '')); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ks-note"><?php \esc_html_e('Note', 'kaupang-stock'); ?></label></th>
                            <td>
                                <textarea id="ks-note" name="note" class="large-text" rows="2" maxlength="255"><?php echo \esc_textarea((string) ($po['note'] ?? '')); ?></textarea>
                                <?php if ($locked): ?>
                                    <p class="description"><?php \esc_html_e('The order is placed — line changes append to this note trail rather than editing locked lines.', 'kaupang-stock'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php \submit_button($po === null ? \__('Create draft', 'kaupang-stock') : \__('Save details', 'kaupang-stock')); ?>
                </form>

                <?php if ($po !== null && $isDraft && $lines !== []): ?>
                    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-inline-form"
                          onsubmit="return confirm('<?php echo \esc_js(__('Place this order? Lines will be locked; further changes append to the note.', 'kaupang-stock')); ?>');">
                        <input type="hidden" name="action" value="<?php echo \esc_attr(self::ORDER_PO); ?>" />
                        <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
                        <?php \wp_nonce_field(self::ORDER_PO); ?>
                        <?php \submit_button(\__('Place order', 'kaupang-stock'), 'primary', 'submit', false); ?>
                    </form>
                <?php endif; ?>

                <?php if ($po !== null && ($status === PurchaseOrders::STATUS_ORDERED || $status === PurchaseOrders::STATUS_PARTIAL)): ?>
                    <a href="<?php echo \esc_url(PurchasingPage::url(['view' => 'receive', 'po' => $poId])); ?>" class="button button-primary"><?php \esc_html_e('Receive goods', 'kaupang-stock'); ?></a>
                <?php endif; ?>

                <?php if ($po !== null && !$isClosed): ?>
                    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-inline-form"
                          onsubmit="return confirm('<?php echo \esc_js(__('Cancel this purchase order? Any received stock stays; the remainder is written off.', 'kaupang-stock')); ?>');">
                        <input type="hidden" name="action" value="<?php echo \esc_attr(self::CANCEL_PO); ?>" />
                        <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
                        <?php \wp_nonce_field(self::CANCEL_PO); ?>
                        <?php \submit_button(\__('Cancel order', 'kaupang-stock'), 'delete', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($po !== null): ?>
                <div class="ks-po-lines">
                    <h2><?php \esc_html_e('Lines', 'kaupang-stock'); ?></h2>
                    <?php self::renderLinesTable($poId, $supplierId, $lines, $isDraft); ?>

                    <?php if ($isDraft): ?>
                        <?php self::renderSupplierCatalog($supplierId); ?>
                        <h3><?php \esc_html_e('Add product', 'kaupang-stock'); ?></h3>
                        <?php self::renderProductPicker($poId, $supplierId); ?>
                        <p class="description"><?php \esc_html_e('Adding a product already on the order merges into that line (its quantity is increased).', 'kaupang-stock'); ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ks-po-lines">
                    <p class="description"><?php \esc_html_e('Save the draft first, then add product lines.', 'kaupang-stock'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($po !== null): ?>
            <?php self::renderReceiptHistory($poId); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $lines Lines::forPoWithReceived()
     */
    private static function renderLinesTable(int $poId, int $supplierId, array $lines, bool $editable): void {
        ?>
        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped ks-lines" data-ks-lines-table data-po="<?php echo (int) $poId; ?>">
            <thead>
                <tr>
                    <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Supplier product', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Supplier SKU', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Ordered', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Received', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Remaining', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Unit cost ex-VAT', 'kaupang-stock'); ?></th>
                    <?php if ($editable): ?><th class="ks-line-actions"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody data-ks-lines-body><?php self::lineRows($poId, $supplierId, $lines, $editable); ?></tbody>
        </table>
        </div>

        <?php if ($editable): ?>
            <!-- Inline edit form, revealed by the Edit buttons (progressive enhancement). -->
            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-line-edit" data-ks-line-edit hidden>
                <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_LINE); ?>" />
                <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
                <input type="hidden" name="line" value="0" data-ks-edit-line />
                <?php \wp_nonce_field(self::SAVE_LINE); ?>
                <label><?php \esc_html_e('Qty', 'kaupang-stock'); ?>
                    <input type="number" name="qty" step="1" min="1" class="small-text" data-ks-edit-qty required /></label>
                <label><?php \esc_html_e('Unit cost ex-VAT (kr)', 'kaupang-stock'); ?>
                    <input type="number" name="unit_cost" step="0.01" min="0" class="small-text" data-ks-edit-cost placeholder="—" /></label>
                <label><?php \esc_html_e('Supplier SKU', 'kaupang-stock'); ?>
                    <input type="text" name="supplier_sku" maxlength="100" data-ks-edit-supplier-sku /></label>
                <label><?php \esc_html_e('Supplier product name', 'kaupang-stock'); ?>
                    <input type="text" name="supplier_name" maxlength="200" class="regular-text" data-ks-edit-supplier-name /></label>
                <input type="hidden" name="supplier_identity_force" value="1" />
                <?php \submit_button(\__('Save line', 'kaupang-stock'), 'secondary', 'submit', false); ?>
                <button type="button" class="button-link" data-ks-edit-cancel><?php \esc_html_e('Cancel', 'kaupang-stock'); ?></button>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * The lines table's <tbody> rows (or the empty-state row). Extracted so the
     * async add/edit/remove REST routes can repaint the body in place with the same
     * markup the server renders — one source of truth for row formatting, supplier
     * identity lookups and the Edit/Remove affordances.
     *
     * @param array<int,array<string,mixed>> $lines Lines::forPoWithReceived()
     */
    private static function lineRows(int $poId, int $supplierId, array $lines, bool $editable): void {
        if ($lines === []) {
            ?>
            <tr><td colspan="<?php echo $editable ? 8 : 7; ?>"><?php \esc_html_e('No lines yet.', 'kaupang-stock'); ?></td></tr>
            <?php
            return;
        }
        foreach ($lines as $line):
            $lineId = (int) $line['id'];
            $cost   = isset($line['unit_cost_ore']) && $line['unit_cost_ore'] !== null ? (int) $line['unit_cost_ore'] : null;
            $supplierProduct = SupplierProducts::find($supplierId, (int) $line['product_id']);
            $supplierSku = (string) ($supplierProduct['supplier_sku'] ?? '');
            $supplierName = (string) ($supplierProduct['supplier_name'] ?? '');
            ?>
            <tr>
                <td><?php echo \esc_html((string) $line['product_label']); ?></td>
                <td><?php echo $supplierName !== '' ? \esc_html($supplierName) : '—'; ?></td>
                <td><code><?php echo $supplierSku !== '' ? \esc_html($supplierSku) : '—'; ?></code></td>
                <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $line['qty_ordered'])); ?></td>
                <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $line['received'])); ?></td>
                <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) $line['remaining'])); ?></td>
                <td class="ks-num"><?php echo $cost !== null ? \esc_html(self::money($cost) . ' kr') : '—'; ?></td>
                <?php if ($editable): ?>
                    <td class="ks-line-actions">
                        <button type="button" class="button-link ks-edit-line"
                                data-line="<?php echo (int) $lineId; ?>"
                                data-qty="<?php echo \esc_attr((string) (float) $line['qty_ordered']); ?>"
                                data-cost="<?php echo \esc_attr($cost !== null ? number_format($cost / 100, 2, '.', '') : ''); ?>"
                                data-supplier-sku="<?php echo \esc_attr($supplierSku); ?>"
                                data-supplier-name="<?php echo \esc_attr($supplierName); ?>">
                            <?php \esc_html_e('Edit', 'kaupang-stock'); ?>
                        </button>
                        &nbsp;|&nbsp;
                        <a href="<?php echo \esc_url(self::deleteLineUrl($poId, $lineId)); ?>" class="ks-danger ks-remove-line"
                           data-line="<?php echo (int) $lineId; ?>"
                           onclick="return confirm('<?php echo \esc_js(__('Remove this line?', 'kaupang-stock')); ?>');"><?php \esc_html_e('Remove', 'kaupang-stock'); ?></a>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach;
    }

    /** The lines <tbody> rows as a string (for the async REST repaint). */
    private static function lineRowsHtml(int $poId, int $supplierId, array $lines, bool $editable): string {
        ob_start();
        self::lineRows($poId, $supplierId, $lines, $editable);
        return (string) ob_get_clean();
    }

    /** Existing products for this PO's supplier, with stock context. */
    private static function renderSupplierCatalog(int $supplierId): void {
        if ($supplierId <= 0) {
            echo '<p class="description">' . \esc_html__('Choose a supplier to use its product catalog.', 'kaupang-stock') . '</p>';
            return;
        }
        $catalog  = SupplierProducts::forSupplier($supplierId);
        $incoming = PurchaseOrders::incomingPerProduct();
        ?>
        <h3><?php \esc_html_e('Add from supplier catalog', 'kaupang-stock'); ?></h3>
        <?php if ($catalog === []): ?>
            <p class="description"><?php \esc_html_e('The supplier catalog is empty. Add a product below and enter the supplier identity to start it.', 'kaupang-stock'); ?></p>
        <?php else: ?>
            <div class="ks-tablewrap">
            <table class="wp-list-table widefat striped ks-supplier-catalog">
                <thead><tr>
                    <th><?php \esc_html_e('Supplier product', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Supplier SKU', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Our product', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('On hand', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Incoming', 'kaupang-stock'); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($catalog as $row):
                    $productId = (int) $row['product_id'];
                    $balance   = Balances::row($productId);
                    $label     = ProductSearch::label($productId);
                    ?>
                    <tr>
                        <td><?php echo \esc_html((string) ($row['supplier_name'] ?: $label)); ?></td>
                        <td><code><?php echo \esc_html((string) ($row['supplier_sku'] ?? '')); ?></code></td>
                        <td><?php echo \esc_html($label); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) ($balance['on_hand'] ?? 0))); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty((float) ($incoming[$productId] ?? 0))); ?></td>
                        <td class="ks-num">
                            <button type="button" class="button button-small ks-pick-product"
                                    data-id="<?php echo $productId; ?>"
                                    data-label="<?php echo \esc_attr($label); ?>"
                                    data-supplier-sku="<?php echo \esc_attr((string) ($row['supplier_sku'] ?? '')); ?>"
                                    data-supplier-name="<?php echo \esc_attr((string) ($row['supplier_name'] ?? '')); ?>">
                                <?php \esc_html_e('Add', 'kaupang-stock'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif;
    }

    /**
     * Server-side product picker (§6.4: "server-side search box or simple SKU/id
     * entry"). No REST dependency — a GET reloads the editor with candidate rows
     * (ProductSearch::search), each of which fills the add-line form's product_id
     * on click. The box also accepts a raw SKU or numeric id resolved on submit,
     * so it works with JS off too.
     */
    private static function renderProductPicker(int $poId, int $supplierId): void {
        $term    = isset($_GET['pline_q']) ? \sanitize_text_field(\wp_unslash((string) $_GET['pline_q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $results = $term !== '' ? ProductSearch::search($term, 20) : [];
        ?>
        <form method="get" action="<?php echo \esc_url(\admin_url('admin.php')); ?>" class="ks-product-search">
            <input type="hidden" name="page" value="<?php echo \esc_attr(Menu::SLUG_PURCHASE); ?>" />
            <input type="hidden" name="view" value="edit" />
            <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
            <input type="search" name="pline_q" class="regular-text" value="<?php echo \esc_attr($term); ?>"
                   data-ks-product-search
                   placeholder="<?php \esc_attr_e('Search product by name or SKU…', 'kaupang-stock'); ?>" />
            <?php \submit_button(\__('Search', 'kaupang-stock'), 'secondary', 'submit', false); ?>
        </form>

        <?php // Empty-scope hint from the server path (no-JS); JS repaints the tbody below and drives the status line. ?>
        <p class="ks-picker-status" data-ks-picker-status aria-live="polite" <?php echo ($term !== '' && $results === []) ? '' : 'hidden'; ?>>
            <?php \esc_html_e('No products found.', 'kaupang-stock'); ?>
        </p>

        <?php // The results table shell is ALWAYS present so the async picker can repaint <tbody>; server rows below are the no-JS fallback. ?>
        <table class="wp-list-table widefat striped ks-picker-results" <?php echo $results === [] ? 'hidden' : ''; ?>>
            <tbody data-ks-picker-results>
                <?php foreach ($results as $r): ?>
                    <?php $identity = SupplierProducts::find($supplierId, (int) $r['id']); ?>
                    <tr>
                        <td><?php echo \esc_html((string) $r['title']); ?></td>
                        <td><code><?php echo \esc_html((string) $r['sku']); ?></code></td>
                        <td class="ks-num">
                            <button type="button" class="button button-small ks-pick-product"
                                    data-id="<?php echo (int) $r['id']; ?>"
                                    data-supplier-sku="<?php echo \esc_attr((string) ($identity['supplier_sku'] ?? '')); ?>"
                                    data-supplier-name="<?php echo \esc_attr((string) ($identity['supplier_name'] ?? '')); ?>"
                                    data-label="<?php echo \esc_attr(($r['sku'] !== '' ? $r['title'] . ' (' . $r['sku'] . ')' : $r['title'])); ?>">
                                <?php \esc_html_e('Add', 'kaupang-stock'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-add-line" data-ks-add-line>
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_LINE); ?>" />
            <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
            <input type="hidden" name="line" value="0" />
            <?php \wp_nonce_field(self::SAVE_LINE); ?>
            <input type="hidden" name="product_id" value="0" data-ks-add-product-id />
            <label><?php \esc_html_e('Product (SKU or id)', 'kaupang-stock'); ?>
                <input type="text" name="product_sku" class="regular-text" autocomplete="off" data-ks-add-product-sku
                       placeholder="<?php \esc_attr_e('Pick above, or type a SKU / product id', 'kaupang-stock'); ?>" /></label>
            <label><?php \esc_html_e('Qty', 'kaupang-stock'); ?>
                <input type="number" name="qty" step="1" min="1" value="1" class="small-text" required /></label>
            <label><?php \esc_html_e('Unit cost ex-VAT (kr)', 'kaupang-stock'); ?>
                <input type="number" name="unit_cost" step="0.01" min="0" class="small-text" placeholder="—" /></label>
            <label><?php \esc_html_e('Supplier SKU', 'kaupang-stock'); ?>
                <input type="text" name="supplier_sku" maxlength="100" data-ks-add-supplier-sku /></label>
            <label><?php \esc_html_e('Supplier product name', 'kaupang-stock'); ?>
                <input type="text" name="supplier_name" maxlength="200" class="regular-text" data-ks-add-supplier-name /></label>
            <?php \submit_button(\__('Add line', 'kaupang-stock'), 'primary', 'submit', false); ?>
            <span class="ks-line-feedback" role="status" aria-live="polite" data-ks-line-feedback></span>
        </form>
        <?php
    }

    private static function renderReceiptHistory(int $poId): void {
        $lines = Lines::forPo($poId);
        if ($lines === []) {
            return;
        }
        // Gather all receipt movements for this PO's lines, grouped by batch.
        $batches = [];
        foreach ($lines as $line) {
            foreach (Movements::forRef('po_line', (int) $line['id']) as $mv) {
                $batch = (string) ($mv['batch'] ?? '');
                if ($batch === '') {
                    $batch = 'mv-' . (int) $mv['id'];
                }
                $batches[$batch]['occurred'] = (string) $mv['occurred_at'];
                $batches[$batch]['rows'][]   = $mv;
            }
        }
        if ($batches === []) {
            return;
        }
        // Newest first.
        uasort($batches, static fn (array $a, array $b): int => strcmp($b['occurred'], $a['occurred']));
        ?>
        <h2><?php \esc_html_e('Receipts', 'kaupang-stock'); ?></h2>
        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php \esc_html_e('Received at', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Lines', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Total qty', 'kaupang-stock'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $batch => $data):
                    $rows  = $data['rows'];
                    $total = 0.0;
                    foreach ($rows as $r) {
                        $total += (float) $r['delta'];
                    }
                    $isRealBatch = strpos((string) $batch, 'mv-') !== 0;
                    ?>
                    <tr>
                        <td><?php echo \esc_html(PurchasingPage::localDateTime((string) $data['occurred'])); ?></td>
                        <td><?php echo (int) count($rows); ?></td>
                        <td class="ks-num"><?php echo \esc_html(PurchasingPage::qty($total)); ?></td>
                        <td>
                            <?php if ($isRealBatch): ?>
                                <a href="<?php echo \esc_url(PurchasingPage::movesUrl(['batch' => (string) $batch])); ?>"><?php \esc_html_e('View in movements', 'kaupang-stock'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /* -------------------------------- Handlers ------------------------------- */

    public static function handleSavePo(): void {
        Screen::guard(self::SAVE_PO);
        $poId = (int) ($_POST['po'] ?? 0);
        $data = [
            'supplier_id'  => (int) ($_POST['supplier_id'] ?? 0),
            'supplier_ref' => (string) \wp_unslash($_POST['supplier_ref'] ?? ''),
            'eta'          => (string) \wp_unslash($_POST['eta'] ?? ''),
            'note'         => (string) \wp_unslash($_POST['note'] ?? ''),
        ];
        if ($poId > 0) {
            $po = PurchaseOrders::find($poId);
            if ($po === null) {
                Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'list', 'ks_err' => 'notfound']);
            }
            // After ordering, header note edits should append to the trail rather
            // than clobber the locked context; supplier/ref/eta stay editable.
            PurchaseOrders::updateHeader($poId, $data);
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_msg' => 'saved']);
        }
        try {
            $newId = PurchaseOrders::create($data);
        } catch (\Throwable $e) {
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'list', 'ks_err' => 'save']);
        }
        Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $newId, 'ks_msg' => 'created']);
    }

    public static function handleOrderPo(): void {
        Screen::guard(self::ORDER_PO);
        $poId = (int) ($_POST['po'] ?? 0);
        if ($poId > 0 && PurchaseOrders::isDraft($poId) && Lines::forPo($poId) !== []) {
            PurchaseOrders::order($poId);
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_msg' => 'ordered']);
        }
        Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_err' => 'order']);
    }

    public static function handleCancelPo(): void {
        Screen::guard(self::CANCEL_PO);
        $poId = (int) ($_POST['po'] ?? 0);
        if ($poId > 0) {
            PurchaseOrders::cancel($poId);
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_msg' => 'cancelled']);
        }
        Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'list']);
    }

    public static function handleSaveLine(): void {
        Screen::guard(self::SAVE_LINE);
        $poId   = (int) ($_POST['po'] ?? 0);
        $lineId = (int) ($_POST['line'] ?? 0);
        $qty    = (float) \str_replace(',', '.', (string) ($_POST['qty'] ?? '0'));
        $cost   = self::parseCostOre((string) ($_POST['unit_cost'] ?? ''));

        $po = $poId > 0 ? PurchaseOrders::find($poId) : null;
        if ($po === null) {
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'list', 'ks_err' => 'notfound']);
        }

        // Locked-PO edits are refused; a change note is appended to the trail instead.
        if (!PurchaseOrders::isDraft($poId)) {
            $label = $lineId > 0 && ($line = Lines::find($lineId)) ? ProductSearch::label((int) $line['product_id']) : '';
            PurchaseOrders::appendNote($poId, sprintf(
                /* translators: 1: product label, 2: requested qty */
                __('Change after ordering refused: %1$s → %2$s (lines are locked).', 'kaupang-stock'),
                $label !== '' ? $label : ('#' . $lineId),
                PurchasingPage::qty($qty)
            ));
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_err' => 'locked']);
        }

        try {
            if ($lineId > 0) {
                $line = Lines::find($lineId);
                if ($line === null || (int) $line['po_id'] !== $poId) {
                    Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_err' => 'line']);
                }
                Lines::edit($lineId, $qty, $cost, null);
                self::saveSupplierIdentity(
                    (int) ($po['supplier_id'] ?? 0),
                    (int) $line['product_id'],
                    !empty($_POST['supplier_identity_force'])
                );
                Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_msg' => 'line_saved']);
            }
            $productId = self::resolveProductId(
                (int) ($_POST['product_id'] ?? 0),
                (string) \wp_unslash($_POST['product_sku'] ?? '')
            );
            if ($productId <= 0) {
                Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_err' => 'noproduct']);
            }
            [, $merged] = Lines::add($poId, $productId, $qty, $cost, null);
            self::saveSupplierIdentity((int) ($po['supplier_id'] ?? 0), $productId, false);
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_msg' => $merged ? 'line_merged' : 'line_added']);
        } catch (\Throwable $e) {
            Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_err' => 'line']);
        }
    }

    public static function handleDeleteLine(): void {
        Screen::guard(self::DELETE_LINE, 'GET');
        $poId   = (int) ($_GET['po'] ?? 0);
        $lineId = (int) ($_GET['line'] ?? 0);
        if ($poId > 0 && $lineId > 0 && PurchaseOrders::isDraft($poId)) {
            $line = Lines::find($lineId);
            if ($line !== null && (int) $line['po_id'] === $poId) {
                Lines::delete($lineId);
                Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_msg' => 'line_deleted']);
            }
        }
        Screen::redirect(Menu::SLUG_PURCHASE, ['view' => 'edit', 'po' => $poId, 'ks_err' => 'line']);
    }

    /* ------------------------------ REST: lines ------------------------------ */

    /**
     * Async line editing for the draft PO — the JS path that repaints the lines
     * table in place instead of a full POST+redirect. The admin-post handlers above
     * stay as the no-JS fallback; both share the same Lines / SupplierProducts logic
     * and the same lineRows() markup. Draft-only: locked POs return 409 (lines are
     * immutable once ordered — the note trail is the audit path there).
     *
     * The routes themselves are registered from Rest\Controller (which is always
     * booted) — this page's register() runs only under is_admin(), and REST is not
     * admin context. These callbacks live here because they lean on the private line
     * helpers and lineRows() rendering.
     */
    public static function restAddLine(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $poId = (int) $req['po'];
        $po   = self::draftForRest($poId);
        if ($po instanceof \WP_Error) {
            return $po;
        }
        $body      = self::restBody($req);
        $qty       = (float) \str_replace(',', '.', (string) ($body['qty'] ?? '0'));
        $cost      = self::parseCostOre((string) ($body['unit_cost'] ?? ''));
        $productId = self::resolveProductId((int) ($body['product_id'] ?? 0), (string) ($body['product_sku'] ?? ''));
        if ($productId <= 0) {
            return self::restLineError(\__('Pick a product, or enter a valid SKU / product id.', 'kaupang-stock'));
        }
        try {
            [, $merged] = Lines::add($poId, $productId, $qty, $cost, null);
            self::saveSupplierIdentityValues(
                (int) ($po['supplier_id'] ?? 0),
                $productId,
                (string) ($body['supplier_sku'] ?? ''),
                (string) ($body['supplier_name'] ?? ''),
                false
            );
        } catch (\InvalidArgumentException $e) {
            return self::restLineError(\__('Enter a quantity of at least 1.', 'kaupang-stock'));
        } catch (\Throwable $e) {
            return self::restLineError(\__('The line could not be saved.', 'kaupang-stock'));
        }
        return self::restLinesResponse(
            $poId,
            $merged ? \__('Merged into the existing line.', 'kaupang-stock') : \__('Line added.', 'kaupang-stock'),
            $merged
        );
    }

    public static function restEditLine(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $poId = (int) $req['po'];
        $po   = self::draftForRest($poId);
        if ($po instanceof \WP_Error) {
            return $po;
        }
        $lineId = (int) $req['line'];
        $line   = Lines::find($lineId);
        if ($line === null || (int) $line['po_id'] !== $poId) {
            return self::restLineError(\__('That line was not found on this order.', 'kaupang-stock'), 404);
        }
        $body = self::restBody($req);
        $qty  = (float) \str_replace(',', '.', (string) ($body['qty'] ?? '0'));
        $cost = self::parseCostOre((string) ($body['unit_cost'] ?? ''));
        try {
            Lines::edit($lineId, $qty, $cost, null);
            // Edit form always forces identity (the operator sees and owns both fields).
            self::saveSupplierIdentityValues(
                (int) ($po['supplier_id'] ?? 0),
                (int) $line['product_id'],
                (string) ($body['supplier_sku'] ?? ''),
                (string) ($body['supplier_name'] ?? ''),
                true
            );
        } catch (\InvalidArgumentException $e) {
            return self::restLineError(\__('Enter a quantity of at least 1.', 'kaupang-stock'));
        } catch (\Throwable $e) {
            return self::restLineError(\__('The line could not be saved.', 'kaupang-stock'));
        }
        return self::restLinesResponse($poId, \__('Line saved.', 'kaupang-stock'), false);
    }

    public static function restDeleteLine(\WP_REST_Request $req): \WP_REST_Response|\WP_Error {
        $poId = (int) $req['po'];
        $po   = self::draftForRest($poId);
        if ($po instanceof \WP_Error) {
            return $po;
        }
        $lineId = (int) $req['line'];
        $line   = Lines::find($lineId);
        if ($line === null || (int) $line['po_id'] !== $poId) {
            return self::restLineError(\__('That line was not found on this order.', 'kaupang-stock'), 404);
        }
        Lines::delete($lineId);
        return self::restLinesResponse($poId, \__('Line removed.', 'kaupang-stock'), false);
    }

    /** PO must exist and still be a draft, with purchasing enabled. */
    private static function draftForRest(int $poId): array|\WP_Error {
        if (!Settings::get('po_enabled')) {
            return new \WP_Error('po_disabled', \__('Purchasing is not enabled.', 'kaupang-stock'), ['status' => 403]);
        }
        $po = $poId > 0 ? PurchaseOrders::find($poId) : null;
        if ($po === null) {
            return new \WP_Error('po_notfound', \__('Purchase order not found.', 'kaupang-stock'), ['status' => 404]);
        }
        if (!PurchaseOrders::isDraft($poId)) {
            return new \WP_Error('po_locked', \__('This order is placed — its lines are locked.', 'kaupang-stock'), ['status' => 409]);
        }
        return $po;
    }

    /** @return array<string,mixed> */
    private static function restBody(\WP_REST_Request $req): array {
        $body = $req->get_json_params();
        return is_array($body) ? $body : [];
    }

    private static function restLineError(string $message, int $status = 400): \WP_REST_Response {
        return new \WP_REST_Response(['status' => 'error', 'message' => $message], $status);
    }

    /** Success envelope: the repainted <tbody> rows for the whole PO plus a message. */
    private static function restLinesResponse(int $poId, string $message, bool $merged): \WP_REST_Response {
        $po    = PurchaseOrders::find($poId);
        $supId = (int) ($po['supplier_id'] ?? 0);
        $lines = Lines::forPoWithReceived($poId);
        return new \WP_REST_Response([
            'status'     => 'ok',
            'merged'     => $merged,
            'message'    => $message,
            'lines_html' => self::lineRowsHtml($poId, $supId, $lines, true),
        ], 200);
    }

    /* --------------------------------- Helpers ------------------------------- */

    private static function supplierSelect(int $current): void {
        $suppliers = Suppliers::all();
        echo '<select id="ks-supplier" name="supplier_id">';
        echo '<option value="0">' . \esc_html__('— none —', 'kaupang-stock') . '</option>';
        foreach ($suppliers as $s) {
            $sid = (int) $s['id'];
            // Keep an inactive-but-selected supplier visible on an existing PO.
            if (empty($s['active']) && $sid !== $current) {
                continue;
            }
            $label = (string) $s['name'] . (empty($s['active']) ? ' (' . \__('inactive', 'kaupang-stock') . ')' : '');
            printf('<option value="%d"%s>%s</option>', $sid, \selected($current, $sid, false), \esc_html($label));
        }
        echo '</select>';
    }

    private static function deleteLineUrl(int $poId, int $lineId): string {
        return \wp_nonce_url(
            \add_query_arg(['action' => self::DELETE_LINE, 'po' => $poId, 'line' => $lineId], \admin_url('admin-post.php')),
            self::DELETE_LINE
        );
    }

    /** kr input → integer øre, or null when blank. */
    private static function parseCostOre(string $raw): ?int {
        $raw = trim(\str_replace(',', '.', $raw));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        return (int) round(((float) $raw) * 100);
    }

    /**
     * Resolve the product for a new line from the JS-picked id or the raw box:
     * a positive picked id wins; otherwise a numeric string is treated as a
     * product id (validated) and anything else as an exact SKU. Returns 0 when
     * nothing resolves. Keeps the ledger's "product_id is a real product" contract.
     */
    private static function resolveProductId(int $picked, string $raw): int {
        if ($picked > 0 && self::isProductPost($picked)) {
            return $picked;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            $id = (int) $raw;
            if ($id > 0 && self::isProductPost($id)) {
                return $id;
            }
        }
        $bySku = ProductSearch::bySku($raw);
        return $bySku !== null ? $bySku : 0;
    }

    /** Persist inline supplier identity without letting a blank generic pick erase an existing mapping. */
    private static function saveSupplierIdentity(int $supplierId, int $productId, bool $force): void {
        self::saveSupplierIdentityValues(
            $supplierId,
            $productId,
            (string) \wp_unslash($_POST['supplier_sku'] ?? ''),
            (string) \wp_unslash($_POST['supplier_name'] ?? ''),
            $force
        );
    }

    /** Same guard, but with the SKU/name passed explicitly (the REST path has no $_POST). */
    private static function saveSupplierIdentityValues(int $supplierId, int $productId, string $sku, string $name, bool $force): void {
        if ($supplierId <= 0 || $productId <= 0) {
            return;
        }
        if (!$force && trim($sku) === '' && trim($name) === '' && SupplierProducts::find($supplierId, $productId) !== null) {
            return;
        }
        SupplierProducts::save($supplierId, $productId, $sku, $name);
    }

    /** True when the id is a (non-trashed) product or variation post. */
    private static function isProductPost(int $id): bool {
        $type = \get_post_type($id);
        if ($type !== 'product' && $type !== 'product_variation') {
            return false;
        }
        $status = \get_post_status($id);
        return $status !== false && $status !== 'trash' && $status !== 'auto-draft';
    }

    private static function money(int $ore): string {
        return \number_format_i18n($ore / 100, 2);
    }
}
