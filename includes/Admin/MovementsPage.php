<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * Bevegelser (§6.2) — the filterable ledger, the audit product where every
 * question ends one click from its source document. Rendered via a WP_List_Table
 * subclass (MovementsListTable, below).
 *
 * Filters: product (id or SKU text) · reason · ref_type · actor · occurred date
 * range (site-local → UTC) · batch (?batch= renders the batch as a document).
 * A collapsible "Ny justering" form posts an adjust movement (optional backdated
 * occurred date, ≤90 days). "Reverser" row action (active mode, non-reversal
 * rows only) posts a reversal. CSV export streams the current filter.
 */
final class MovementsPage {

    private const ACT_ADJUST  = 'kaupang_stock_add_movement';
    private const ACT_REVERSE = 'kaupang_stock_reverse';
    private const ACT_EXPORT  = 'kaupang_stock_export_movements';

    public static function register(): void {
        \add_action('admin_post_' . self::ACT_ADJUST, [self::class, 'handleAdjust']);
        \add_action('admin_post_' . self::ACT_REVERSE, [self::class, 'handleReverse']);
        \add_action('admin_post_' . self::ACT_EXPORT, [self::class, 'handleExport']);
    }

    /* ------------------------------ Render -------------------------------- */

    public static function render(): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }

        $batch = isset($_GET['batch']) ? \sanitize_text_field(\wp_unslash((string) $_GET['batch'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <div class="wrap ks-wrap">
            <h1 class="wp-heading-inline"><?php \esc_html_e('Movements', 'kaupang-stock'); ?></h1>
            <?php self::notices(); ?>

            <?php if ($batch !== ''): ?>
                <?php self::renderBatch($batch); ?>
            <?php else: ?>
                <?php self::renderList(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderList(): void {
        $filters = self::readFilters();

        self::adjustForm();

        // Export button carries the current filter forward.
        $exportUrl = \wp_nonce_url(
            \add_query_arg(
                array_merge(['action' => self::ACT_EXPORT], self::filterQueryArgs($filters)),
                \admin_url('admin-post.php')
            ),
            self::ACT_EXPORT
        );
        ?>
        <hr class="wp-header-end" />
        <form method="get" class="ks-filters ks-movements-filters">
            <input type="hidden" name="page" value="<?php echo \esc_attr(Menu::SLUG_MOVES); ?>" />
            <?php self::filterControls($filters); ?>
        </form>

        <p class="ks-export-row">
            <a class="button" href="<?php echo \esc_url($exportUrl); ?>"><?php \esc_html_e('Export CSV (current filter)', 'kaupang-stock'); ?></a>
        </p>

        <?php
        $table = new MovementsListTable($filters);
        $table->prepare_items();
        // The 9-column ledger is the widest table in the plugin; give it a real
        // scroll container so it never pushes the page (see .ks-tablewrap).
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . \esc_attr(Menu::SLUG_MOVES) . '" />';
        echo '<div class="ks-tablewrap">';
        $table->display();
        echo '</div>';
        echo '</form>';
    }

    /** Per-batch "document" view (a receiving session / count apply). */
    private static function renderBatch(string $batch): void {
        $rows = Movements::forBatch($batch);
        $multi = Locations::isMulti();
        $back = \add_query_arg('page', Menu::SLUG_MOVES, \admin_url('admin.php'));
        ?>
        <p><a href="<?php echo \esc_url($back); ?>">&larr; <?php \esc_html_e('Back to movements', 'kaupang-stock'); ?></a></p>
        <h2><?php \esc_html_e('Batch', 'kaupang-stock'); ?> <code><?php echo \esc_html($batch); ?></code></h2>
        <?php if (empty($rows)): ?>
            <p><?php \esc_html_e('No movements in this batch.', 'kaupang-stock'); ?></p>
        <?php else: ?>
            <div class="ks-tablewrap">
            <table class="wp-list-table widefat striped ks-table">
                <thead>
                    <tr>
                        <th><?php \esc_html_e('Time', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <?php if ($multi): ?><th><?php \esc_html_e('Location', 'kaupang-stock'); ?></th><?php endif; ?>
                        <th class="ks-num">Δ</th>
                        <th class="ks-num"><?php \esc_html_e('Balance after', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Reason', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Reference', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Note', 'kaupang-stock'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo \esc_html(self::localTime((string) $row['occurred_at'])); ?></td>
                            <td><?php echo \esc_html(ProductSearch::label((int) $row['product_id'])); ?></td>
                            <?php if ($multi): ?><td><?php echo \esc_html(Locations::name((int) $row['location_id'])); ?></td><?php endif; ?>
                            <td class="ks-num"><?php echo self::deltaHtml((float) $row['delta']); // escaped inside ?></td>
                            <td class="ks-num"><?php echo \esc_html(self::qty((float) $row['balance_after'])); ?></td>
                            <td><span class="ks-reason-chip"><?php echo \esc_html(Reasons::label((string) $row['reason'])); ?></span></td>
                            <td><?php echo self::refCell($row); // escaped inside ?></td>
                            <td><?php echo \esc_html((string) ($row['note'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        <?php
    }

    /* --------------------------- "Ny justering" --------------------------- */

    private static function adjustForm(): void {
        $canAdjust = Settings::activeMode();
        $idem      = \wp_generate_uuid4();
        $maxDate   = \wp_date('Y-m-d');
        ?>
        <div class="ks-panel ks-adjust-panel">
            <button type="button" class="button ks-panel-toggle" aria-expanded="false" data-ks-target="ks-new-adjust">
                <?php \esc_html_e('New adjustment', 'kaupang-stock'); ?>
            </button>
            <div id="ks-new-adjust" class="ks-panel-body" hidden>
                <?php if (!$canAdjust): ?>
                    <p class="description ks-warning"><?php \esc_html_e('Adjustments write to WooCommerce stock and require active mode. Switch to active mode in settings first.', 'kaupang-stock'); ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-adjust-form">
                    <?php \wp_nonce_field(self::ACT_ADJUST); ?>
                    <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_ADJUST); ?>" />
                    <input type="hidden" name="idem" value="<?php echo \esc_attr($idem); ?>" />
                    <p>
                        <label>
                            <span class="ks-field-label"><?php \esc_html_e('Product (title, SKU or ID)', 'kaupang-stock'); ?></span>
                            <input type="text" name="product" class="regular-text ks-product-input" required<?php \disabled(!$canAdjust); ?> />
                        </label>
                    </p>
                    <?php if (Locations::isMulti()): ?>
                    <p>
                        <label>
                            <span class="ks-field-label"><?php \esc_html_e('Location', 'kaupang-stock'); ?></span>
                            <?php self::locationSelect('location_id', 0, false, !$canAdjust); ?>
                        </label>
                    </p>
                    <?php endif; ?>
                    <p>
                        <label>
                            <span class="ks-field-label"><?php \esc_html_e('Quantity change', 'kaupang-stock'); ?></span>
                            <input type="number" name="delta" step="1" class="small-text" placeholder="±0" required<?php \disabled(!$canAdjust); ?> />
                        </label>
                    </p>
                    <p>
                        <label>
                            <span class="ks-field-label"><?php \esc_html_e('Note (required)', 'kaupang-stock'); ?></span>
                            <input type="text" name="note" class="regular-text" maxlength="255" required<?php \disabled(!$canAdjust); ?> />
                        </label>
                    </p>
                    <p>
                        <label>
                            <span class="ks-field-label"><?php \esc_html_e('Occurred date (optional, up to 90 days back)', 'kaupang-stock'); ?></span>
                            <input type="date" name="occurred_date" max="<?php echo \esc_attr($maxDate); ?>"<?php \disabled(!$canAdjust); ?> />
                        </label>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"<?php \disabled(!$canAdjust); ?>><?php \esc_html_e('Record adjustment', 'kaupang-stock'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /* ------------------------------ Filters ------------------------------- */

    /** @return array<string,mixed> normalized filters for Movements::query + display */
    public static function readFilters(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $productRaw = isset($_GET['product']) ? \sanitize_text_field(\wp_unslash((string) $_GET['product'])) : '';
        $reason     = isset($_GET['reason']) ? \sanitize_key((string) $_GET['reason']) : '';
        $refType    = isset($_GET['ref_type']) ? \sanitize_key((string) $_GET['ref_type']) : '';
        $actor      = isset($_GET['actor_id']) && $_GET['actor_id'] !== '' ? (int) $_GET['actor_id'] : null;
        $locationId = isset($_GET['location_id']) ? (int) $_GET['location_id'] : 0;
        $from       = isset($_GET['from']) ? \sanitize_text_field((string) $_GET['from']) : '';
        $to         = isset($_GET['to']) ? \sanitize_text_field((string) $_GET['to']) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $productId = 0;
        if ($productRaw !== '') {
            if (ctype_digit($productRaw)) {
                $productId = (int) $productRaw;
            } else {
                $bySku = ProductSearch::bySku($productRaw);
                $productId = $bySku ?? 0;
            }
        }

        $filters = [
            'product_display' => $productRaw,
            'reason'          => $reason !== '' && Reasons::isValid($reason) ? $reason : '',
            'ref_type'        => $refType,
            'actor_id'        => $actor,
            'location_id'     => Locations::isMulti() && Locations::isActive($locationId) ? $locationId : 0,
            'from'            => $from,
            'to'              => $to,
        ];
        if ($productId > 0) {
            $filters['product_id'] = $productId;
        }
        if ($from !== '') {
            $filters['occurred_from'] = Movements::dateBoundary($from);
        }
        if ($to !== '') {
            $filters['occurred_to'] = Movements::dateBoundary($to, true);
        }
        return $filters;
    }

    private static function filterControls(array $filters): void {
        $reasons  = Reasons::all();
        $refTypes = ['order', 'po_line', 'count_line', 'movement'];
        ?>
        <input type="hidden" name="page" value="<?php echo \esc_attr(Menu::SLUG_MOVES); ?>" />
        <input type="search" name="product" value="<?php echo \esc_attr((string) ($filters['product_display'] ?? '')); ?>" placeholder="<?php \esc_attr_e('Product title, SKU or ID', 'kaupang-stock'); ?>" />
        <?php if (Locations::isMulti()): self::locationSelect('location_id', (int) ($filters['location_id'] ?? 0), true); endif; ?>
        <select name="reason">
            <option value="">— <?php \esc_html_e('Reason', 'kaupang-stock'); ?> —</option>
            <?php foreach ($reasons as $reason): ?>
                <option value="<?php echo \esc_attr($reason); ?>" <?php \selected((string) ($filters['reason'] ?? ''), $reason); ?>><?php echo \esc_html(Reasons::label($reason)); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="ref_type">
            <option value="">— <?php \esc_html_e('Reference type', 'kaupang-stock'); ?> —</option>
            <?php foreach ($refTypes as $rt): ?>
                <option value="<?php echo \esc_attr($rt); ?>" <?php \selected((string) ($filters['ref_type'] ?? ''), $rt); ?>><?php echo \esc_html($rt); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="actor_id" value="<?php echo \esc_attr($filters['actor_id'] !== null ? (string) $filters['actor_id'] : ''); ?>" class="small-text" placeholder="<?php \esc_attr_e('Actor ID', 'kaupang-stock'); ?>" />
        <label class="ks-date-label"><?php \esc_html_e('From', 'kaupang-stock'); ?>
            <input type="date" name="from" value="<?php echo \esc_attr((string) ($filters['from'] ?? '')); ?>" />
        </label>
        <label class="ks-date-label"><?php \esc_html_e('To', 'kaupang-stock'); ?>
            <input type="date" name="to" value="<?php echo \esc_attr((string) ($filters['to'] ?? '')); ?>" />
        </label>
        <?php \submit_button(\__('Filter', 'kaupang-stock'), '', '', false); ?>
        <a class="button-link" href="<?php echo \esc_url(\add_query_arg('page', Menu::SLUG_MOVES, \admin_url('admin.php'))); ?>"><?php \esc_html_e('Reset', 'kaupang-stock'); ?></a>
        <?php
    }

    /** Only the user-facing query args (for export/pagination links). @return array<string,string> */
    private static function filterQueryArgs(array $filters): array {
        $args = [];
        if (($filters['product_display'] ?? '') !== '') {
            $args['product'] = (string) $filters['product_display'];
        }
        if (($filters['reason'] ?? '') !== '') {
            $args['reason'] = (string) $filters['reason'];
        }
        if (!empty($filters['location_id'])) {
            $args['location_id'] = (string) (int) $filters['location_id'];
        }
        if (($filters['ref_type'] ?? '') !== '') {
            $args['ref_type'] = (string) $filters['ref_type'];
        }
        if (($filters['actor_id'] ?? null) !== null) {
            $args['actor_id'] = (string) $filters['actor_id'];
        }
        if (($filters['from'] ?? '') !== '') {
            $args['from'] = (string) $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $args['to'] = (string) $filters['to'];
        }
        return $args;
    }

    /* ------------------------------ Handlers ------------------------------ */

    public static function handleAdjust(): void {
        self::guard(self::ACT_ADJUST);

        $productRaw = \sanitize_text_field(\wp_unslash((string) ($_POST['product'] ?? '')));
        $delta      = self::parseDelta((string) ($_POST['delta'] ?? ''));
        $note       = \sanitize_text_field(\wp_unslash((string) ($_POST['note'] ?? '')));
        $dateRaw    = \sanitize_text_field((string) ($_POST['occurred_date'] ?? ''));
        $key        = \sanitize_text_field((string) ($_POST['idem'] ?? ''));
        $locationId = self::postedLocation();

        $productId = self::resolveProduct($productRaw);
        if ($productId <= 0) {
            self::redirect(['ks_err' => 'product']);
        }
        if ($delta === null || abs($delta) < 1e-9) {
            self::redirect(['ks_err' => 'delta']);
        }
        if ($note === '') {
            self::redirect(['ks_err' => 'note']);
        }

        $occurredAt = null;
        if ($dateRaw !== '') {
            $occurredAt = Movements::dateBoundary($dateRaw) ?: null;
        }

        $idem = $key !== '' ? 'adjust:' . $key : null;
        try {
            Ledger::adjust($productId, (float) $delta, $note, $occurredAt, $idem, $locationId);
            self::redirect(['ks_msg' => 'adjusted']);
        } catch (LedgerException $e) {
            self::redirect(['ks_err' => 'ledger', 'ks_detail' => rawurlencode($e->getMessage())]);
        }
    }

    public static function handleReverse(): void {
        self::guard(self::ACT_REVERSE);
        $movementId = isset($_POST['movement_id']) ? (int) $_POST['movement_id'] : 0;
        $note       = \sanitize_text_field(\wp_unslash((string) ($_POST['note'] ?? '')));
        if ($movementId <= 0) {
            self::redirect(['ks_err' => 'movement']);
        }
        if ($note === '') {
            self::redirect(['ks_err' => 'note']);
        }
        try {
            Ledger::reverse($movementId, $note);
            self::redirect(['ks_msg' => 'reversed']);
        } catch (LedgerException $e) {
            self::redirect(['ks_err' => 'ledger', 'ks_detail' => rawurlencode($e->getMessage())]);
        }
    }

    /** Streaming CSV of the current filter (pages of 500 over Movements::query). */
    public static function handleExport(): void {
        if (!\current_user_can(Settings::capability())) {
            \wp_die(\esc_html__('You do not have permission to do this.', 'kaupang-stock'), '', ['response' => 403]);
        }
        \check_admin_referer(self::ACT_EXPORT);

        $filters = self::readFilters();

        \nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="movements-' . \wp_date('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        // BOM so Excel reads UTF-8 (Norwegian characters) correctly.
        Movements::export($filters, $out, true);

        fclose($out);
        exit;
    }

    /* --------------------------- Cell renderers --------------------------- */
    /* (public so the list table can reuse them) */

    public static function deltaHtml(float $delta): string {
        return sprintf(
            '<span class="%s">%s</span>',
            $delta >= 0 ? 'ks-delta-pos' : 'ks-delta-neg',
            \esc_html(self::signed($delta))
        );
    }

    private static function locationSelect(string $name, int $selected, bool $allowAll, bool $disabled = false): void {
        echo '<select name="' . \esc_attr($name) . '"' . ($disabled ? ' disabled' : '') . '>';
        if ($allowAll) {
            echo '<option value="">— ' . \esc_html__('Location', 'kaupang-stock') . ' —</option>';
        }
        foreach (Locations::all(true) as $location) {
            $id = (int) $location['id'];
            echo '<option value="' . $id . '" ' . \selected($selected, $id, false) . '>' . \esc_html((string) $location['name']) . '</option>';
        }
        echo '</select>';
    }

    private static function postedLocation(): int {
        if (!Locations::isMulti()) {
            return 0;
        }
        $locationId = isset($_POST['location_id']) ? (int) $_POST['location_id'] : 0;
        return Locations::isActive($locationId) ? $locationId : 0;
    }

    /** Deep link to the causing document. @param array<string,mixed> $row */
    public static function refCell(array $row): string {
        $type = (string) ($row['ref_type'] ?? '');
        $id   = isset($row['ref_id']) ? (int) $row['ref_id'] : 0;
        if ($type === '' || $id <= 0) {
            return '<span class="ks-muted">—</span>';
        }

        switch ($type) {
            case 'order':
                $url = self::orderEditUrl($id);
                return sprintf(
                    '<a href="%s">%s #%d</a>',
                    \esc_url($url),
                    \esc_html__('Order', 'kaupang-stock'),
                    $id
                );
            case 'po_line':
                // Resolve the line to its PO so the link lands on the document,
                // not the list (indexed PK lookup, memoised per render).
                $poId = self::parentId(\Kaupang\Stock\Schema::purchaseOrderLines(), 'po_id', $id);
                $url  = $poId > 0
                    ? \add_query_arg(['page' => Menu::SLUG_PURCHASE, 'view' => 'edit', 'po' => $poId], \admin_url('admin.php'))
                    : \add_query_arg(['page' => Menu::SLUG_PURCHASE], \admin_url('admin.php'));
                return sprintf(
                    '<a href="%s">%s #%d</a>',
                    \esc_url($url),
                    \esc_html($poId > 0 ? sprintf(\__('PO #%d, line', 'kaupang-stock'), $poId) : \__('PO line', 'kaupang-stock')),
                    $id
                );
            case 'count_line':
                $countId = self::parentId(\Kaupang\Stock\Schema::countLines(), 'count_id', $id);
                $url     = $countId > 0
                    ? \add_query_arg(['page' => Menu::SLUG_COUNTS, 'count' => $countId], \admin_url('admin.php'))
                    : \add_query_arg(['page' => Menu::SLUG_COUNTS], \admin_url('admin.php'));
                return sprintf(
                    '<a href="%s">%s #%d</a>',
                    \esc_url($url),
                    \esc_html($countId > 0 ? sprintf(\__('Count #%d, line', 'kaupang-stock'), $countId) : \__('Count line', 'kaupang-stock')),
                    $id
                );
            case 'movement':
                $url = \add_query_arg(['page' => Menu::SLUG_MOVES], \admin_url('admin.php')) . '#movement-' . $id;
                return sprintf('<a href="%s">%s #%d</a>', \esc_url($url), \esc_html__('Movement', 'kaupang-stock'), $id);
            default:
                return \esc_html($type . ' #' . $id);
        }
    }

    /** @var array<string,int> memo: "table:lineId" → parent id */
    private static array $parentIdMemo = [];

    /** Parent document id for a line row (po_line → po_id, count_line → count_id). */
    private static function parentId(string $table, string $column, int $lineId): int {
        $key = $table . ':' . $lineId;
        if (!isset(self::$parentIdMemo[$key])) {
            global $wpdb;
            self::$parentIdMemo[$key] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT $column FROM $table WHERE id = %d",
                $lineId
            ));
        }
        return self::$parentIdMemo[$key];
    }

    private static function orderEditUrl(int $orderId): string {
        // HPOS admin URL when the custom order tables are authoritative; classic
        // post edit link otherwise.
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            return \admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId);
        }
        $link = \get_edit_post_link($orderId, '');
        return $link ?: \admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId);
    }

    /* --------------------------- Notices/util ----------------------------- */

    private static function notices(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $msg    = isset($_GET['ks_msg']) ? \sanitize_key((string) $_GET['ks_msg']) : '';
        $err    = isset($_GET['ks_err']) ? \sanitize_key((string) $_GET['ks_err']) : '';
        $detail = isset($_GET['ks_detail']) ? \sanitize_text_field(\wp_unslash((string) $_GET['ks_detail'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $map = [
            'adjusted' => \__('Adjustment recorded.', 'kaupang-stock'),
            'reversed' => \__('Movement reversed.', 'kaupang-stock'),
        ];
        if (isset($map[$msg])) {
            self::flash('success', $map[$msg]);
        }

        if ($err === 'product') {
            self::flash('error', \__('Could not resolve a product from that value.', 'kaupang-stock'));
        } elseif ($err === 'delta') {
            self::flash('error', \__('Enter a non-zero quantity change.', 'kaupang-stock'));
        } elseif ($err === 'note') {
            self::flash('error', \__('A note is required.', 'kaupang-stock'));
        } elseif ($err === 'movement') {
            self::flash('error', \__('A valid movement is required.', 'kaupang-stock'));
        } elseif ($err === 'ledger') {
            self::flash('error', sprintf(
                /* translators: %s: ledger error */
                \__('Could not record the movement: %s', 'kaupang-stock'),
                $detail !== '' ? $detail : \__('unknown error', 'kaupang-stock')
            ));
        }
    }

    private static function guard(string $action): void {
        if (!\current_user_can(Settings::capability())) {
            \wp_die(\esc_html__('You do not have permission to do this.', 'kaupang-stock'), '', ['response' => 403]);
        }
        \check_admin_referer($action);
    }

    /** @param array<string,string> $args */
    private static function redirect(array $args): void {
        \wp_safe_redirect(\add_query_arg(
            array_merge(['page' => Menu::SLUG_MOVES], $args),
            \admin_url('admin.php')
        ));
        exit;
    }

    private static function flash(string $type, string $message): void {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            \esc_attr($type),
            \esc_html($message)
        );
    }

    private static function resolveProduct(string $raw): int {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        $bySku = ProductSearch::bySku($raw);
        if ($bySku !== null) {
            return $bySku;
        }
        // Fall back to a single unambiguous search hit.
        $hits = ProductSearch::search($raw, 2);
        return count($hits) === 1 ? (int) $hits[0]['id'] : 0;
    }

    private static function parseDelta(string $raw): ?float {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    }

    public static function qty(float $q): string {
        if (abs($q - round($q)) < 1e-9) {
            return (string) (int) round($q);
        }
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    }

    public static function signed(float $q): string {
        return ($q >= 0 ? '+' : '−') . self::qty(abs($q));
    }

    public static function localTime(string $utc): string {
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return $utc;
        }
        return \wp_date('Y-m-d H:i', $ts) ?: $utc;
    }

    public static function reverseAction(): string {
        return self::ACT_REVERSE;
    }
}

/**
 * The Bevegelser table (§6.2 "Use a WP_List_Table subclass"). Lives in this file
 * rather than its own so the deliverable file set stays exactly as specified; it
 * is only ever instantiated from MovementsPage::renderList(), which has already
 * been autoloaded, and WP_List_Table is pulled in on demand below.
 *
 * Columns: occurred time (created_at on hover when they differ) · product label
 * · signed coloured Δ · balance_after · reason chip · ref deep-link · actor
 * display_name · via · note. The "Reverser" row action shows only in active
 * mode on rows whose reason is not already a reversal.
 */
if (!class_exists('\\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class MovementsListTable extends \WP_List_Table {

    private const PER_PAGE = 50;

    /** @var array<string,mixed> */
    private array $filters;

    /** @var array<int,string> actor id → display name (batched to avoid N queries) */
    private array $actorNames = [];

    /** @param array<string,mixed> $filters */
    public function __construct(array $filters) {
        $this->filters = $filters;
        parent::__construct([
            'singular' => 'movement',
            'plural'   => 'movements',
            'ajax'     => false,
            'screen'   => 'kaupang-stock-movements',
        ]);
    }

    /** @return array<string,string> */
    public function get_columns(): array {
        $columns = [
            'occurred'      => \__('Time', 'kaupang-stock'),
            'product'       => \__('Product', 'kaupang-stock'),
        ];
        if (Locations::isMulti()) {
            $columns['location'] = \__('Location', 'kaupang-stock');
        }
        return array_merge($columns, [
            'delta'         => 'Δ',
            'balance_after' => \__('Balance after', 'kaupang-stock'),
            'reason'        => \__('Reason', 'kaupang-stock'),
            'ref'           => \__('Reference', 'kaupang-stock'),
            'actor'         => \__('Actor', 'kaupang-stock'),
            'via'           => \__('Via', 'kaupang-stock'),
            'note'          => \__('Note', 'kaupang-stock'),
        ]);
    }

    public function prepare_items(): void {
        $columns  = $this->get_columns();
        $this->_column_headers = [$columns, [], []];

        $paged  = $this->get_pagenum();
        $result = Movements::query($this->filters, $paged, self::PER_PAGE, 'DESC');
        $rows   = $result['rows'];
        $total  = (int) $result['total'];

        $this->items = $rows;
        $this->prefillActors($rows);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) max(1, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function prefillActors(array $rows): void {
        $ids = [];
        foreach ($rows as $row) {
            $aid = (int) ($row['actor_id'] ?? 0);
            if ($aid > 0) {
                $ids[$aid] = true;
            }
        }
        if (empty($ids)) {
            return;
        }
        $users = \get_users([
            'include' => array_keys($ids),
            'fields'  => ['ID', 'display_name'],
        ]);
        foreach ($users as $user) {
            $this->actorNames[(int) $user->ID] = (string) $user->display_name;
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param string $column_name
     * @return string
     */
    public function column_default($item, $column_name): string {
        switch ($column_name) {
            case 'occurred':
                $occurred = (string) $item['occurred_at'];
                $created  = (string) $item['created_at'];
                $title    = $occurred !== $created
                    ? sprintf(
                        /* translators: %s: system creation time */
                        \esc_attr__('Recorded %s', 'kaupang-stock'),
                        MovementsPage::localTime($created)
                    )
                    : '';
                $anchor = '<span id="movement-' . (int) $item['id'] . '"></span>';
                return $anchor . sprintf(
                    '<span title="%s">%s</span>',
                    \esc_attr($title),
                    \esc_html(MovementsPage::localTime($occurred))
                );

            case 'product':
                $pid   = (int) $item['product_id'];
                $url   = \add_query_arg(['page' => Menu::SLUG_MOVES, 'product' => $pid], \admin_url('admin.php'));
                $label = ProductSearch::label($pid);
                return sprintf('<a href="%s">%s</a>', \esc_url($url), \esc_html($label));

            case 'location':
                return \esc_html(Locations::name((int) $item['location_id']));

            case 'delta':
                return MovementsPage::deltaHtml((float) $item['delta']);

            case 'balance_after':
                return \esc_html(MovementsPage::qty((float) $item['balance_after']));

            case 'reason':
                return '<span class="ks-reason-chip">' . \esc_html(Reasons::label((string) $item['reason'])) . '</span>';

            case 'ref':
                return MovementsPage::refCell($item);

            case 'actor':
                $aid = (int) ($item['actor_id'] ?? 0);
                if ($aid === 0) {
                    return '<span class="ks-muted">' . \esc_html__('system', 'kaupang-stock') . '</span>';
                }
                return \esc_html($this->actorNames[$aid] ?? ('#' . $aid));

            case 'via':
                $via = (string) ($item['via'] ?? '');
                return $via !== '' ? \esc_html($via) : '<span class="ks-muted">—</span>';

            case 'note':
                $note = (string) ($item['note'] ?? '');
                return $note !== '' ? \esc_html($note) : '<span class="ks-muted">—</span>';
        }
        return '';
    }

    /**
     * "Reverser" row action on the Time column: active mode only, never on a
     * row that is itself a reversal. A tiny inline form (note required, driven
     * by admin.js) posts to admin-post.
     *
     * @param array<string,mixed> $item
     */
    public function column_occurred($item): string {
        $cell    = $this->column_default($item, 'occurred');
        $actions = [];

        if (Settings::activeMode() && (string) $item['reason'] !== Reasons::REVERSAL) {
            $mid   = (int) $item['id'];
            $nonce = \wp_create_nonce(MovementsPage::reverseAction());
            $form  = sprintf(
                '<form method="post" action="%s" class="ks-reverse-form" data-ks-reverse="1">'
                . '<input type="hidden" name="_wpnonce" value="%s" />'
                . '<input type="hidden" name="action" value="%s" />'
                . '<input type="hidden" name="movement_id" value="%d" />'
                . '<input type="hidden" name="note" value="" />'
                . '<button type="submit" class="button-link ks-reverse-btn">%s</button>'
                . '</form>',
                \esc_url(\admin_url('admin-post.php')),
                \esc_attr($nonce),
                \esc_attr(MovementsPage::reverseAction()),
                $mid,
                \esc_html__('Reverse', 'kaupang-stock')
            );
            $actions['reverse'] = $form;
        }

        if (empty($actions)) {
            return $cell;
        }
        return $cell . $this->row_actions($actions);
    }

    public function no_items(): void {
        \esc_html_e('No movements match the current filter.', 'kaupang-stock');
    }
}
