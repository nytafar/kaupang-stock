<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * "Lager → Innkjøp" (§6.4) — purchase orders, receiving, the inbound view and the
 * supplier registry, all under one page routed by ?view=:
 *
 *   list (default) · edit (?po=<id> | new) · receive (?po=<id>&view=receive) ·
 *   inbound · suppliers
 *
 * Mutations go through admin-post with nonce + capability checks (the structured,
 * nested nature of PO lines makes the Settings API a poor fit — the wholesale
 * GroupsPage pattern). The receive flow is the one exception: its grid POSTs to
 * the kaupang-stock/v1 REST `receive` route (owned by the REST controller) so the
 * client-minted token round-trips as batch id + idempotency key. Suppliers get an
 * optional BRREG "Slå opp" lookup via a separate admin-post round-trip when
 * kaupang-brreg is active (soft dep).
 *
 * Owned-write gating: when NOT in active mode the receive UI is disabled with an
 * explanatory notice (receipts write stock). PO drafting/ordering and the supplier
 * registry work in any mode — only posting goods needs active mode.
 */
final class PurchasingPage {

    private const SAVE_PO       = 'kaupang_stock_save_po';
    private const ORDER_PO      = 'kaupang_stock_order_po';
    private const CANCEL_PO     = 'kaupang_stock_cancel_po';
    private const SAVE_LINE     = 'kaupang_stock_save_po_line';
    private const DELETE_LINE   = 'kaupang_stock_delete_po_line';
    private const SAVE_SUPPLIER = 'kaupang_stock_save_supplier';
    private const TOGGLE_SUPP   = 'kaupang_stock_toggle_supplier';
    private const BRREG_LOOKUP  = 'kaupang_stock_supplier_brreg';

    public static function register(): void {
        \add_action('admin_enqueue_scripts', [self::class, 'assets']);

        \add_action('admin_post_' . self::SAVE_PO, [self::class, 'handleSavePo']);
        \add_action('admin_post_' . self::ORDER_PO, [self::class, 'handleOrderPo']);
        \add_action('admin_post_' . self::CANCEL_PO, [self::class, 'handleCancelPo']);
        \add_action('admin_post_' . self::SAVE_LINE, [self::class, 'handleSaveLine']);
        \add_action('admin_post_' . self::DELETE_LINE, [self::class, 'handleDeleteLine']);
        \add_action('admin_post_' . self::SAVE_SUPPLIER, [self::class, 'handleSaveSupplier']);
        \add_action('admin_post_' . self::TOGGLE_SUPP, [self::class, 'handleToggleSupplier']);
        \add_action('admin_post_' . self::BRREG_LOOKUP, [self::class, 'handleBrregLookup']);
    }

    /** Enqueue purchasing assets on this screen only, on top of the shared handles. */
    public static function assets(string $hook): void {
        $page = isset($_GET['page']) ? \sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ($page !== Menu::SLUG_PURCHASE) {
            return;
        }
        \wp_enqueue_style(
            'kaupang-stock-purchasing',
            KAUPANG_STOCK_URL . 'assets/purchasing.css',
            [Menu::STYLE_HANDLE],
            KAUPANG_STOCK_VERSION
        );
        \wp_enqueue_script(
            'kaupang-stock-purchasing',
            KAUPANG_STOCK_URL . 'assets/purchasing.js',
            [Menu::SCRIPT_HANDLE],
            KAUPANG_STOCK_VERSION,
            true
        );
        \wp_localize_script('kaupang-stock-purchasing', 'KaupangStockPurchasing', [
            'i18n' => [
                'confirmTitle'   => \__('Confirm over-receipt', 'kaupang-stock'),
                'confirmIntro'   => \__('These lines exceed the remaining quantity. Receiving anyway is allowed (supplier overs are normal):', 'kaupang-stock'),
                'confirmReceive' => \__('Receive anyway', 'kaupang-stock'),
                'cancel'         => \__('Cancel', 'kaupang-stock'),
                'received'       => \__('Goods received.', 'kaupang-stock'),
                'genericError'   => \__('Something went wrong. Please try again.', 'kaupang-stock'),
                'ordered'        => \__('Ordered', 'kaupang-stock'),
                'alreadyGot'     => \__('Received', 'kaupang-stock'),
                'remaining'      => \__('Remaining', 'kaupang-stock'),
                'entered'        => \__('Entered', 'kaupang-stock'),
                'nothingToReceive' => \__('Enter a quantity on at least one line.', 'kaupang-stock'),
            ],
        ]);
    }

    /* --------------------------------- Router -------------------------------- */

    public static function render(): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }
        if (!Settings::enabled() || !Settings::get('po_enabled')) {
            self::renderDisabled();
            return;
        }

        $view = isset($_GET['view']) ? \sanitize_key((string) $_GET['view']) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification

        echo '<div class="wrap ks-wrap ks-purchasing">';
        self::notices();

        switch ($view) {
            case 'edit':
                self::renderEdit();
                break;
            case 'receive':
                self::renderReceive();
                break;
            case 'inbound':
                self::renderInbound();
                break;
            case 'suppliers':
                self::renderSuppliers();
                break;
            default:
                self::renderList();
        }

        echo '</div>';
    }

    private static function renderDisabled(): void {
        echo '<div class="wrap ks-wrap"><h1>' . \esc_html__('Purchasing', 'kaupang-stock') . '</h1>';
        echo '<div class="notice notice-info inline"><p>'
            . \esc_html__('Purchasing (Innkjøp) is not enabled. Turn it on under Lager → Innstillinger.', 'kaupang-stock')
            . '</p></div></div>';
    }

    /* ---------------------------------- List --------------------------------- */

    private static function renderList(): void {
        $pos = PurchaseOrders::all();
        ?>
        <h1 class="wp-heading-inline"><?php echo \esc_html('Innkjøp'); ?></h1>
        <a href="<?php echo \esc_url(self::url(['view' => 'edit', 'po' => 'new'])); ?>" class="page-title-action"><?php \esc_html_e('New purchase order', 'kaupang-stock'); ?></a>
        <a href="<?php echo \esc_url(self::url(['view' => 'inbound'])); ?>" class="page-title-action"><?php echo \esc_html('Innkommende'); ?></a>
        <a href="<?php echo \esc_url(self::url(['view' => 'suppliers'])); ?>" class="page-title-action"><?php echo \esc_html('Leverandører'); ?></a>
        <hr class="wp-header-end" />

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php \esc_html_e('PO', 'kaupang-stock'); ?></th>
                    <th><?php echo \esc_html('Leverandør'); ?></th>
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
                    $editUrl = self::url(['view' => 'edit', 'po' => $poId]);
                    ?>
                    <tr>
                        <td><a href="<?php echo \esc_url($editUrl); ?>"><strong>#<?php echo (int) $poId; ?></strong></a></td>
                        <td><?php echo \esc_html(Suppliers::name(isset($po['supplier_id']) ? (int) $po['supplier_id'] : null)); ?></td>
                        <td><?php self::statusChip($status); ?></td>
                        <td><?php echo (int) count($lines); ?></td>
                        <td><?php echo \esc_html(self::localDate((string) ($po['eta'] ?? ''))); ?></td>
                        <td><?php echo \esc_html((string) ($po['supplier_ref'] ?? '')); ?></td>
                        <td>
                            <a href="<?php echo \esc_url($editUrl); ?>"><?php \esc_html_e('Open', 'kaupang-stock'); ?></a>
                            <?php if ($status === PurchaseOrders::STATUS_ORDERED || $status === PurchaseOrders::STATUS_PARTIAL): ?>
                                &nbsp;|&nbsp;<a href="<?php echo \esc_url(self::url(['view' => 'receive', 'po' => $poId])); ?>"><?php echo \esc_html('Motta varer'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* ----------------------------------- Edit -------------------------------- */

    private static function renderEdit(): void {
        $isNew = (isset($_GET['po']) && (string) $_GET['po'] === 'new'); // phpcs:ignore WordPress.Security.NonceVerification
        $poId  = $isNew ? 0 : (int) ($_GET['po'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        $po    = $poId > 0 ? PurchaseOrders::find($poId) : null;

        if ($poId > 0 && $po === null) {
            echo '<div class="notice notice-error"><p>' . \esc_html__('Purchase order not found.', 'kaupang-stock') . '</p></div>';
            return;
        }

        $lines    = $po !== null ? Lines::forPoWithReceived($poId) : [];
        $status   = $po !== null ? PurchaseOrders::derivedStatus($po, $lines) : PurchaseOrders::STATUS_DRAFT;
        $locked   = $po !== null && $status !== PurchaseOrders::STATUS_DRAFT && $status !== PurchaseOrders::STATUS_CANCELLED;
        $isDraft  = $status === PurchaseOrders::STATUS_DRAFT;
        $isClosed = $status === PurchaseOrders::STATUS_CANCELLED || $status === PurchaseOrders::STATUS_RECEIVED;
        ?>
        <h1 class="wp-heading-inline">
            <?php echo $po === null ? \esc_html__('New purchase order', 'kaupang-stock') : \esc_html(sprintf('Innkjøp #%d', $poId)); ?>
        </h1>
        <?php if ($po !== null): ?>
            <span class="ks-inline-status"><?php self::statusChip($status); ?></span>
        <?php endif; ?>
        <a href="<?php echo \esc_url(self::url(['view' => 'list'])); ?>" class="page-title-action"><?php \esc_html_e('Back to list', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />

        <div class="ks-po-grid">
            <div class="ks-po-header">
                <h2><?php echo \esc_html('Detaljer'); ?></h2>
                <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_PO); ?>" />
                    <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
                    <?php \wp_nonce_field(self::SAVE_PO); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ks-supplier"><?php echo \esc_html('Leverandør'); ?></label></th>
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
                    <a href="<?php echo \esc_url(self::url(['view' => 'receive', 'po' => $poId])); ?>" class="button button-primary"><?php echo \esc_html('Motta varer'); ?></a>
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
                    <?php self::renderLinesTable($poId, $lines, $isDraft); ?>

                    <?php if ($isDraft): ?>
                        <h3><?php \esc_html_e('Add product', 'kaupang-stock'); ?></h3>
                        <?php self::renderProductPicker($poId); ?>
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
    private static function renderLinesTable(int $poId, array $lines, bool $editable): void {
        ?>
        <table class="wp-list-table widefat striped ks-lines">
            <thead>
                <tr>
                    <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Ordered', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Received', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Remaining', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Unit cost ex-VAT', 'kaupang-stock'); ?></th>
                    <?php if ($editable): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($lines === []): ?>
                    <tr><td colspan="<?php echo $editable ? 6 : 5; ?>"><?php \esc_html_e('No lines yet.', 'kaupang-stock'); ?></td></tr>
                <?php else: foreach ($lines as $line):
                    $lineId = (int) $line['id'];
                    $cost   = isset($line['unit_cost_ore']) && $line['unit_cost_ore'] !== null ? (int) $line['unit_cost_ore'] : null;
                    ?>
                    <tr>
                        <td><?php echo \esc_html((string) $line['product_label']); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty((float) $line['qty_ordered'])); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty((float) $line['received'])); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty((float) $line['remaining'])); ?></td>
                        <td class="ks-num"><?php echo $cost !== null ? \esc_html(self::money($cost) . ' kr') : '—'; ?></td>
                        <?php if ($editable): ?>
                            <td class="ks-line-actions">
                                <button type="button" class="button-link ks-edit-line"
                                        data-line="<?php echo (int) $lineId; ?>"
                                        data-qty="<?php echo \esc_attr((string) (float) $line['qty_ordered']); ?>"
                                        data-cost="<?php echo \esc_attr($cost !== null ? number_format($cost / 100, 2, '.', '') : ''); ?>">
                                    <?php \esc_html_e('Edit', 'kaupang-stock'); ?>
                                </button>
                                &nbsp;|&nbsp;
                                <a href="<?php echo \esc_url(self::deleteLineUrl($poId, $lineId)); ?>" class="ks-danger"
                                   onclick="return confirm('<?php echo \esc_js(__('Remove this line?', 'kaupang-stock')); ?>');"><?php \esc_html_e('Remove', 'kaupang-stock'); ?></a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($editable && $lines !== []): ?>
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
                <?php \submit_button(\__('Save line', 'kaupang-stock'), 'secondary', 'submit', false); ?>
                <button type="button" class="button-link" data-ks-edit-cancel><?php \esc_html_e('Cancel', 'kaupang-stock'); ?></button>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * Server-side product picker (§6.4: "server-side search box or simple SKU/id
     * entry"). No REST dependency — a GET reloads the editor with candidate rows
     * (ProductSearch::search), each of which fills the add-line form's product_id
     * on click. The box also accepts a raw SKU or numeric id resolved on submit,
     * so it works with JS off too.
     */
    private static function renderProductPicker(int $poId): void {
        $term    = isset($_GET['pline_q']) ? \sanitize_text_field(\wp_unslash((string) $_GET['pline_q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $results = $term !== '' ? ProductSearch::search($term, 20) : [];
        ?>
        <form method="get" action="<?php echo \esc_url(\admin_url('admin.php')); ?>" class="ks-product-search">
            <input type="hidden" name="page" value="<?php echo \esc_attr(Menu::SLUG_PURCHASE); ?>" />
            <input type="hidden" name="view" value="edit" />
            <input type="hidden" name="po" value="<?php echo (int) $poId; ?>" />
            <input type="search" name="pline_q" class="regular-text" value="<?php echo \esc_attr($term); ?>"
                   placeholder="<?php \esc_attr_e('Search product by name or SKU…', 'kaupang-stock'); ?>" />
            <?php \submit_button(\__('Search', 'kaupang-stock'), 'secondary', 'submit', false); ?>
        </form>

        <?php if ($term !== ''): ?>
            <?php if ($results === []): ?>
                <p class="description"><?php \esc_html_e('No products found.', 'kaupang-stock'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat striped ks-picker-results">
                    <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><?php echo \esc_html((string) $r['title']); ?></td>
                                <td><code><?php echo \esc_html((string) $r['sku']); ?></code></td>
                                <td class="ks-num">
                                    <button type="button" class="button button-small ks-pick-product"
                                            data-id="<?php echo (int) $r['id']; ?>"
                                            data-label="<?php echo \esc_attr(($r['sku'] !== '' ? $r['title'] . ' (' . $r['sku'] . ')' : $r['title'])); ?>">
                                        <?php \esc_html_e('Add', 'kaupang-stock'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

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
            <?php \submit_button(\__('Add line', 'kaupang-stock'), 'primary', 'submit', false); ?>
        </form>
        <?php
    }

    /* --------------------------------- Receive ------------------------------- */

    private static function renderReceive(): void {
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
        <h1 class="wp-heading-inline"><?php echo \esc_html(sprintf('Motta varer — innkjøp #%d', $poId)); ?></h1>
        <a href="<?php echo \esc_url(self::url(['view' => 'edit', 'po' => $poId])); ?>" class="page-title-action"><?php \esc_html_e('Back to order', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />

        <?php if (!$open): ?>
            <div class="notice notice-warning inline"><p>
                <?php \esc_html_e('This purchase order is not open for receiving.', 'kaupang-stock'); ?>
            </p></div>
            <?php return; ?>
        <?php endif; ?>

        <?php if (!Settings::activeMode()): ?>
            <div class="notice notice-warning inline"><p>
                <?php \esc_html_e('Receiving writes stock and requires active mode. It is disabled while the ledger runs in shadow mode — enable active mode under Lager → Innstillinger.', 'kaupang-stock'); ?>
            </p></div>
        <?php endif; ?>

        <form class="ks-receive"
              data-ks-receive
              data-po="<?php echo (int) $poId; ?>"
              data-token="<?php echo \esc_attr($token); ?>"
              <?php echo Settings::activeMode() ? '' : 'data-disabled="1"'; ?>>
            <p class="description"><?php \esc_html_e('Quantities are pre-filled with what remains. Adjust for a partial delivery, then confirm. The server re-checks each remaining quantity at submit.', 'kaupang-stock'); ?></p>

            <table class="wp-list-table widefat striped ks-receive-grid">
                <thead>
                    <tr>
                        <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Ordered', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Received', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Remaining', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Receive now', 'kaupang-stock'); ?></th>
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
                            <td class="ks-num"><?php echo \esc_html(self::qty((float) $line['qty_ordered'])); ?></td>
                            <td class="ks-num"><?php echo \esc_html(self::qty((float) $line['received'])); ?></td>
                            <td class="ks-num ks-remaining"><?php echo \esc_html(self::qty($remaining)); ?></td>
                            <td class="ks-num">
                                <input type="number" step="1" min="0" class="small-text ks-receive-qty"
                                       data-line="<?php echo (int) $lineId; ?>"
                                       value="<?php echo \esc_attr(self::inputQty($remaining)); ?>"
                                       <?php echo Settings::activeMode() ? '' : 'disabled'; ?> />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <table class="form-table" role="presentation">
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
                        <td><?php echo \esc_html(self::localDateTime((string) $data['occurred'])); ?></td>
                        <td><?php echo (int) count($rows); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty($total)); ?></td>
                        <td>
                            <?php if ($isRealBatch): ?>
                                <a href="<?php echo \esc_url(self::movesUrl(['batch' => (string) $batch])); ?>"><?php \esc_html_e('View in movements', 'kaupang-stock'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* --------------------------------- Inbound ------------------------------- */

    private static function renderInbound(): void {
        $rows = PurchaseOrders::inboundLines();
        ?>
        <h1 class="wp-heading-inline"><?php echo \esc_html('Innkommende'); ?></h1>
        <a href="<?php echo \esc_url(self::url(['view' => 'list'])); ?>" class="page-title-action"><?php \esc_html_e('Back to list', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />
        <p class="description"><?php \esc_html_e('Every open purchase-order line across all orders — the single "what is coming" answer.', 'kaupang-stock'); ?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Ordered', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Received', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Remaining', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('ETA', 'kaupang-stock'); ?></th>
                    <th><?php echo \esc_html('Leverandør'); ?></th>
                    <th><?php \esc_html_e('PO', 'kaupang-stock'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><?php \esc_html_e('Nothing incoming.', 'kaupang-stock'); ?></td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo \esc_html((string) $row['product_label']); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty((float) $row['ordered'])); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty((float) $row['received'])); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty((float) $row['remaining'])); ?></td>
                        <td><?php echo \esc_html(self::localDate((string) $row['eta'])); ?></td>
                        <td><?php echo \esc_html(Suppliers::name((int) $row['supplier_id'])); ?></td>
                        <td><a href="<?php echo \esc_url(self::url(['view' => 'edit', 'po' => (int) $row['po_id']])); ?>">#<?php echo (int) $row['po_id']; ?></a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* -------------------------------- Suppliers ------------------------------ */

    private static function renderSuppliers(): void {
        $suppliers = Suppliers::all();
        $editId    = isset($_GET['supplier']) ? (int) $_GET['supplier'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $current   = $editId > 0 ? Suppliers::find($editId) : null;

        // A BRREG lookup round-trip may have stashed a name/attribution to prefill.
        $lookup = \get_transient('kaupang_stock_brreg_prefill_' . \get_current_user_id());
        if (is_array($lookup)) {
            \delete_transient('kaupang_stock_brreg_prefill_' . \get_current_user_id());
        } else {
            $lookup = null;
        }
        $prefillName = is_array($lookup) ? (string) ($lookup['name'] ?? '') : '';
        $prefillOrg  = is_array($lookup) ? (string) ($lookup['orgnr'] ?? '') : '';
        ?>
        <h1 class="wp-heading-inline"><?php echo \esc_html('Leverandører'); ?></h1>
        <a href="<?php echo \esc_url(self::url(['view' => 'list'])); ?>" class="page-title-action"><?php \esc_html_e('Back to list', 'kaupang-stock'); ?></a>
        <hr class="wp-header-end" />

        <table class="wp-list-table widefat fixed striped" style="max-width:60em">
            <thead>
                <tr>
                    <th><?php \esc_html_e('Name', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Org. nr', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Email', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Phone', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Active', 'kaupang-stock'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($suppliers === []): ?>
                    <tr><td colspan="6"><?php \esc_html_e('No suppliers yet.', 'kaupang-stock'); ?></td></tr>
                <?php else: foreach ($suppliers as $s):
                    $sid = (int) $s['id'];
                    ?>
                    <tr>
                        <td><strong><?php echo \esc_html((string) $s['name']); ?></strong></td>
                        <td><?php echo \esc_html((string) ($s['org_nr'] ?? '')); ?></td>
                        <td><?php echo \esc_html((string) ($s['email'] ?? '')); ?></td>
                        <td><?php echo \esc_html((string) ($s['phone'] ?? '')); ?></td>
                        <td><?php echo !empty($s['active']) ? '✓' : '—'; ?></td>
                        <td>
                            <a href="<?php echo \esc_url(self::url(['view' => 'suppliers', 'supplier' => $sid])); ?>"><?php \esc_html_e('Edit', 'kaupang-stock'); ?></a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo \esc_url(self::toggleSupplierUrl($sid, empty($s['active']))); ?>">
                                <?php echo !empty($s['active']) ? \esc_html__('Deactivate', 'kaupang-stock') : \esc_html__('Activate', 'kaupang-stock'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top:1.5em"><?php echo $current ? \esc_html__('Edit supplier', 'kaupang-stock') : \esc_html__('New supplier', 'kaupang-stock'); ?></h2>

        <?php if (Suppliers::brregAvailable()): ?>
            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-brreg-lookup">
                <input type="hidden" name="action" value="<?php echo \esc_attr(self::BRREG_LOOKUP); ?>" />
                <input type="hidden" name="supplier" value="<?php echo (int) $editId; ?>" />
                <?php \wp_nonce_field(self::BRREG_LOOKUP); ?>
                <label><?php \esc_html_e('Org. nr lookup', 'kaupang-stock'); ?>
                    <input type="text" name="lookup_orgnr" inputmode="numeric" maxlength="11" value="<?php echo \esc_attr($prefillOrg); ?>" />
                </label>
                <?php \submit_button(\__('Slå opp', 'kaupang-stock'), 'secondary', 'submit', false); ?>
                <span class="description"><?php echo \esc_html(sprintf(__('Looks the company up in %s and fills the name.', 'kaupang-stock'), \Kaupang\Brreg\Client::SOURCE)); ?></span>
            </form>
            <?php if (is_array($lookup) && $prefillName !== ''): ?>
                <p class="ks-brreg-attrib">
                    <?php echo \esc_html(sprintf(__('Filled from %s.', 'kaupang-stock'), (string) $lookup['source'])); ?>
                    <a href="<?php echo \esc_url((string) ($lookup['source_url'] ?? '')); ?>" target="_blank" rel="noopener"><?php \esc_html_e('Source', 'kaupang-stock'); ?></a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_SUPPLIER); ?>" />
            <input type="hidden" name="supplier" value="<?php echo (int) $editId; ?>" />
            <?php \wp_nonce_field(self::SAVE_SUPPLIER); ?>
            <table class="form-table" role="presentation" style="max-width:52em">
                <tr>
                    <th scope="row"><label for="ks-supp-name"><?php \esc_html_e('Name', 'kaupang-stock'); ?></label></th>
                    <td><input type="text" id="ks-supp-name" name="name" class="regular-text" required maxlength="200"
                               value="<?php echo \esc_attr($current !== null ? (string) $current['name'] : $prefillName); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-org"><?php \esc_html_e('Org. nr', 'kaupang-stock'); ?></label></th>
                    <td>
                        <input type="text" id="ks-supp-org" name="org_nr" inputmode="numeric" maxlength="11"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['org_nr'] ?? '') : $prefillOrg); ?>" />
                        <?php if (Suppliers::brregAvailable()): ?>
                            <p class="description"><?php \esc_html_e('Norwegian 9-digit organisation number (MOD11-validated).', 'kaupang-stock'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-email"><?php \esc_html_e('Email', 'kaupang-stock'); ?></label></th>
                    <td><input type="email" id="ks-supp-email" name="email" class="regular-text" maxlength="200"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['email'] ?? '') : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-phone"><?php \esc_html_e('Phone', 'kaupang-stock'); ?></label></th>
                    <td><input type="text" id="ks-supp-phone" name="phone" class="regular-text" maxlength="50"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['phone'] ?? '') : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-supp-note"><?php \esc_html_e('Note', 'kaupang-stock'); ?></label></th>
                    <td><input type="text" id="ks-supp-note" name="note" class="large-text" maxlength="255"
                               value="<?php echo \esc_attr($current !== null ? (string) ($current['note'] ?? '') : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php \esc_html_e('Active', 'kaupang-stock'); ?></th>
                    <td><label><input type="checkbox" name="active" value="1" <?php \checked($current === null || !empty($current['active'])); ?> />
                        <?php \esc_html_e('Available for new purchase orders', 'kaupang-stock'); ?></label></td>
                </tr>
            </table>
            <?php \submit_button($current ? \__('Save supplier', 'kaupang-stock') : \__('Add supplier', 'kaupang-stock')); ?>
            <?php if ($current): ?>
                <a class="button" href="<?php echo \esc_url(self::url(['view' => 'suppliers'])); ?>"><?php \esc_html_e('Cancel', 'kaupang-stock'); ?></a>
            <?php endif; ?>
        </form>
        <?php
    }

    /* -------------------------------- Handlers ------------------------------- */

    public static function handleSavePo(): void {
        self::guard(self::SAVE_PO);
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
                self::redirect(['view' => 'list', 'ks_err' => 'notfound']);
            }
            // After ordering, header note edits should append to the trail rather
            // than clobber the locked context; supplier/ref/eta stay editable.
            PurchaseOrders::updateHeader($poId, $data);
            self::redirect(['view' => 'edit', 'po' => $poId, 'ks_msg' => 'saved']);
        }
        try {
            $newId = PurchaseOrders::create($data);
        } catch (\Throwable $e) {
            self::redirect(['view' => 'list', 'ks_err' => 'save']);
        }
        self::redirect(['view' => 'edit', 'po' => $newId, 'ks_msg' => 'created']);
    }

    public static function handleOrderPo(): void {
        self::guard(self::ORDER_PO);
        $poId = (int) ($_POST['po'] ?? 0);
        if ($poId > 0 && PurchaseOrders::isDraft($poId) && Lines::forPo($poId) !== []) {
            PurchaseOrders::order($poId);
            self::redirect(['view' => 'edit', 'po' => $poId, 'ks_msg' => 'ordered']);
        }
        self::redirect(['view' => 'edit', 'po' => $poId, 'ks_err' => 'order']);
    }

    public static function handleCancelPo(): void {
        self::guard(self::CANCEL_PO);
        $poId = (int) ($_POST['po'] ?? 0);
        if ($poId > 0) {
            PurchaseOrders::cancel($poId);
            self::redirect(['view' => 'edit', 'po' => $poId, 'ks_msg' => 'cancelled']);
        }
        self::redirect(['view' => 'list']);
    }

    public static function handleSaveLine(): void {
        self::guard(self::SAVE_LINE);
        $poId   = (int) ($_POST['po'] ?? 0);
        $lineId = (int) ($_POST['line'] ?? 0);
        $qty    = (float) \str_replace(',', '.', (string) ($_POST['qty'] ?? '0'));
        $cost   = self::parseCostOre((string) ($_POST['unit_cost'] ?? ''));

        $po = $poId > 0 ? PurchaseOrders::find($poId) : null;
        if ($po === null) {
            self::redirect(['view' => 'list', 'ks_err' => 'notfound']);
        }

        // Locked-PO edits are refused; a change note is appended to the trail instead.
        if (!PurchaseOrders::isDraft($poId)) {
            $label = $lineId > 0 && ($line = Lines::find($lineId)) ? ProductSearch::label((int) $line['product_id']) : '';
            PurchaseOrders::appendNote($poId, sprintf(
                /* translators: 1: product label, 2: requested qty */
                __('Endring etter bestilling avvist: %1$s → %2$s (linjer er låst).', 'kaupang-stock'),
                $label !== '' ? $label : ('#' . $lineId),
                self::qty($qty)
            ));
            self::redirect(['view' => 'edit', 'po' => $poId, 'ks_err' => 'locked']);
        }

        try {
            if ($lineId > 0) {
                $line = Lines::find($lineId);
                if ($line === null || (int) $line['po_id'] !== $poId) {
                    self::redirect(['view' => 'edit', 'po' => $poId, 'ks_err' => 'line']);
                }
                Lines::edit($lineId, $qty, $cost, null);
                self::redirect(['view' => 'edit', 'po' => $poId, 'ks_msg' => 'line_saved']);
            }
            $productId = self::resolveProductId(
                (int) ($_POST['product_id'] ?? 0),
                (string) \wp_unslash($_POST['product_sku'] ?? '')
            );
            if ($productId <= 0) {
                self::redirect(['view' => 'edit', 'po' => $poId, 'ks_err' => 'noproduct']);
            }
            [, $merged] = Lines::add($poId, $productId, $qty, $cost, null);
            self::redirect(['view' => 'edit', 'po' => $poId, 'ks_msg' => $merged ? 'line_merged' : 'line_added']);
        } catch (\Throwable $e) {
            self::redirect(['view' => 'edit', 'po' => $poId, 'ks_err' => 'line']);
        }
    }

    public static function handleDeleteLine(): void {
        self::guard(self::DELETE_LINE, false);
        $poId   = (int) ($_GET['po'] ?? 0);
        $lineId = (int) ($_GET['line'] ?? 0);
        if ($poId > 0 && $lineId > 0 && PurchaseOrders::isDraft($poId)) {
            $line = Lines::find($lineId);
            if ($line !== null && (int) $line['po_id'] === $poId) {
                Lines::delete($lineId);
                self::redirect(['view' => 'edit', 'po' => $poId, 'ks_msg' => 'line_deleted']);
            }
        }
        self::redirect(['view' => 'edit', 'po' => $poId, 'ks_err' => 'line']);
    }

    public static function handleSaveSupplier(): void {
        self::guard(self::SAVE_SUPPLIER);
        $sid  = (int) ($_POST['supplier'] ?? 0);
        $data = [
            'name'   => (string) \wp_unslash($_POST['name'] ?? ''),
            'org_nr' => (string) \wp_unslash($_POST['org_nr'] ?? ''),
            'email'  => (string) \wp_unslash($_POST['email'] ?? ''),
            'phone'  => (string) \wp_unslash($_POST['phone'] ?? ''),
            'note'   => (string) \wp_unslash($_POST['note'] ?? ''),
            'active' => !empty($_POST['active']),
        ];
        // Surface an invalid org-nr (MOD11) as a soft warning but still save — the
        // supplier registry accepts whatever the operator has (soft-dep posture).
        $org = Suppliers::normalizeOrgNr($data['org_nr']);
        $err = ($org['orgnr'] !== '' && !$org['valid']) ? 'orgnr' : '';

        try {
            if ($sid > 0) {
                Suppliers::update($sid, $data);
            } else {
                $sid = Suppliers::create($data);
            }
        } catch (\InvalidArgumentException $e) {
            self::redirect(['view' => 'suppliers', 'ks_err' => 'suppname']);
        } catch (\Throwable $e) {
            self::redirect(['view' => 'suppliers', 'ks_err' => 'save']);
        }
        $args = ['view' => 'suppliers', 'ks_msg' => 'supp_saved'];
        if ($err !== '') {
            $args['ks_err'] = $err;
        }
        self::redirect($args);
    }

    public static function handleToggleSupplier(): void {
        self::guard(self::TOGGLE_SUPP, false);
        $sid    = (int) ($_GET['supplier'] ?? 0);
        $active = !empty($_GET['active']);
        if ($sid > 0) {
            Suppliers::setActive($sid, $active);
        }
        self::redirect(['view' => 'suppliers', 'ks_msg' => 'supp_saved']);
    }

    /**
     * BRREG "Slå opp" round-trip: look the org-nr up, stash the name/attribution
     * in a short-lived transient, and redirect back to the supplier form which
     * prefills from it. Fails soft (no result) when brreg is absent / the number
     * is unknown / BRREG is unavailable.
     */
    public static function handleBrregLookup(): void {
        self::guard(self::BRREG_LOOKUP);
        $sid   = (int) ($_POST['supplier'] ?? 0);
        $orgnr = (string) \wp_unslash($_POST['lookup_orgnr'] ?? '');
        $org   = Suppliers::normalizeOrgNr($orgnr);

        $args = ['view' => 'suppliers'];
        if ($sid > 0) {
            $args['supplier'] = $sid;
        }

        if ($org['orgnr'] === '' || !$org['valid']) {
            $args['ks_err'] = 'orgnr';
            self::redirect($args);
        }

        $result = Suppliers::brregLookup($org['orgnr']);
        if ($result === null) {
            $args['ks_err'] = 'brreg';
            self::redirect($args);
        }

        \set_transient('kaupang_stock_brreg_prefill_' . \get_current_user_id(), [
            'name'       => $result['name'],
            'orgnr'      => $org['orgnr'],
            'source'     => $result['source'],
            'source_url' => $result['source_url'],
        ], 120);
        $args['ks_msg'] = 'brreg_ok';
        self::redirect($args);
    }

    /* --------------------------------- Helpers ------------------------------- */

    private static function guard(string $action, bool $post = true): void {
        if (!\current_user_can(Settings::capability())) {
            \wp_die(\esc_html__('You do not have permission to do this.', 'kaupang-stock'), '', ['response' => 403]);
        }
        \check_admin_referer($action);
    }

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

    private static function statusChip(string $status): void {
        printf(
            '<span class="ks-chip ks-chip-%s">%s</span>',
            \esc_attr($status),
            \esc_html(PurchaseOrders::statusLabel($status))
        );
    }

    /** @param array<string,mixed> $args */
    private static function url(array $args): string {
        return \add_query_arg(array_merge(['page' => Menu::SLUG_PURCHASE], $args), \admin_url('admin.php'));
    }

    /** @param array<string,mixed> $args */
    private static function movesUrl(array $args): string {
        return \add_query_arg(array_merge(['page' => Menu::SLUG_MOVES], $args), \admin_url('admin.php'));
    }

    private static function deleteLineUrl(int $poId, int $lineId): string {
        return \wp_nonce_url(
            \add_query_arg(['action' => self::DELETE_LINE, 'po' => $poId, 'line' => $lineId], \admin_url('admin-post.php')),
            self::DELETE_LINE
        );
    }

    private static function toggleSupplierUrl(int $sid, bool $active): string {
        return \wp_nonce_url(
            \add_query_arg(['action' => self::TOGGLE_SUPP, 'supplier' => $sid, 'active' => $active ? 1 : 0], \admin_url('admin-post.php')),
            self::TOGGLE_SUPP
        );
    }

    /** @param array<string,mixed> $args */
    private static function redirect(array $args): void {
        \wp_safe_redirect(self::url($args));
        exit;
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

    private static function qty(float $q): string {
        // Integer-only v1, but format defensively for the DECIMAL column.
        if (abs($q - round($q)) < 1e-9) {
            return \number_format_i18n($q, 0);
        }
        return \number_format_i18n($q, 3);
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

    private static function localDate(string $ymd): string {
        $ymd = trim($ymd);
        if ($ymd === '' || $ymd === '0000-00-00') {
            return '—';
        }
        return \esc_html($ymd);
    }

    /** UTC 'Y-m-d H:i:s' → site-local display. */
    private static function localDateTime(string $utc): string {
        if ($utc === '' || $utc === '0000-00-00 00:00:00') {
            return '—';
        }
        return \get_date_from_gmt($utc, \get_option('date_format') . ' ' . \get_option('time_format'));
    }

    private static function notices(): void {
        $msg = isset($_GET['ks_msg']) ? \sanitize_key((string) $_GET['ks_msg']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $err = isset($_GET['ks_err']) ? \sanitize_key((string) $_GET['ks_err']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $messages = [
            'created'      => \__('Draft purchase order created.', 'kaupang-stock'),
            'saved'        => \__('Purchase order saved.', 'kaupang-stock'),
            'ordered'      => \__('Order placed. Lines are now locked.', 'kaupang-stock'),
            'cancelled'    => \__('Purchase order cancelled. Any received stock was kept.', 'kaupang-stock'),
            'line_added'   => \__('Line added.', 'kaupang-stock'),
            'line_merged'  => \__('Product already on the order — its quantity was increased.', 'kaupang-stock'),
            'line_saved'   => \__('Line saved.', 'kaupang-stock'),
            'line_deleted' => \__('Line removed.', 'kaupang-stock'),
            'supp_saved'   => \__('Supplier saved.', 'kaupang-stock'),
            'brreg_ok'     => \__('Company found — the name was filled in below.', 'kaupang-stock'),
        ];
        $errors = [
            'notfound'   => \__('Purchase order not found.', 'kaupang-stock'),
            'save'       => \__('Could not save. Please try again.', 'kaupang-stock'),
            'order'      => \__('Could not place the order — add at least one line to a draft first.', 'kaupang-stock'),
            'line'       => \__('Could not save the line.', 'kaupang-stock'),
            'noproduct'  => \__('Pick a product first.', 'kaupang-stock'),
            'locked'     => \__('The order is placed — lines are locked. The change was noted on the order instead.', 'kaupang-stock'),
            'suppname'   => \__('A supplier name is required.', 'kaupang-stock'),
            'orgnr'      => \__('That organisation number failed the MOD11 check.', 'kaupang-stock'),
            'brreg'      => \__('No company found for that organisation number (or the registry was unavailable).', 'kaupang-stock'),
        ];

        if ($msg !== '' && isset($messages[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html($messages[$msg]) . '</p></div>';
        }
        if ($err !== '' && isset($errors[$err])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . \esc_html($errors[$err]) . '</p></div>';
        }
    }
}
