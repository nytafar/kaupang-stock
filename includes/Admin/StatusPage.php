<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Observe\Seeder;
use Kaupang\Stock\Reconcile\Reconciler;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;
use Kaupang\Stock\Transfers;

/**
 * Lagerstatus (§6.1) — one row per stock-managed product/variation, plus any
 * balance row whose product has left scope (shown chipped "ikke lagerstyrt" /
 * "slettet"). Deliberately a READ of balances + held stock + open-PO sums —
 * never a SUM over movements on this hot path.
 *
 * Columns: product (title + SKU, link to filtered Bevegelser) · on hand ·
 * reserved (core held stock) · available (on hand − reserved) · incoming
 * (open PO remainders) · last movement (one grouped MAX(id) query) · status
 * chips (negative / low stock). Search + category filter, paginated 50.
 *
 * Inline quick adjust per row posts an `adjust` movement at occurred_at = now
 * (backdating lives on Bevegelser); the form carries a per-render idempotency
 * key so a double-submit dedupes, and is disabled unless mode is active.
 *
 * The last reconcile report is surfaced as a banner with per-row Heal and a
 * "Kjør kontroll nå" button.
 */
final class StatusPage {

    private const PER_PAGE      = 50;
    private const ACT_ADJUST    = 'kaupang_stock_quick_adjust';
    public const ACT_TRANSFER   = 'kaupang_stock_quick_transfer';
    private const ACT_HEAL      = 'kaupang_stock_heal';
    private const ACT_RECONCILE = 'kaupang_stock_reconcile_now';

    public static function register(): void {
        \add_action('admin_post_' . self::ACT_ADJUST, [self::class, 'handleAdjust']);
        \add_action('admin_post_' . self::ACT_TRANSFER, [self::class, 'handleTransfer']);
        \add_action('admin_post_' . self::ACT_HEAL, [self::class, 'handleHeal']);
        \add_action('admin_post_' . self::ACT_RECONCILE, [self::class, 'handleReconcile']);
    }

    /* ------------------------------ Render -------------------------------- */

    public static function render(): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }

        if (!Settings::enabled()) {
            self::renderDisabled();
            return;
        }

        $search   = isset($_GET['s']) ? \sanitize_text_field(\wp_unslash((string) $_GET['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $category = isset($_GET['product_cat']) ? (int) $_GET['product_cat'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $paged    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1; // phpcs:ignore WordPress.Security.NonceVerification

        $lowLoc = 0;
        if (Locations::isMulti()) {
            $rawLowLoc = isset($_GET['low_loc']) ? (int) $_GET['low_loc'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
            $lowLoc = Locations::isActive($rawLowLoc) ? $rawLowLoc : 0;
        }

        $ids   = self::rowIds($search, $category, $lowLoc);
        $total = count($ids);
        $pages = (int) max(1, (int) ceil($total / self::PER_PAGE));
        $paged = min($paged, $pages);
        $slice = array_slice($ids, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

        $balanceRows = Balances::rows($slice);
        $balances  = Balances::aggregateRows($slice);
        $multi     = Locations::isMulti();
        $locations = $multi ? Locations::all(true) : [];
        $lastMoves = self::lastMovementMap($slice);
        $incoming  = self::incomingMap();
        $managed   = array_fill_keys(Seeder::stockManagedProductIds(), true);
        $negWarn   = (bool) Settings::get('negative_warning');
        $canAdjust = Settings::activeMode();
        ?>
        <div class="wrap ks-wrap">
            <h1><?php \esc_html_e('Stock status', 'kaupang-stock'); ?></h1>

            <?php self::notices(); ?>
            <?php self::reconcileBanner(); ?>

            <form method="get" class="ks-filters">
                <input type="hidden" name="page" value="<?php echo \esc_attr(Menu::SLUG); ?>" />
                <p class="search-box">
                    <label class="screen-reader-text" for="ks-status-search"><?php \esc_html_e('Search products', 'kaupang-stock'); ?></label>
                    <input type="search" id="ks-status-search" name="s" value="<?php echo \esc_attr($search); ?>" placeholder="<?php \esc_attr_e('Title or SKU…', 'kaupang-stock'); ?>" />
                    <?php self::categoryDropdown($category); ?>
                    <?php if (Locations::isMulti()): ?>
                        <select name="low_loc">
                            <option value="0"><?php \esc_html_e('Any low status', 'kaupang-stock'); ?></option>
                            <?php foreach (Locations::all(true) as $loc): ?>
                                <option value="<?php echo (int) $loc['id']; ?>" <?php \selected($lowLoc, (int) $loc['id']); ?>><?php echo \esc_html(sprintf(\__('Low at %s', 'kaupang-stock'), (string) $loc['name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php \submit_button(\__('Filter', 'kaupang-stock'), '', '', false); ?>
                </p>
            </form>

            <div class="ks-tablewrap">
            <table class="wp-list-table widefat striped ks-table ks-status-table">
                <thead>
                    <tr>
                        <th scope="col" class="ks-col-product"><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <th scope="col" class="ks-num"><?php \esc_html_e('On hand', 'kaupang-stock'); ?></th>
                        <?php foreach ($locations as $location): ?>
                            <th scope="col" class="ks-num ks-col-location"><?php echo \esc_html((string) $location['name']); ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="ks-num"><?php \esc_html_e('Reserved', 'kaupang-stock'); ?></th>
                        <th scope="col" class="ks-num"><?php \esc_html_e('Available', 'kaupang-stock'); ?></th>
                        <th scope="col" class="ks-num"><?php \esc_html_e('Incoming', 'kaupang-stock'); ?></th>
                        <th scope="col"><?php \esc_html_e('Last movement', 'kaupang-stock'); ?></th>
                        <th scope="col"><?php \esc_html_e('Status', 'kaupang-stock'); ?></th>
                        <?php if ($multi): ?>
                            <th scope="col" class="ks-col-transfer"><?php \esc_html_e('Quick transfer', 'kaupang-stock'); ?></th>
                        <?php endif; ?>
                        <th scope="col" class="ks-col-adjust"><?php \esc_html_e('Quick adjust', 'kaupang-stock'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($slice)): ?>
                        <tr><td colspan="<?php echo 8 + count($locations) + ($multi ? 1 : 0); ?>"><?php \esc_html_e('No stock-managed products yet.', 'kaupang-stock'); ?></td></tr>
                    <?php else: foreach ($slice as $productId):
                        $inScope  = isset($managed[$productId]);
                        $row      = $balances[$productId] ?? null;
                        $onHand   = $row !== null ? (float) $row['on_hand'] : 0.0;
                        $perLocation = $balanceRows[$productId] ?? [];
                        $hasNegativeLocation = false;
                        foreach ($perLocation as $locationRow) {
                            if ((float) $locationRow['on_hand'] < 0) {
                                $hasNegativeLocation = true;
                                break;
                            }
                        }
                        $reserved = self::reserved($productId);
                        $avail    = $onHand - $reserved;
                        $inc      = $incoming[$productId] ?? 0.0;
                        $movesUrl = \add_query_arg(
                            ['page' => Menu::SLUG_MOVES, 'product_id' => $productId],
                            \admin_url('admin.php')
                        );
                        ?>
                        <tr>
                            <td class="ks-col-product">
                                <a href="<?php echo \esc_url($movesUrl); ?>"><?php echo \esc_html(ProductSearch::label($productId)); ?></a>
                                <?php if (!$inScope): ?>
                                    <?php echo self::scopeChip($productId); // escaped inside ?>
                                <?php endif; ?>
                            </td>
                            <td class="ks-num<?php echo $onHand < 0 ? ' ks-neg' : ''; ?>"><?php echo \esc_html(self::qty($onHand)); ?></td>
                            <?php foreach ($locations as $location):
                                $locationOnHand = (float) ($perLocation[(int) $location['id']]['on_hand'] ?? 0);
                            ?>
                                <td class="ks-num<?php echo $locationOnHand < 0 ? ' ks-neg' : ''; ?>">
                                    <?php echo \esc_html(self::qty($locationOnHand)); ?>
                                    <?php if ($inScope && self::isLowStock($productId, $locationOnHand)): ?>
                                        <span class="ks-chip ks-chip-warning ks-chip-loc-low" title="<?php echo \esc_attr(sprintf(\__('Low at %s', 'kaupang-stock'), (string) $location['name'])); ?>"><?php \esc_html_e('Low', 'kaupang-stock'); ?></span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="ks-num"><?php echo \esc_html(self::qty($reserved)); ?></td>
                            <td class="ks-num"><?php echo \esc_html(self::qty($avail)); ?></td>
                            <td class="ks-num"><?php echo $inc > 0 ? \esc_html(self::qty($inc)) : '—'; ?></td>
                            <td><?php echo self::lastMovementCell($lastMoves[$productId] ?? null); // escaped inside ?></td>
                            <td><?php echo self::statusChips($onHand, $productId, $negWarn, $inScope, $hasNegativeLocation); // escaped inside ?></td>
                            <?php if ($multi): ?>
                                <td class="ks-col-transfer"><?php self::transferForm($productId, $canAdjust && $inScope, true); ?></td>
                            <?php endif; ?>
                            <td class="ks-col-adjust"><?php self::quickAdjustForm($productId, $canAdjust, $inScope, $multi); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <?php self::pagination($paged, $pages, $total); ?>
        </div>
        <?php
    }

    private static function renderDisabled(): void {
        $url = \add_query_arg('page', Menu::SLUG_SETTINGS, \admin_url('admin.php'));
        ?>
        <div class="wrap ks-wrap">
            <h1><?php \esc_html_e('Stock', 'kaupang-stock'); ?></h1>
            <div class="notice notice-info inline">
                <p>
                    <?php \esc_html_e('The stock ledger is not enabled yet.', 'kaupang-stock'); ?>
                    <a href="<?php echo \esc_url($url); ?>"><?php \esc_html_e('Open settings to enable it.', 'kaupang-stock'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    /* ------------------------------ Handlers ------------------------------ */

    public static function handleAdjust(): void {
        self::guard(self::ACT_ADJUST);

        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $delta     = self::parseDelta((string) ($_POST['delta'] ?? ''));
        $note      = \sanitize_text_field(\wp_unslash((string) ($_POST['note'] ?? '')));
        $key       = \sanitize_text_field((string) ($_POST['idem'] ?? ''));
        $locationId = 0;
        if (Locations::isMulti()) {
            $postedLocation = isset($_POST['location_id']) ? (int) $_POST['location_id'] : 0;
            $locationId = Locations::isActive($postedLocation) ? $postedLocation : 0;
        }

        if ($productId <= 0) {
            self::redirect(['ks_err' => 'product']);
        }
        if ($delta === null || abs($delta) < 1e-9) {
            self::redirect(['ks_err' => 'delta']);
        }
        if ($note === '') {
            self::redirect(['ks_err' => 'note']);
        }

        $idem = $key !== '' ? 'adjust:' . $key : null;

        // Optional "à kr" unit cost: entering stock at a known cost ("add 20 @
        // 101") stashes the øre amount under the movement's idempotency key so
        // the costing fold prices the layer exactly. Positive deltas only —
        // removals are valued FIFO by the engine.
        $costRaw = trim((string) ($_POST['unit_cost'] ?? ''));
        if ($costRaw !== '' && $delta !== null && $delta > 0 && $idem !== null
            && \Kaupang\Stock\Costing\Costing::enabled()
        ) {
            $ore = (int) round(((float) str_replace(',', '.', $costRaw)) * 100);
            if ($ore >= 0) {
                try {
                    \Kaupang\Stock\Costing\CostInputs::stash($idem, $ore);
                } catch (\Throwable $e) {
                    \Kaupang\Stock\Logging\Logger::error('adjust_cost_stash_failed', ['error' => $e->getMessage()]);
                }
            }
        }

        try {
            Ledger::adjust($productId, (float) $delta, $note, null, $idem, $locationId);
            self::redirect(['ks_msg' => 'adjusted']);
        } catch (LedgerException $e) {
            self::redirect(['ks_err' => 'ledger', 'ks_detail' => rawurlencode($e->getMessage())]);
        }
    }

    public static function handleTransfer(): void {
        self::guard(self::ACT_TRANSFER);

        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;
        $sourceLocationId = isset($_POST['source_location_id']) ? (int) $_POST['source_location_id'] : 0;
        $destinationLocationId = isset($_POST['destination_location_id']) ? (int) $_POST['destination_location_id'] : 0;
        $token = \sanitize_text_field(\wp_unslash((string) ($_POST['token'] ?? '')));
        $note = \sanitize_text_field(\wp_unslash((string) ($_POST['note'] ?? '')));

        if ($productId <= 0) {
            self::redirect(['ks_err' => 'product']);
        }
        if ($quantity <= 0) {
            self::redirect(['ks_err' => 'transfer_quantity']);
        }

        try {
            Transfers::transfer(
                $productId,
                $quantity,
                $sourceLocationId,
                $destinationLocationId,
                $token,
                $note !== '' ? $note : null
            );
            self::redirect(['ks_msg' => 'transferred']);
        } catch (\Throwable $e) {
            self::redirect(['ks_err' => 'transfer', 'ks_detail' => rawurlencode($e->getMessage())]);
        }
    }

    public static function handleHeal(): void {
        self::guard(self::ACT_HEAL);
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        if ($productId <= 0) {
            self::redirect(['ks_err' => 'product']);
        }
        try {
            $outcome = Reconciler::healProduct($productId);
            self::redirect(['ks_msg' => 'healed', 'ks_detail' => rawurlencode($outcome)]);
        } catch (LedgerException $e) {
            self::redirect(['ks_err' => 'ledger', 'ks_detail' => rawurlencode($e->getMessage())]);
        }
    }

    public static function handleReconcile(): void {
        self::guard(self::ACT_RECONCILE);
        $report = Reconciler::run();
        self::redirect(['ks_msg' => 'reconciled', 'ks_detail' => rawurlencode((string) count((array) ($report['issues'] ?? [])))]);
    }

    /* ------------------------------ Row set ------------------------------- */

    /**
     * The row universe: stock-managed product ids ∪ existing balance rows
     * (so out-of-scope rows still appear), filtered by search/category, sorted
     * by title for a stable pagination order.
     *
     * @return int[]
     */
    private static function rowIds(string $search, int $category, int $lowLoc = 0): array {
        $managed = Seeder::stockManagedProductIds();
        $balance = array_keys(Balances::rows());
        $ids     = array_values(array_unique(array_merge($managed, $balance)));

        if ($search !== '') {
            $ids = self::filterBySearch($ids, $search);
        }
        if ($category > 0) {
            $ids = self::filterByCategory($ids, $category);
        }
        if ($lowLoc > 0) {
            $ids = self::filterByLowLocation($ids, $lowLoc);
        }

        return self::sortByTitle($ids);
    }

    /** @param int[] $ids @return int[] ids low at $locationId (managed only) */
    private static function filterByLowLocation(array $ids, int $locationId): array {
        if ($ids === []) {
            return [];
        }
        $managed = array_fill_keys(Seeder::stockManagedProductIds(), true);
        $rows    = Balances::rows($ids);
        $out     = [];
        foreach ($ids as $id) {
            if (!isset($managed[$id])) {
                continue;
            }
            $onHand = (float) ($rows[$id][$locationId]['on_hand'] ?? 0);
            if (self::isLowStock($id, $onHand)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @param int[] $ids @return int[] */
    private static function filterBySearch(array $ids, string $search): array {
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $in   = implode(',', array_map('intval', $ids));
        $like = '%' . $wpdb->esc_like($search) . '%';
        $hits = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             WHERE p.ID IN ($in)
               AND (p.post_title LIKE %s OR sku.meta_value LIKE %s)",
            $like,
            $like
        ));
        return array_map('intval', (array) $hits);
    }

    /** @param int[] $ids @return int[] */
    private static function filterByCategory(array $ids, int $category): array {
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $in = implode(',', array_map('intval', $ids));
        // Category is a product-level taxonomy; a variation inherits its parent's
        // terms, so resolve each id to its post parent where one exists.
        $hits = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr
                     ON tr.object_id = IF(p.post_parent > 0, p.post_parent, p.ID)
             INNER JOIN {$wpdb->term_taxonomy} tt
                     ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
             WHERE p.ID IN ($in) AND tt.term_id = %d",
            $category
        ));
        return array_map('intval', (array) $hits);
    }

    /** @param int[] $ids @return int[] sorted by product title */
    private static function sortByTitle(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $in   = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($in) ORDER BY post_title ASC, ID ASC"
        );
        $sorted = array_map('intval', (array) $rows);
        // Deleted-post ids won't come back from posts; keep them at the tail.
        $missing = array_values(array_diff($ids, $sorted));
        return array_merge($sorted, $missing);
    }

    /* --------------------------- Data helpers ----------------------------- */

    /**
     * One grouped query over the movements table: newest movement per product,
     * keyed by product id → {occurred_at, created_at, delta, reason}.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    private static function lastMovementMap(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $in    = implode(',', array_map('intval', $ids));
        $table = Schema::movements();
        $rows  = $wpdb->get_results(
            "SELECT m.product_id, m.occurred_at, m.created_at, m.delta, m.reason
             FROM $table m
             INNER JOIN (
                 SELECT product_id, MAX(id) AS max_id
                 FROM $table
                 WHERE product_id IN ($in)
                 GROUP BY product_id
             ) latest ON latest.product_id = m.product_id AND latest.max_id = m.id",
            ARRAY_A
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(int) $row['product_id']] = $row;
        }
        return $out;
    }

    /**
     * Open-PO remainders per product (Lagerstatus incoming column). The
     * Purchasing module owns the derivation; guard so the shell works before it
     * lands.
     *
     * @return array<int,float>
     */
    private static function incomingMap(): array {
        if (class_exists('\\Kaupang\\Stock\\Purchasing\\PurchaseOrders')
            && method_exists('\\Kaupang\\Stock\\Purchasing\\PurchaseOrders', 'incomingPerProduct')
        ) {
            $map = \Kaupang\Stock\Purchasing\PurchaseOrders::incomingPerProduct();
            return is_array($map) ? $map : [];
        }
        return [];
    }

    private static function reserved(int $productId): float {
        $product = \wc_get_product($productId);
        if (!$product instanceof \WC_Product) {
            return 0.0;
        }
        return (float) \wc_get_held_stock_quantity($product);
    }

    /* ------------------------------ Cells --------------------------------- */

    private static function lastMovementCell(?array $row): string {
        if ($row === null) {
            return '<span class="ks-muted">—</span>';
        }
        $occurred = (string) $row['occurred_at'];
        $created  = (string) $row['created_at'];
        $delta    = (float) $row['delta'];
        $reason   = (string) $row['reason'];

        $display = self::localTime($occurred);
        $title   = $occurred !== $created
            ? sprintf(
                /* translators: %s: system creation time */
                \esc_attr__('Recorded %s', 'kaupang-stock'),
                self::localTime($created)
            )
            : '';

        return sprintf(
            '<span class="ks-lastmove" title="%s">%s <span class="%s">%s</span> <span class="ks-reason-chip">%s</span></span>',
            \esc_attr($title),
            \esc_html($display),
            $delta >= 0 ? 'ks-delta-pos' : 'ks-delta-neg',
            \esc_html(self::signed($delta)),
            \esc_html(Reasons::label($reason))
        );
    }

    private static function statusChips(float $onHand, int $productId, bool $negWarn, bool $inScope, bool $hasNegativeLocation = false): string {
        $chips = [];
        if ($inScope && $negWarn && ($onHand < 0 || $hasNegativeLocation)) {
            $chips[] = '<span class="ks-chip ks-chip-danger">' . \esc_html__('Negative', 'kaupang-stock') . '</span>';
        }
        if ($inScope && self::isLowStock($productId, $onHand)) {
            $chips[] = '<span class="ks-chip ks-chip-warning">' . \esc_html__('Low stock', 'kaupang-stock') . '</span>';
        }
        return $chips === [] ? '<span class="ks-muted">—</span>' : implode(' ', $chips);
    }

    /** Reuse Woo's per-product low-stock amount (falls back to the store default). */
    private static function isLowStock(int $productId, float $onHand): bool {
        if (!function_exists('wc_get_low_stock_amount')) {
            return false;
        }
        $product = \wc_get_product($productId);
        if (!$product instanceof \WC_Product) {
            return false;
        }
        $threshold = \wc_get_low_stock_amount($product);
        if (!is_numeric($threshold)) {
            return false;
        }
        return $onHand <= (float) $threshold && $onHand >= 0;
    }

    private static function scopeChip(int $productId): string {
        $post = \get_post($productId);
        if ($post === null) {
            return ' <span class="ks-chip ks-chip-muted">' . \esc_html__('deleted', 'kaupang-stock') . '</span>';
        }
        return ' <span class="ks-chip ks-chip-muted">' . \esc_html__('not stock managed', 'kaupang-stock') . '</span>';
    }

    private static function quickAdjustForm(int $productId, bool $canAdjust, bool $inScope, bool $multi): void {
        if (!$inScope) {
            echo '<span class="ks-muted">—</span>';
            return;
        }
        $disabledTitle = $canAdjust
            ? ''
            : \esc_attr__('Switch to active mode in settings to adjust stock.', 'kaupang-stock');
        $disabled = $canAdjust ? '' : ' disabled';
        $idem     = \wp_generate_uuid4();
        ?>
        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-quick-adjust"<?php echo $disabledTitle !== '' ? ' title="' . $disabledTitle . '"' : ''; ?>>
            <?php \wp_nonce_field(self::ACT_ADJUST); ?>
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_ADJUST); ?>" />
            <input type="hidden" name="product_id" value="<?php echo \esc_attr((string) $productId); ?>" />
            <input type="hidden" name="idem" value="<?php echo \esc_attr($idem); ?>" />
            <?php if ($multi): ?>
                <select name="location_id" aria-label="<?php \esc_attr_e('Location', 'kaupang-stock'); ?>"<?php echo $disabled; ?>>
                    <?php foreach (Locations::all(true) as $location): ?>
                        <option value="<?php echo (int) $location['id']; ?>"><?php echo \esc_html((string) $location['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <input type="number" name="delta" step="1" class="ks-adjust-delta" placeholder="±0" aria-label="<?php \esc_attr_e('Quantity change', 'kaupang-stock'); ?>"<?php echo $disabled; ?> />
            <?php if (\Kaupang\Stock\Costing\Costing::enabled()): ?>
                <input type="number" name="unit_cost" step="0.01" min="0" class="ks-adjust-cost" placeholder="<?php \esc_attr_e('à kr', 'kaupang-stock'); ?>" title="<?php \esc_attr_e('Unit cost ex-VAT (kr) — prices the cost layer when adding stock', 'kaupang-stock'); ?>" aria-label="<?php \esc_attr_e('Unit cost ex-VAT (kr)', 'kaupang-stock'); ?>"<?php echo $disabled; ?> />
            <?php endif; ?>
            <input type="text" name="note" class="ks-adjust-note" placeholder="<?php \esc_attr_e('Note (required)', 'kaupang-stock'); ?>" maxlength="255" aria-label="<?php \esc_attr_e('Note', 'kaupang-stock'); ?>"<?php echo $disabled; ?> />
            <button type="submit" class="button button-small ks-adjust-submit"<?php echo $disabled; ?>><?php \esc_html_e('Save', 'kaupang-stock'); ?></button>
        </form>
        <?php
    }

    /** Quick transfer form shared with the product-side panel. */
    public static function transferForm(int $productId, bool $enabled, bool $compact = false): void {
        if (!Locations::isMulti()) {
            return;
        }

        $locations = Locations::all(true);
        $sourceId = Balances::defaultLocationId();
        $destinationId = 0;
        foreach ($locations as $location) {
            $candidate = (int) $location['id'];
            if ($candidate !== $sourceId) {
                $destinationId = $candidate;
                break;
            }
        }
        if ($destinationId <= 0) {
            return;
        }

        $disabled = $enabled ? '' : ' disabled';
        $title = $enabled ? '' : \esc_attr__('Switch to active mode in settings to transfer stock.', 'kaupang-stock');
        ?>
        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-quick-transfer<?php echo $compact ? ' ks-quick-transfer-compact' : ''; ?>"<?php echo $title !== '' ? ' title="' . $title . '"' : ''; ?>>
            <?php \wp_nonce_field(self::ACT_TRANSFER); ?>
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_TRANSFER); ?>" />
            <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>" />
            <input type="hidden" name="token" value="<?php echo \esc_attr(\wp_generate_uuid4()); ?>" />
            <label><span><?php \esc_html_e('From', 'kaupang-stock'); ?></span>
                <select name="source_location_id"<?php echo $disabled; ?>>
                    <?php foreach ($locations as $location): $id = (int) $location['id']; ?>
                        <option value="<?php echo $id; ?>" <?php \selected($id, $sourceId); ?>><?php echo \esc_html((string) $location['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php \esc_html_e('To', 'kaupang-stock'); ?></span>
                <select name="destination_location_id"<?php echo $disabled; ?>>
                    <?php foreach ($locations as $location): $id = (int) $location['id']; ?>
                        <option value="<?php echo $id; ?>" <?php \selected($id, $destinationId); ?>><?php echo \esc_html((string) $location['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php \esc_html_e('Qty', 'kaupang-stock'); ?></span>
                <input type="number" name="quantity" step="1" min="1" class="small-text" required<?php echo $disabled; ?> />
            </label>
            <label class="ks-transfer-note"><span><?php \esc_html_e('Note', 'kaupang-stock'); ?></span>
                <input type="text" name="note" maxlength="255" placeholder="<?php \esc_attr_e('Optional', 'kaupang-stock'); ?>"<?php echo $disabled; ?> />
            </label>
            <button type="submit" class="button button-small"<?php echo $disabled; ?>><?php \esc_html_e('Transfer', 'kaupang-stock'); ?></button>
        </form>
        <?php
    }

    /* ------------------------- Reconcile banner --------------------------- */

    private static function reconcileBanner(): void {
        $report = \get_option(Reconciler::REPORT_OPTION);
        if (!is_array($report) || empty($report['issues'])) {
            self::reconcileButton(true);
            return;
        }
        $issues = (array) $report['issues'];
        ?>
        <div class="notice notice-warning ks-reconcile-banner">
            <p>
                <strong><?php \esc_html_e('Reconciliation found stock discrepancies.', 'kaupang-stock'); ?></strong>
                <?php if (!empty($report['time'])): ?>
                    <span class="ks-muted"><?php echo \esc_html(self::localTime((string) $report['time'])); ?></span>
                <?php endif; ?>
            </p>
            <table class="wp-list-table widefat striped ks-reconcile-table">
                <thead>
                    <tr>
                        <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Ledger sum', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('On hand', 'kaupang-stock'); ?></th>
                        <th class="ks-num">_stock</th>
                        <th class="ks-num"><?php \esc_html_e('Lookup', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Owned tail', 'kaupang-stock'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issues as $issue):
                        $pid = (int) ($issue['product_id'] ?? 0);
                        ?>
                        <tr>
                            <td><?php echo \esc_html(($issue['name'] ?? '') !== '' ? (string) $issue['name'] : '#' . $pid); ?></td>
                            <td class="ks-num"><?php echo \esc_html(self::qty((float) ($issue['sum'] ?? 0))); ?></td>
                            <td class="ks-num"><?php echo \esc_html(self::qty((float) ($issue['on_hand'] ?? 0))); ?></td>
                            <td class="ks-num"><?php echo \esc_html(self::qty((float) ($issue['stock'] ?? 0))); ?></td>
                            <td class="ks-num"><?php echo $issue['lookup'] === null ? '—' : \esc_html(self::qty((float) $issue['lookup'])); ?></td>
                            <td><?php echo !empty($issue['has_tail']) ? \esc_html__('yes', 'kaupang-stock') : \esc_html__('no', 'kaupang-stock'); ?></td>
                            <td>
                                <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-inline-form">
                                    <?php \wp_nonce_field(self::ACT_HEAL); ?>
                                    <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_HEAL); ?>" />
                                    <input type="hidden" name="product_id" value="<?php echo \esc_attr((string) $pid); ?>" />
                                    <button type="submit" class="button button-small"><?php \esc_html_e('Heal', 'kaupang-stock'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><?php self::reconcileButton(false); ?></p>
        </div>
        <?php
    }

    private static function reconcileButton(bool $wrap): void {
        if ($wrap) {
            echo '<p class="ks-reconcile-run">';
        }
        ?>
        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-inline-form">
            <?php \wp_nonce_field(self::ACT_RECONCILE); ?>
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_RECONCILE); ?>" />
            <button type="submit" class="button"><?php \esc_html_e('Run check now', 'kaupang-stock'); ?></button>
        </form>
        <?php
        if ($wrap) {
            echo '</p>';
        }
    }

    /* ------------------------------ Chrome -------------------------------- */

    private static function notices(): void {
        $msg    = isset($_GET['ks_msg']) ? \sanitize_key((string) $_GET['ks_msg']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $err    = isset($_GET['ks_err']) ? \sanitize_key((string) $_GET['ks_err']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $detail = isset($_GET['ks_detail']) ? \sanitize_text_field(\wp_unslash((string) $_GET['ks_detail'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($msg === 'adjusted') {
            self::flash('success', \__('Stock adjusted.', 'kaupang-stock'));
        } elseif ($msg === 'transferred') {
            self::flash('success', \__('Stock transferred.', 'kaupang-stock'));
        } elseif ($msg === 'healed') {
            self::flash('success', sprintf(
                /* translators: %s: heal outcome */
                \__('Heal complete: %s.', 'kaupang-stock'),
                $detail !== '' ? $detail : 'ok'
            ));
        } elseif ($msg === 'reconciled') {
            $n = (int) $detail;
            self::flash('info', $n > 0
                ? sprintf(
                    /* translators: %d: number of discrepancies */
                    \_n('Reconciliation complete: %d discrepancy found.', 'Reconciliation complete: %d discrepancies found.', $n, 'kaupang-stock'),
                    $n
                )
                : \__('Reconciliation complete: no discrepancies.', 'kaupang-stock'));
        }

        if ($err === 'product') {
            self::flash('error', \__('A valid product is required.', 'kaupang-stock'));
        } elseif ($err === 'delta') {
            self::flash('error', \__('Enter a non-zero quantity change.', 'kaupang-stock'));
        } elseif ($err === 'note') {
            self::flash('error', \__('A note is required for an adjustment.', 'kaupang-stock'));
        } elseif ($err === 'transfer_quantity') {
            self::flash('error', \__('Enter a positive whole transfer quantity.', 'kaupang-stock'));
        } elseif ($err === 'transfer') {
            self::flash('error', sprintf(
                /* translators: %s: transfer error message */
                \__('Could not transfer stock: %s', 'kaupang-stock'),
                $detail !== '' ? $detail : \__('unknown error', 'kaupang-stock')
            ));
        } elseif ($err === 'ledger') {
            self::flash('error', sprintf(
                /* translators: %s: ledger error message */
                \__('Could not record the movement: %s', 'kaupang-stock'),
                $detail !== '' ? $detail : \__('unknown error', 'kaupang-stock')
            ));
        }
    }

    private static function categoryDropdown(int $selected): void {
        if (!function_exists('wp_dropdown_categories')) {
            return;
        }
        \wp_dropdown_categories([
            'taxonomy'         => 'product_cat',
            'name'             => 'product_cat',
            'orderby'          => 'name',
            'selected'         => $selected,
            'hierarchical'     => true,
            'show_option_all'  => \__('All categories', 'kaupang-stock'),
            'show_count'       => false,
            'hide_empty'       => false,
            'value_field'      => 'term_id',
        ]);
    }

    private static function pagination(int $paged, int $pages, int $total): void {
        if ($pages <= 1) {
            return;
        }
        $base = \add_query_arg('paged', '%#%');
        $links = \paginate_links([
            'base'      => $base,
            'format'    => '',
            'current'   => $paged,
            'total'     => $pages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]);
        if ($links === null) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages">';
        printf(
            '<span class="displaying-num">%s</span>',
            \esc_html(sprintf(
                /* translators: %d: total item count */
                \_n('%d item', '%d items', $total, 'kaupang-stock'),
                $total
            ))
        );
        echo \wp_kses_post($links);
        echo '</div></div>';
    }

    /* ------------------------------ Shared -------------------------------- */

    private static function guard(string $action): void {
        if (!\current_user_can(Settings::capability())) {
            \wp_die(\esc_html__('You do not have permission to do this.', 'kaupang-stock'), '', ['response' => 403]);
        }
        \check_admin_referer($action);
    }

    /** @param array<string,string> $args */
    private static function redirect(array $args): void {
        \wp_safe_redirect(\add_query_arg(
            array_merge(['page' => Menu::SLUG], $args),
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

    private static function parseDelta(string $raw): ?float {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    }

    private static function qty(float $q): string {
        // Integer-only v1; show whole numbers without trailing zeros.
        if (abs($q - round($q)) < 1e-9) {
            return (string) (int) round($q);
        }
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    }

    private static function signed(float $q): string {
        $s = self::qty(abs($q));
        return ($q >= 0 ? '+' : '−') . $s;
    }

    private static function localTime(string $utc): string {
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return $utc;
        }
        return \wp_date('Y-m-d H:i', $ts) ?: $utc;
    }
}
