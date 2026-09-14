<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Costing\Costing;
use Kaupang\Stock\Costing\CostingException;
use Kaupang\Stock\Costing\WcCogsBridge;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * "Lager → Lagerverdi" — the valuation and COGS reporting surface.
 *
 * Read-only over the cost projection (the one write is the opening-cost
 * entry, which goes through Costing::saveOpening()). Renders:
 *  - the opening-cost setup box while products still await an opening cost;
 *  - per-product valuation (point-in-time capable via the as-of picker,
 *    since append-only tables make "as of" a filtered sum) + CSV export;
 *  - a per-product drill-down (layers + consumptions — the audit chain);
 *  - the COGS period report with the reconciliation identity
 *    (opening + inbound − COGS = closing).
 */
final class ValuationPage {

    private const ACT_OPENING = 'kaupang_stock_opening_save';
    private const ACT_CSV     = 'kaupang_stock_valuation_csv';

    public static function register(): void {
        \add_action('admin_post_' . self::ACT_OPENING, [self::class, 'handleOpeningSave']);
        \add_action('admin_post_' . self::ACT_CSV, [self::class, 'handleCsv']);
    }

    /* ------------------------------ Render -------------------------------- */

    public static function render(): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }
        if (!Costing::enabled()) {
            self::renderDisabled();
            return;
        }

        // Cheap at this catalog size; guarantees the page reflects the ledger.
        Costing::sweep();

        // phpcs:disable WordPress.Security.NonceVerification -- read-only GET filters.
        $view      = isset($_GET['view']) ? \sanitize_key((string) $_GET['view']) : '';
        $productId = isset($_GET['product']) ? (int) $_GET['product'] : 0;
        $locationId = isset($_GET['location']) ? (int) $_GET['location'] : 0;
        // phpcs:enable

        echo '<div class="wrap ks-wrap">';
        self::notices();

        if ($view === 'product' && $productId > 0) {
            self::renderDrilldown($productId, $locationId);
            echo '</div>';
            return;
        }

        echo '<h1>' . \esc_html__('Stock value (Lagerverdi)', 'kaupang-stock') . '</h1>';
        self::openingBox();
        self::valuationSection();
        self::cogsSection();
        echo '</div>';
    }

    private static function renderDisabled(): void {
        echo '<div class="wrap ks-wrap"><h1>' . \esc_html__('Stock value (Lagerverdi)', 'kaupang-stock') . '</h1>';
        echo '<p>' . \esc_html__('Cost tracking is not enabled. Turn it on under Lager → Innstillinger.', 'kaupang-stock') . '</p></div>';
    }

    /* --------------------------- Opening box ------------------------------ */

    private static function openingBox(): void {
        $pending = Costing::pendingOpening();
        if ($pending === []) {
            return;
        }
        global $wpdb;
        ?>
        <?php // A plain card, deliberately NOT .notice — notice classes get relocated
              // by core and swept into notice-consolidator plugins' drawers. ?>
        <div class="ks-opening-box postbox">
            <p><strong><?php \esc_html_e('Opening costs missing', 'kaupang-stock'); ?></strong> —
                <?php \esc_html_e('these products had stock when cost tracking was enabled. Enter what one unit cost (ex-VAT) so the value on hand is complete; sales made in the meantime settle automatically.', 'kaupang-stock'); ?></p>
            <table class="widefat striped ks-opening-table">
                <thead><tr>
                    <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Qty at start', 'kaupang-stock'); ?></th>
                    <th class="ks-num"><?php \esc_html_e('Last PO cost', 'kaupang-stock'); ?></th>
                    <th><?php \esc_html_e('Unit cost ex-VAT (kr)', 'kaupang-stock'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($pending as $pid => $info):
                    $lastPo = $wpdb->get_var($wpdb->prepare(
                        'SELECT unit_cost_ore FROM ' . Schema::purchaseOrderLines() . ' WHERE product_id = %d AND unit_cost_ore IS NOT NULL ORDER BY id DESC LIMIT 1',
                        $pid
                    ));
                    $product = \wc_get_product($pid);
                    $prefill = '';
                    if ($product instanceof \WC_Product && method_exists($product, 'get_cogs_value') && (float) $product->get_cogs_value() > 0) {
                        $prefill = number_format((float) $product->get_cogs_value(), 2, '.', '');
                    } elseif ($lastPo !== null) {
                        $prefill = number_format(((int) $lastPo) / 100, 2, '.', '');
                    }
                    ?>
                    <tr>
                        <td><?php echo \esc_html(ProductSearch::label($pid)); ?></td>
                        <td class="ks-num"><?php echo \esc_html(self::qty((float) $info['qty'])); ?></td>
                        <td class="ks-num ks-muted"><?php echo $lastPo !== null ? \esc_html(self::kr((int) $lastPo)) : '—'; ?></td>
                        <td>
                            <?php // The whole form lives in ONE cell — a form element
                                  // spanning <td> boundaries gets auto-closed by the
                                  // HTML parser, detaching the submit button. ?>
                            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-opening-form">
                                <?php \wp_nonce_field(self::ACT_OPENING); ?>
                                <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_OPENING); ?>" />
                                <input type="hidden" name="product_id" value="<?php echo (int) $pid; ?>" />
                                <input type="number" name="unit_cost" step="0.01" min="0" class="small-text" required
                                       value="<?php echo \esc_attr($prefill); ?>" />
                                <button type="submit" class="button button-small"><?php \esc_html_e('Save', 'kaupang-stock'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ------------------------- Valuation section -------------------------- */

    private static function valuationSection(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $asOfRaw = isset($_GET['as_of']) ? \sanitize_text_field((string) $_GET['as_of']) : '';
        // phpcs:enable
        $asOfUtc = null;
        if ($asOfRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfRaw)) {
            $asOfUtc = \get_gmt_from_date($asOfRaw . ' 23:59:59'); // end of the chosen day, site-local
        } else {
            $asOfRaw = '';
        }

        $multi  = Locations::isMulti();
        ['rows' => $rows, 'totals' => $totals] = Costing::valuation(['as_of' => $asOfUtc, 'by_location' => $multi]);
        ?>
        <form method="get" class="ks-filters ks-filterbar ks-valuation-filter" data-ks-autosubmit>
            <input type="hidden" name="page" value="<?php echo \esc_attr(Menu::SLUG_VALUATION); ?>" />
            <label for="ks-asof"><?php \esc_html_e('Value as of', 'kaupang-stock'); ?></label>
            <input type="date" id="ks-asof" name="as_of" value="<?php echo \esc_attr($asOfRaw); ?>" />
            <noscript><button type="submit" class="button"><?php \esc_html_e('Show', 'kaupang-stock'); ?></button></noscript>
            <?php if ($asOfRaw !== ''): ?>
                <a class="button button-link" href="<?php echo \esc_url(self::url([])); ?>"><?php \esc_html_e('Now', 'kaupang-stock'); ?></a>
            <?php endif; ?>
        </form>

        <?php if (Settings::get('cogs_order_meta_enabled') && !WcCogsBridge::wcCogsFeatureEnabled()): ?>
            <div class="notice notice-warning inline"><p>
                <?php \esc_html_e('Order COGS stamping is on, but WooCommerce’s “Cost of goods sold” feature is disabled — only the plugin’s own øre meta is being written. Enable the feature under WooCommerce → Settings → Advanced → Features for the native fields.', 'kaupang-stock'); ?>
            </p></div>
        <?php endif; ?>

        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped ks-valuation-table">
            <thead><tr>
                <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                <?php if ($multi): ?><th><?php \esc_html_e('Location', 'kaupang-stock'); ?></th><?php endif; ?>
                <th class="ks-num"><?php \esc_html_e('Qty', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Avg cost', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Value', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Flags', 'kaupang-stock'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?php echo $multi ? 7 : 6; ?>" class="ks-muted"><?php \esc_html_e('No cost layers yet — receive goods with a cost, add stock with “à kr”, or enter opening costs.', 'kaupang-stock'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                $costedQty = $row['open_qty'] - $row['uncosted_qty'];
                $avg       = $costedQty > 1e-9 ? (int) round($row['value_ore'] / $costedQty) : null;
                ?>
                <tr>
                    <td><?php echo \esc_html(ProductSearch::label($row['product_id'])); ?></td>
                    <?php if ($multi): ?><td><?php echo \esc_html(Locations::name((int) $row['location_id'])); ?></td><?php endif; ?>
                    <td class="ks-num"><?php echo \esc_html(self::qty($row['open_qty'])); ?></td>
                    <td class="ks-num"><?php echo $avg !== null ? \esc_html(self::kr($avg)) : '—'; ?></td>
                    <td class="ks-num"><?php echo \esc_html(self::kr($row['value_ore'])); ?></td>
                    <td>
                        <?php if ($row['uncosted_qty'] > 1e-9): ?>
                            <span class="ks-chip ks-chip-warning"><?php echo \esc_html(sprintf(\__('Uncosted %s', 'kaupang-stock'), self::qty($row['uncosted_qty']))); ?></span>
                        <?php endif; ?>
                        <?php if ($row['estimate_qty'] > 1e-9): ?>
                            <span class="ks-chip"><?php echo \esc_html(sprintf(\__('Estimate %s', 'kaupang-stock'), self::qty($row['estimate_qty']))); ?></span>
                        <?php endif; ?>
                        <?php if ($row['provisional_qty'] > 1e-9): ?>
                            <span class="ks-chip ks-chip-warning"><?php echo \esc_html(sprintf(\__('Provisional %s', 'kaupang-stock'), self::qty($row['provisional_qty']))); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?php echo \esc_url(self::url(array_filter(['view' => 'product', 'product' => $row['product_id'], 'location' => $multi ? $row['location_id'] : 0]))); ?>"><?php \esc_html_e('Details', 'kaupang-stock'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th><?php \esc_html_e('Total', 'kaupang-stock'); ?></th>
                <?php if ($multi): ?><th></th><?php endif; ?>
                <th class="ks-num"><?php echo \esc_html(self::qty($totals['open_qty'])); ?></th>
                <th></th>
                <th class="ks-num"><strong><?php echo \esc_html(self::kr($totals['total_ore'])); ?></strong></th>
                <th colspan="2" class="ks-muted">
                    <?php
                    echo \esc_html(sprintf(
                        /* translators: 1: products with value, 2: products holding uncosted stock */
                        \__('%1$d products · %2$d with uncosted stock', 'kaupang-stock'),
                        (int) $totals['products'],
                        (int) $totals['uncosted_products']
                    ));
                    ?>
                </th>
            </tr></tfoot>
        </table>
        </div>

        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-valuation-csv">
            <?php \wp_nonce_field(self::ACT_CSV); ?>
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACT_CSV); ?>" />
            <input type="hidden" name="as_of" value="<?php echo \esc_attr($asOfRaw); ?>" />
            <button type="submit" class="button"><?php \esc_html_e('Export CSV', 'kaupang-stock'); ?></button>
        </form>
        <?php
    }

    /* ---------------------------- COGS report ----------------------------- */

    private static function cogsSection(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $fromRaw = isset($_GET['from']) ? \sanitize_text_field((string) $_GET['from']) : '';
        $toRaw   = isset($_GET['to']) ? \sanitize_text_field((string) $_GET['to']) : '';
        // phpcs:enable
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)) {
            $fromRaw = (string) \wp_date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
            $toRaw = (string) \wp_date('Y-m-d');
        }
        $report = Costing::report(
            \get_gmt_from_date($fromRaw . ' 00:00:00'),
            \get_gmt_from_date($toRaw . ' 23:59:59')
        );
        ?>
        <h2><?php \esc_html_e('Cost of goods sold', 'kaupang-stock'); ?></h2>
        <form method="get" class="ks-filters ks-filterbar ks-cogs-filter" data-ks-autosubmit>
            <input type="hidden" name="page" value="<?php echo \esc_attr(Menu::SLUG_VALUATION); ?>" />
            <?php Screen::dateRange($fromRaw, $toRaw); ?>
            <noscript><button type="submit" class="button"><?php \esc_html_e('Show', 'kaupang-stock'); ?></button></noscript>
        </form>

        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped ks-cogs-table">
            <thead><tr>
                <th><?php \esc_html_e('Reason', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Qty', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('COGS', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Uncosted qty', 'kaupang-stock'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ($report['by_reason'] === []): ?>
                <tr><td colspan="4" class="ks-muted"><?php \esc_html_e('No consumption in the period.', 'kaupang-stock'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($report['by_reason'] as $reason => $bucket): ?>
                <tr>
                    <td><span class="ks-reason-chip"><?php echo \esc_html(self::originLabel((string) $reason)); ?></span></td>
                    <td class="ks-num"><?php echo \esc_html(self::qty($bucket['qty'])); ?></td>
                    <td class="ks-num"><?php echo \esc_html(self::kr($bucket['cost_ore'])); ?></td>
                    <td class="ks-num"><?php echo $bucket['uncosted_qty'] > 1e-9 ? \esc_html(self::qty($bucket['uncosted_qty'])) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p class="ks-cogs-identity">
            <?php
            echo \esc_html(sprintf(
                /* translators: 1: opening value, 2: inbound value, 3: COGS, 4: closing value */
                \__('Opening %1$s + inbound %2$s − COGS %3$s = closing %4$s', 'kaupang-stock'),
                self::kr($report['opening_ore']),
                self::kr($report['inbound_ore']),
                self::kr($report['cogs_ore']),
                self::kr($report['closing_ore'])
            ));
            if ((int) $report['identity_gap_ore'] === 0) {
                echo ' <span class="ks-chip ks-chip-ok">' . \esc_html__('Ties out to the øre', 'kaupang-stock') . '</span>';
            } else {
                echo ' <span class="ks-chip ks-chip-warning">' . \esc_html(sprintf(
                    /* translators: %s: gap amount */
                    \__('Gap %s — expected with uncosted stock in the period', 'kaupang-stock'),
                    self::kr((int) $report['identity_gap_ore'])
                )) . '</span>';
            }
            ?>
        </p>
        <?php
    }

    /* ----------------------------- Drill-down ----------------------------- */

    private static function renderDrilldown(int $productId, int $locationId = 0): void {
        $locationId = Locations::isMulti() && Locations::exists($locationId) ? $locationId : 0;
        ?>
        <h1><?php echo \esc_html(sprintf(
            /* translators: %s: product label */
            \__('Cost layers — %s', 'kaupang-stock'),
            ProductSearch::label($productId)
        )); ?></h1>
        <?php if ($locationId > 0): ?><p class="ks-muted"><?php echo \esc_html(Locations::name($locationId)); ?></p><?php endif; ?>
        <p><a href="<?php echo \esc_url(self::url([])); ?>">&larr; <?php \esc_html_e('Back to stock value', 'kaupang-stock'); ?></a></p>

        <?php $drill = Costing::drillDown($productId, $locationId); ?>
        <h2><?php \esc_html_e('Layers', 'kaupang-stock'); ?></h2>
        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <th>#</th>
                <th><?php \esc_html_e('Origin', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Qty', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Remaining', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Unit cost', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Ref', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Date', 'kaupang-stock'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($drill['layers'] as $layer): ?>
                <tr>
                    <td class="ks-muted">#<?php echo (int) $layer['id']; ?></td>
                    <td>
                        <span class="ks-reason-chip"><?php echo \esc_html(self::originLabel((string) $layer['origin'])); ?></span>
                        <?php if ((int) $layer['is_estimate'] === 1): ?>
                            <span class="ks-chip"><?php \esc_html_e('Estimate', 'kaupang-stock'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="ks-num"><?php echo \esc_html(self::qty((float) $layer['qty_original'])); ?></td>
                    <td class="ks-num"><?php echo \esc_html(self::qty((float) $layer['remaining'])); ?></td>
                    <td class="ks-num"><?php echo $layer['unit_cost_ore'] !== null ? \esc_html(self::kr((int) $layer['unit_cost_ore'])) : '—'; ?></td>
                    <td class="ks-muted"><?php echo \esc_html(self::refLabel($layer)); ?></td>
                    <td class="ks-muted"><?php echo \esc_html(self::localTime((string) $layer['occurred_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <h2><?php \esc_html_e('Consumption', 'kaupang-stock'); ?></h2>
        <div class="ks-tablewrap">
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <th><?php \esc_html_e('Date', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Kind', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Reason', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Qty', 'kaupang-stock'); ?></th>
                <th class="ks-num"><?php \esc_html_e('Cost', 'kaupang-stock'); ?></th>
                <th><?php \esc_html_e('Ref', 'kaupang-stock'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($drill['consumptions'] as $c): ?>
                <tr>
                    <td class="ks-muted"><?php echo \esc_html(self::localTime((string) $c['occurred_at'])); ?></td>
                    <td><span class="ks-reason-chip"><?php echo \esc_html(self::kindLabel((string) $c['kind'])); ?></span></td>
                    <td><?php echo $c['reason'] !== null ? \esc_html(Reasons::label((string) $c['reason'])) : '—'; ?></td>
                    <td class="ks-num"><?php echo \esc_html(self::qty((float) $c['qty'])); ?></td>
                    <td class="ks-num"><?php echo $c['cost_ore'] !== null ? \esc_html(self::kr((int) $c['cost_ore'])) : '—'; ?></td>
                    <td class="ks-muted"><?php echo self::consumptionRef($c); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /* ------------------------------ Handlers ------------------------------ */

    public static function handleOpeningSave(): void {
        Screen::guard(self::ACT_OPENING);

        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $costRaw   = str_replace(',', '.', trim((string) ($_POST['unit_cost'] ?? '')));

        if ($productId <= 0 || $costRaw === '' || !is_numeric($costRaw) || (float) $costRaw < 0) {
            Screen::redirect(Menu::SLUG_VALUATION, ['ks_err' => 'opening_input']);
        }
        try {
            Costing::saveOpening($productId, (int) round(((float) $costRaw) * 100));
        } catch (CostingException $e) {
            Screen::redirect(Menu::SLUG_VALUATION, ['ks_err' => 'opening', 'ks_detail' => $e->getMessage()]);
        }
        Screen::redirect(Menu::SLUG_VALUATION, ['ks_msg' => 'opening_saved']);
    }

    public static function handleCsv(): void {
        Screen::guard(self::ACT_CSV);

        $asOfRaw = \sanitize_text_field((string) ($_POST['as_of'] ?? ''));
        $asOfUtc = null;
        if ($asOfRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfRaw)) {
            $asOfUtc = \get_gmt_from_date($asOfRaw . ' 23:59:59');
        } else {
            $asOfRaw = (string) \wp_date('Y-m-d');
        }

        Costing::sweep();
        $multi  = Locations::isMulti();
        ['rows' => $rows, 'totals' => $totals] = Costing::valuation(['as_of' => $asOfUtc, 'by_location' => $multi]);

        $header = ['product_id', 'product'];
        if ($multi) {
            $header = array_merge($header, ['location_id', 'location']);
        }
        $header = array_merge($header, ['qty', 'uncosted_qty', 'estimate_qty', 'provisional_qty', 'avg_cost_kr', 'value_kr']);

        $csv = [];
        foreach ($rows as $row) {
            $costedQty = $row['open_qty'] - $row['uncosted_qty'];
            $avg       = $costedQty > 1e-9 ? round($row['value_ore'] / $costedQty) / 100 : null;
            $line      = [$row['product_id'], ProductSearch::label($row['product_id'])];
            if ($multi) {
                $line[] = (int) $row['location_id'];
                $line[] = Locations::name((int) $row['location_id']);
            }
            $csv[] = array_merge($line, [
                self::qty($row['open_qty']),
                self::qty($row['uncosted_qty']),
                self::qty($row['estimate_qty']),
                self::qty($row['provisional_qty']),
                $avg !== null ? number_format($avg, 2, ',', '') : '',
                number_format($row['value_ore'] / 100, 2, ',', ''),
            ]);
        }
        $total = ['', 'TOTAL'];
        if ($multi) {
            $total = array_merge($total, ['', '']);
        }
        $csv[] = array_merge($total, [
            self::qty($totals['open_qty']),
            self::qty($totals['uncosted_qty']),
            self::qty($totals['estimate_qty']),
            self::qty($totals['provisional_qty']),
            '',
            number_format($totals['total_ore'] / 100, 2, ',', ''),
        ]);

        Screen::streamCsv('lagerverdi-' . $asOfRaw . '.csv', $header, $csv);
    }

    /* ------------------------------ Helpers ------------------------------- */

    private static function notices(): void {
        $detail = Screen::detail();
        Screen::notices(
            ['opening_saved' => \__('Opening cost saved — value on hand updated.', 'kaupang-stock')],
            [
                'opening_input' => \__('Enter a non-negative unit cost in kr.', 'kaupang-stock'),
                'opening'       => $detail !== '' ? $detail : \__('Could not save the opening cost.', 'kaupang-stock'),
            ]
        );
    }

    /** Origin/reason → Norwegian chip label (layers reuse movement reasons). */
    private static function originLabel(string $origin): string {
        if ($origin === 'opening') {
            return \__('Opening cost', 'kaupang-stock');
        }
        if ($origin === 'correction') {
            return \__('Cost correction', 'kaupang-stock');
        }
        return Reasons::label($origin);
    }

    private static function kindLabel(string $kind): string {
        $labels = [
            'fifo'          => \__('FIFO', 'kaupang-stock'),
            'provisional'   => \__('Provisional', 'kaupang-stock'),
            'prov_reversal' => \__('True-up (reversal)', 'kaupang-stock'),
            'backfill'      => \__('True-up (backfill)', 'kaupang-stock'),
            'correction'    => \__('Correction', 'kaupang-stock'),
            'transfer_out'  => \__('Transfer out', 'kaupang-stock'),
        ];
        return $labels[$kind] ?? $kind;
    }

    /** @param array<string,mixed> $layer */
    private static function refLabel(array $layer): string {
        $refType = isset($layer['ref_type']) ? (string) $layer['ref_type'] : '';
        $refId   = isset($layer['ref_id']) ? (int) $layer['ref_id'] : 0;
        $note    = isset($layer['note']) && $layer['note'] !== null ? (string) $layer['note'] : '';
        if ($refType === 'po_line' && $refId > 0) {
            return sprintf('PO-linje #%d', $refId);
        }
        if ($refType === 'order' && $refId > 0) {
            return sprintf('Ordre #%d', $refId);
        }
        if ($refType === 'cost_layer' && $refId > 0) {
            return sprintf(\__('corrects layer #%d', 'kaupang-stock'), $refId) . ($note !== '' ? ' · ' . $note : '');
        }
        return $note;
    }

    /** Escaped HTML for a consumption ref (order link when it exists). @param array<string,mixed> $c */
    private static function consumptionRef(array $c): string {
        $refType = isset($c['ref_type']) ? (string) $c['ref_type'] : '';
        $refId   = isset($c['ref_id']) ? (int) $c['ref_id'] : 0;
        if ($refType === 'order' && $refId > 0) {
            $order = \wc_get_order($refId);
            if ($order instanceof \WC_Order) {
                return '<a href="' . \esc_url($order->get_edit_order_url()) . '">' . \esc_html(sprintf('Ordre #%d', $refId)) . '</a>';
            }
            return \esc_html(sprintf('Ordre #%d', $refId));
        }
        if ($refType === 'po_line' && $refId > 0) {
            return \esc_html(sprintf('PO-linje #%d', $refId));
        }
        if ($refType !== '' && $refId > 0) {
            return \esc_html($refType . ' #' . $refId);
        }
        return '—';
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args): string {
        return \add_query_arg(
            array_merge(['page' => Menu::SLUG_VALUATION], $args),
            \admin_url('admin.php')
        );
    }

    private static function qty(float $q): string {
        if (abs($q - round($q)) < 1e-9) {
            return (string) (int) round($q);
        }
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    }

    private static function kr(int $ore): string {
        return \number_format_i18n($ore / 100, 2) . ' kr';
    }

    private static function localTime(string $utc): string {
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return $utc;
        }
        return \wp_date('Y-m-d H:i', $ts) ?: $utc;
    }
}
