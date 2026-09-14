<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Counting\CountLines;
use Kaupang\Stock\Counting\Import;
use Kaupang\Stock\Counting\Counts;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;
use Kaupang\Stock\Support\Assets;

/**
 * The Varetelling screen (§6.3). Three views routed off ?view= / ?count=:
 *
 *  - list (default): every count + a "Ny telling" create form.
 *  - capture (open): the sheet as a list-table plus ONE fixed scan-input bar that
 *    drives per-row increments over REST (keyboard-wedge scanner, Enter-terminated).
 *  - review: the three-column expected / counted / differanse grid, recount chips,
 *    override, per-line "movements since count started" drill-down, and apply.
 *
 * The shell's Menu routes the submenu slug to render() and registers the shared
 * `kaupang-stock-admin` asset handles; this page enqueues its own counting.{css,js}
 * with those handles as dependencies (so the localized KaupangStock global is
 * present). admin-post handlers own create, lifecycle transitions, and the CSV
 * round-trip — each nonce- and capability-checked. Direct $wpdb never appears here:
 * count documents go through Counts/CountLines, stock only ever through the Ledger.
 */
final class CountsPage {

    private const SLUG = 'kaupang-stock-counts';

    private const A_CREATE    = 'kaupang_stock_count_create';
    private const A_TRANSITION = 'kaupang_stock_count_transition';
    private const A_EXPORT    = 'kaupang_stock_count_export';
    private const A_IMPORT    = 'kaupang_stock_count_import';

    public static function register(): void {
        \add_action('admin_post_' . self::A_CREATE, [self::class, 'handleCreate']);
        \add_action('admin_post_' . self::A_TRANSITION, [self::class, 'handleTransition']);
        \add_action('admin_post_' . self::A_EXPORT, [self::class, 'handleExport']);
        \add_action('admin_post_' . self::A_IMPORT, [self::class, 'handleImport']);
        \add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    /**
     * Enqueue counting.{css,js} on the counts screen only, with the shell's
     * `kaupang-stock-admin` handles as dependencies (those carry the localized
     * KaupangStock global the JS reads for restUrl + nonce).
     */
    public static function assets(string $hook): void {
        $page = isset($_GET['page']) ? \sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ($page !== self::SLUG) {
            return;
        }
        \wp_enqueue_style(
            'kaupang-stock-counting',
            KAUPANG_STOCK_URL . 'assets/counting.css',
            ['kaupang-stock-admin'],
            Assets::ver('assets/counting.css')
        );
        \wp_enqueue_script(
            'kaupang-stock-counting',
            KAUPANG_STOCK_URL . 'assets/counting.js',
            ['kaupang-stock-admin'],
            Assets::ver('assets/counting.js'),
            true
        );
    }

    /* ------------------------------ Router -------------------------------- */

    public static function render(): void {
        if (!\current_user_can(Settings::capability())) {
            return;
        }
        $countId = isset($_GET['count']) ? (int) $_GET['count'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $view    = isset($_GET['view']) ? \sanitize_key((string) $_GET['view']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($countId > 0) {
            $count = Counts::find($countId);
            if ($count === null) {
                self::renderList();
                return;
            }
            $status = (string) $count['status'];
            // The requested view can only be capture or review; otherwise pick the
            // natural one for the status (open → capture, else review).
            if ($view !== 'capture' && $view !== 'review') {
                $view = $status === Counts::STATUS_OPEN ? 'capture' : 'review';
            }
            if ($view === 'capture') {
                self::renderCapture($count);
            } else {
                self::renderReview($count);
            }
            return;
        }

        self::renderList();
    }

    /* ------------------------------ List view ----------------------------- */

    private static function renderList(): void {
        $counts = Counts::all();
        ?>
        <div class="wrap ks-counts">
            <h1><?php \esc_html_e('Varetelling', 'kaupang-stock'); ?></h1>
            <?php self::notices(); ?>

            <?php self::renderCreateForm(); ?>

            <h2 style="margin-top:2em"><?php \esc_html_e('Counts', 'kaupang-stock'); ?></h2>
            <div class="ks-tablewrap">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php \esc_html_e('Status', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Scope', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Blind', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Progress', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Created', 'kaupang-stock'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($counts)): ?>
                    <tr><td colspan="7"><?php \esc_html_e('No counts yet.', 'kaupang-stock'); ?></td></tr>
                <?php else: foreach ($counts as $c):
                    $id       = (int) $c['id'];
                    $status   = (string) $c['status'];
                    $progress = Counts::progress($id);
                    ?>
                    <tr>
                        <td><strong>#<?php echo (int) $id; ?></strong></td>
                        <td><?php echo self::statusChip($status); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td><?php echo \esc_html((string) ($c['scope'] ?? '')); ?></td>
                        <td><?php echo !empty($c['blind']) ? \esc_html__('Yes', 'kaupang-stock') : \esc_html__('No', 'kaupang-stock'); ?></td>
                        <td>
                            <?php echo (int) $progress['counted'] . ' / ' . (int) $progress['lines']; ?>
                            <?php if ($progress['recount'] > 0): ?>
                                <span class="ks-chip ks-chip--recount"><?php echo (int) $progress['recount']; ?> <?php \esc_html_e('recount', 'kaupang-stock'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo \esc_html(\get_date_from_gmt((string) $c['created_at'], 'Y-m-d H:i')); ?></td>
                        <td><?php echo self::rowActions($id, $status); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    private static function renderCreateForm(): void {
        $categories = self::categoryChoices();
        ?>
        <h2><?php \esc_html_e('Ny telling', 'kaupang-stock'); ?></h2>
        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" class="ks-create-form">
            <?php \wp_nonce_field(self::A_CREATE); ?>
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::A_CREATE); ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ks-scope"><?php \esc_html_e('Scope', 'kaupang-stock'); ?></label></th>
                    <td>
                        <select name="scope" id="ks-scope" class="ks-scope-select">
                            <option value="all"><?php \esc_html_e('All stock-managed products', 'kaupang-stock'); ?></option>
                            <option value="category"><?php \esc_html_e('One product category', 'kaupang-stock'); ?></option>
                            <option value="manual"><?php \esc_html_e('Manual selection', 'kaupang-stock'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr class="ks-scope-row ks-scope-row--category" style="display:none">
                    <th scope="row"><label for="ks-category"><?php \esc_html_e('Category', 'kaupang-stock'); ?></label></th>
                    <td>
                        <select name="category" id="ks-category">
                            <option value="0">&mdash;</option>
                            <?php foreach ($categories as $termId => $name): ?>
                                <option value="<?php echo (int) $termId; ?>"><?php echo \esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr class="ks-scope-row ks-scope-row--manual" style="display:none">
                    <th scope="row"><label for="ks-product-filter"><?php \esc_html_e('Products', 'kaupang-stock'); ?></label></th>
                    <td>
                        <div class="ks-picker">
                            <input type="text" id="ks-product-filter" class="regular-text" autocomplete="off"
                                   placeholder="<?php \esc_attr_e('Filter by name or SKU…', 'kaupang-stock'); ?>" />
                            <div class="ks-picker__list" role="group" aria-label="<?php \esc_attr_e('Stock-managed products', 'kaupang-stock'); ?>">
                                <?php foreach (self::managedProductChoices() as $choice): ?>
                                    <label class="ks-picker__item" data-search="<?php echo \esc_attr(mb_strtolower($choice['title'] . ' ' . $choice['sku'])); ?>">
                                        <input type="checkbox" name="products[]" value="<?php echo (int) $choice['id']; ?>" />
                                        <?php echo \esc_html($choice['title']); ?>
                                        <?php if ($choice['sku'] !== ''): ?><code><?php echo \esc_html($choice['sku']); ?></code><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php \esc_html_e('Only stock-managed products are listed.', 'kaupang-stock'); ?></p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php \esc_html_e('Blind count', 'kaupang-stock'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="blind" value="1" checked />
                            <?php \esc_html_e('Hide the expected quantity while counting (recommended).', 'kaupang-stock'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-note"><?php \esc_html_e('Note', 'kaupang-stock'); ?></label></th>
                    <td><input type="text" name="note" id="ks-note" class="regular-text" maxlength="200" /></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php \esc_html_e('Create count', 'kaupang-stock'); ?></button>
            </p>
        </form>
        <?php
    }

    /* ------------------------------ Capture view -------------------------- */

    private static function renderCapture(array $count): void {
        $countId = (int) $count['id'];
        $blind   = !empty($count['blind']);
        $lines   = Counts::lines($countId);
        $rows    = self::decorateLines($lines);

        // Per-row SKU map (server-rendered) so the scanner resolves a line
        // client-side; repeated scans increment via REST.
        $skuMap = [];
        foreach ($rows as $r) {
            if ($r['sku'] !== '') {
                $skuMap[$r['sku']] = $r['id'];
            }
        }
        ?>
        <div class="wrap ks-counts ks-capture <?php echo $blind ? 'ks-capture--blind' : ''; ?>"
             data-count-id="<?php echo (int) $countId; ?>"
             data-sku-map="<?php echo \esc_attr((string) \wp_json_encode($skuMap)); ?>">
            <h1>
                <?php echo \esc_html(sprintf(\__('Varetelling #%d — capture', 'kaupang-stock'), $countId)); ?>
                <?php echo self::statusChip((string) $count['status']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </h1>
            <?php self::notices(); ?>
            <p class="ks-back"><a href="<?php echo \esc_url(self::listUrl()); ?>">&larr; <?php \esc_html_e('All counts', 'kaupang-stock'); ?></a></p>

            <div class="ks-capture__toolbar">
                <label class="ks-blind-toggle">
                    <input type="checkbox" id="ks-show-expected" <?php echo $blind ? '' : 'checked'; ?> />
                    <?php \esc_html_e('Vis forventet', 'kaupang-stock'); ?>
                </label>
                <?php echo self::transitionButton($countId, 'to_review', \__('Til gjennomgang', 'kaupang-stock'), 'button-primary'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php echo self::exportButton($countId); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>

            <div class="ks-scanbar">
                <input type="text" id="ks-scan-input" class="ks-scanbar__input" autocomplete="off" autofocus
                       placeholder="<?php \esc_attr_e('Scan or type a SKU, then Enter…', 'kaupang-stock'); ?>" />
                <span class="ks-scanbar__status" id="ks-scan-status" role="status" aria-live="polite"></span>
            </div>

            <div class="ks-tablewrap">
            <table class="wp-list-table widefat striped ks-sheet">
                <thead>
                    <tr>
                        <th><?php \esc_html_e('SKU', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <th class="ks-col-expected"><?php \esc_html_e('Forventet', 'kaupang-stock'); ?></th>
                        <th><?php \esc_html_e('Counted', 'kaupang-stock'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr data-line-id="<?php echo (int) $r['id']; ?>" data-sku="<?php echo \esc_attr($r['sku']); ?>">
                        <td><code><?php echo \esc_html($r['sku'] !== '' ? $r['sku'] : '—'); ?></code></td>
                        <td><?php echo \esc_html($r['title']); ?></td>
                        <td class="ks-col-expected"><?php echo \esc_html(self::fmt($r['expected'])); ?></td>
                        <td>
                            <input type="number" step="1" min="0" class="ks-count-input small-text"
                                   value="<?php echo $r['counted'] === null ? '' : \esc_attr(self::fmt($r['counted'])); ?>" />
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    /* ------------------------------ Review view --------------------------- */

    private static function renderReview(array $count): void {
        $countId = (int) $count['id'];
        $status  = (string) $count['status'];
        $lines   = Counts::lines($countId);
        $rows    = self::decorateLines($lines);
        $since   = (string) $count['created_at']; // UTC; drill-down cutoff

        $recountCount = 0;
        $uncounted    = 0;
        foreach ($rows as $r) {
            if ($r['recount']) {
                $recountCount++;
            }
            if ($r['counted'] === null) {
                $uncounted++;
            }
        }
        $applied  = $status === Counts::STATUS_APPLIED;
        $canApply = $status === Counts::STATUS_REVIEW;
        ?>
        <div class="wrap ks-counts ks-review"
             data-count-id="<?php echo (int) $countId; ?>"
             data-since="<?php echo \esc_attr($since); ?>">
            <h1>
                <?php echo \esc_html(sprintf(\__('Varetelling #%d', 'kaupang-stock'), $countId)); ?>
                <?php echo self::statusChip($status); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </h1>
            <?php self::notices(); ?>
            <p class="ks-back"><a href="<?php echo \esc_url(self::listUrl()); ?>">&larr; <?php \esc_html_e('All counts', 'kaupang-stock'); ?></a></p>

            <?php if ((string) ($count['note'] ?? '') !== ''): ?>
                <p class="ks-note-trail"><strong><?php \esc_html_e('Note:', 'kaupang-stock'); ?></strong> <?php echo \esc_html((string) $count['note']); ?></p>
            <?php endif; ?>

            <div class="ks-review__toolbar">
                <?php if ($canApply): ?>
                    <?php echo self::transitionButton($countId, 'reopen', \__('Reopen for counting', 'kaupang-stock'), 'button'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <button type="button" class="button button-primary ks-apply-btn"
                            <?php echo $recountCount > 0 ? 'disabled' : ''; ?>>
                        <?php \esc_html_e('Bruk telling', 'kaupang-stock'); ?>
                    </button>
                    <?php if ($recountCount > 0): ?>
                        <span class="description"><?php echo \esc_html(sprintf(\__('%d line(s) need a recount or override before applying.', 'kaupang-stock'), $recountCount)); ?></span>
                    <?php endif; ?>
                    <?php echo self::transitionButton($countId, 'cancel', \__('Cancel count', 'kaupang-stock'), 'button ks-danger', true); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php elseif ($applied): ?>
                    <p class="description"><?php echo \esc_html(sprintf(\__('Applied %s by %s.', 'kaupang-stock'),
                        \get_date_from_gmt((string) ($count['applied_at'] ?? gmdate('Y-m-d H:i:s')), 'Y-m-d H:i'),
                        self::userName((int) ($count['applied_by'] ?? 0)))); ?></p>
                <?php endif; ?>
                <?php echo self::exportButton($countId); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>

            <div class="ks-tablewrap">
            <table class="wp-list-table widefat striped ks-grid">
                <thead>
                    <tr>
                        <th><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Forventet', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Counted', 'kaupang-stock'); ?></th>
                        <th class="ks-num"><?php \esc_html_e('Differanse', 'kaupang-stock'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $diff  = $r['counted'] === null ? null : $r['counted'] - $r['expected'];
                    $klass = $diff === null ? '' : ($diff > 0 ? 'ks-pos' : ($diff < 0 ? 'ks-neg' : 'ks-zero'));
                    ?>
                    <tr data-line-id="<?php echo (int) $r['id']; ?>" data-product-id="<?php echo (int) $r['product_id']; ?>">
                        <td>
                            <?php echo \esc_html($r['title']); ?>
                            <?php if ($r['sku'] !== ''): ?><code class="ks-sku"><?php echo \esc_html($r['sku']); ?></code><?php endif; ?>
                            <?php if ($r['recount']): ?>
                                <span class="ks-chip ks-chip--recount"><?php \esc_html_e('recount', 'kaupang-stock'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ks-num ks-cell-expected"><?php echo \esc_html(self::fmt($r['expected'])); ?></td>
                        <td class="ks-num ks-cell-counted"><?php echo $r['counted'] === null ? '<em>—</em>' : \esc_html(self::fmt($r['counted'])); ?></td>
                        <td class="ks-num ks-cell-diff <?php echo \esc_attr($klass); ?>"><?php echo $diff === null ? '' : \esc_html(self::fmtSigned($diff)); ?></td>
                        <td class="ks-row-actions">
                            <button type="button" class="button-link ks-drill-btn" aria-expanded="false"><?php \esc_html_e('Movements since', 'kaupang-stock'); ?></button>
                            <?php if ($canApply && $r['recount']): ?>
                                <button type="button" class="button-link ks-override-btn"><?php \esc_html_e('Override', 'kaupang-stock'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="ks-drill-row" hidden><td colspan="5" class="ks-drill-cell"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($uncounted > 0 && $canApply): ?>
                <p class="description ks-uncounted-note">
                    <?php echo \esc_html(sprintf(\__('%d line(s) have not been counted. They post nothing and will require a combined confirmation at apply.', 'kaupang-stock'), $uncounted)); ?>
                </p>
            <?php endif; ?>
        </div>

        <?php self::renderImportForm($count); ?>
        <?php
    }

    private static function renderImportForm(array $count): void {
        $status = (string) $count['status'];
        // Import re-fills counted and lands in review — offered while still open or
        // in review, never once applied/cancelled.
        if (!in_array($status, [Counts::STATUS_OPEN, Counts::STATUS_REVIEW], true)) {
            return;
        }
        ?>
        <div class="ks-import">
            <h2><?php \esc_html_e('Import counted quantities (CSV)', 'kaupang-stock'); ?></h2>
            <p class="description"><?php \esc_html_e('Upload the exported sheet with the counted column filled. Matching is by SKU (falling back to a product id column if present). The count lands in review — never applied directly.', 'kaupang-stock'); ?></p>
            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php \wp_nonce_field(self::A_IMPORT); ?>
                <input type="hidden" name="action" value="<?php echo \esc_attr(self::A_IMPORT); ?>" />
                <input type="hidden" name="count" value="<?php echo (int) $count['id']; ?>" />
                <input type="file" name="sheet" accept=".csv,text/csv" required />
                <button type="submit" class="button"><?php \esc_html_e('Upload', 'kaupang-stock'); ?></button>
            </form>
        </div>
        <?php
    }

    /* ------------------------------ Handlers ------------------------------ */

    public static function handleCreate(): void {
        Screen::guard(self::A_CREATE);

        $scope = isset($_POST['scope']) ? \sanitize_key((string) $_POST['scope']) : Counts::SCOPE_ALL;
        $args  = [
            'scope' => $scope,
            'blind' => !empty($_POST['blind']),
            'note'  => isset($_POST['note']) ? \sanitize_text_field((string) \wp_unslash($_POST['note'])) : '',
        ];
        if ($scope === Counts::SCOPE_CATEGORY) {
            $args['category'] = isset($_POST['category']) ? (int) $_POST['category'] : 0;
        }
        if ($scope === Counts::SCOPE_MANUAL) {
            $raw               = isset($_POST['products']) ? (array) \wp_unslash($_POST['products']) : [];
            $args['products']  = array_values(array_filter(array_map('intval', $raw)));
        }

        $countId = Counts::create($args);
        if ($countId <= 0) {
            Screen::redirect(Menu::SLUG_COUNTS, ['ks_err' => 'create_failed']);
        }
        $lines = Counts::progress($countId)['lines'];
        if ($lines === 0) {
            // A scope that matched nothing still creates the document; warn.
            Screen::redirect(Menu::SLUG_COUNTS, ['count' => $countId, 'view' => 'capture', 'ks_msg' => 'empty_scope']);
        }
        Screen::redirect(Menu::SLUG_COUNTS, ['count' => $countId, 'view' => 'capture', 'ks_msg' => 'created']);
    }

    public static function handleTransition(): void {
        Screen::guard(self::A_TRANSITION);
        $countId = isset($_POST['count']) ? (int) $_POST['count'] : 0;
        $to      = isset($_POST['to']) ? \sanitize_key((string) $_POST['to']) : '';

        $ok   = false;
        $dest = [];
        switch ($to) {
            case 'to_review':
                $ok   = Counts::toReview($countId);
                $dest = ['count' => $countId, 'view' => 'review'];
                break;
            case 'reopen':
                $ok   = Counts::reopen($countId);
                $dest = ['count' => $countId, 'view' => 'capture'];
                break;
            case 'cancel':
                $ok   = Counts::cancel($countId);
                break;
        }
        Screen::redirect(Menu::SLUG_COUNTS, $dest + ($ok ? ['ks_msg' => 'transition'] : ['ks_err' => 'transition_failed']));
    }

    /**
     * CSV export of the sheet (§6.7): columns sku, product, counted — plus an
     * `expected` column ONLY when the count is not blind. Streamed as a download.
     */
    public static function handleExport(): void {
        Screen::guard(self::A_EXPORT, 'GET');
        $countId = isset($_GET['count']) ? (int) $_GET['count'] : 0;
        $count   = Counts::find($countId);
        if ($count === null) {
            Screen::redirect(Menu::SLUG_COUNTS, ['ks_err' => 'not_found']);
        }
        $blind = !empty($count['blind']);
        $rows  = self::decorateLines(Counts::lines($countId));

        $header = ['sku', 'product', 'counted'];
        if (!$blind) {
            $header[] = 'expected';
        }
        // A product_id column makes re-import robust when SKUs are blank.
        $header[] = 'product_id';

        $csv = [];
        foreach ($rows as $r) {
            $line = [
                $r['sku'],
                $r['title'],
                $r['counted'] === null ? '' : self::fmt($r['counted']),
            ];
            if (!$blind) {
                $line[] = self::fmt($r['expected']);
            }
            $line[] = $r['product_id'];
            $csv[]  = $line;
        }
        Screen::streamCsv('varetelling-' . $countId . '.csv', $header, $csv, ',');
    }

    /**
     * CSV import (§6.7): fill `counted` for matching lines (by sku, falling back to
     * a product_id column), move the count to review, never apply. The parse and
     * the SKU match live in Counting\Import.
     */
    public static function handleImport(): void {
        Screen::guard(self::A_IMPORT);
        $countId = isset($_POST['count']) ? (int) $_POST['count'] : 0;
        $count   = Counts::find($countId);
        if ($count === null) {
            Screen::redirect(Menu::SLUG_COUNTS, ['ks_err' => 'not_found']);
        }
        if (!in_array((string) $count['status'], [Counts::STATUS_OPEN, Counts::STATUS_REVIEW], true)) {
            Screen::redirect(Menu::SLUG_COUNTS, ['count' => $countId, 'view' => 'review', 'ks_err' => 'import_wrong_status']);
        }
        if (empty($_FILES['sheet']['tmp_name']) || !is_uploaded_file((string) $_FILES['sheet']['tmp_name'])) {
            Screen::redirect(Menu::SLUG_COUNTS, ['count' => $countId, 'view' => 'review', 'ks_err' => 'no_file']);
        }

        $handle = fopen((string) $_FILES['sheet']['tmp_name'], 'r');
        if ($handle === false) {
            Screen::redirect(Menu::SLUG_COUNTS, ['count' => $countId, 'view' => 'review', 'ks_err' => 'parse_failed']);
        }
        if (fgets($handle) === false) {
            fclose($handle);
            Screen::redirect(Menu::SLUG_COUNTS, ['count' => $countId, 'view' => 'review', 'ks_err' => 'parse_failed']);
        }
        rewind($handle);
        $parsed = Import::parse($handle);
        fclose($handle);

        // Fill the count's own lines from the parsed product → counted map.
        $applied = 0;
        foreach (Counts::lines($countId) as $line) {
            $productId = (int) $line['product_id'];
            if (isset($parsed['lines'][$productId])
                && CountLines::setCounted((int) $line['id'], $parsed['lines'][$productId]) !== null
            ) {
                $applied++;
            }
        }

        // Land in review (never applied). Only transitions from open.
        if ((string) $count['status'] === Counts::STATUS_OPEN) {
            Counts::toReview($countId);
        }
        Screen::redirect(Menu::SLUG_COUNTS, ['count' => $countId, 'view' => 'review', 'ks_msg' => 'imported', 'n' => $applied]);
    }

    /* ------------------------------ Decorate ------------------------------ */

    /**
     * Attach title + SKU to raw line rows in a single query batch (no WC_Product
     * loop). Returns typed, render-ready rows.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array{id:int,product_id:int,title:string,sku:string,expected:float,counted:?float,recount:bool}>
     */
    private static function decorateLines(array $lines): array {
        $meta = ProductSearch::rows(array_map(static fn ($l): int => (int) $l['product_id'], $lines));

        $out = [];
        foreach ($lines as $l) {
            $pid   = (int) $l['product_id'];
            $title = $meta[$pid]['title'] ?? ('#' . $pid);
            $out[] = [
                'id'         => (int) $l['id'],
                'product_id' => $pid,
                'title'      => $title,
                'sku'        => $meta[$pid]['sku'] ?? '',
                'expected'   => (float) $l['expected'],
                'counted'    => $l['counted'] === null ? null : (float) $l['counted'],
                'recount'    => (int) $l['recount'] === 1,
            ];
        }
        return $out;
    }

    /* ------------------------------ URLs & UI ----------------------------- */

    private static function listUrl(): string {
        return \admin_url('admin.php?page=' . self::SLUG);
    }

    private static function captureUrl(int $countId): string {
        return \add_query_arg(['page' => self::SLUG, 'count' => $countId, 'view' => 'capture'], \admin_url('admin.php'));
    }

    private static function reviewUrl(int $countId): string {
        return \add_query_arg(['page' => self::SLUG, 'count' => $countId, 'view' => 'review'], \admin_url('admin.php'));
    }

    private static function statusChip(string $status): string {
        $labels = [
            Counts::STATUS_OPEN      => \__('Open', 'kaupang-stock'),
            Counts::STATUS_REVIEW    => \__('Til gjennomgang', 'kaupang-stock'),
            Counts::STATUS_APPLIED   => \__('Applied', 'kaupang-stock'),
            Counts::STATUS_CANCELLED => \__('Cancelled', 'kaupang-stock'),
        ];
        $label = $labels[$status] ?? $status;
        return '<span class="ks-chip ks-chip--' . \esc_attr($status) . '">' . \esc_html($label) . '</span>';
    }

    private static function rowActions(int $countId, string $status): string {
        $links = [];
        if ($status === Counts::STATUS_OPEN) {
            $links[] = '<a href="' . \esc_url(self::captureUrl($countId)) . '">' . \esc_html__('Capture', 'kaupang-stock') . '</a>';
            $links[] = '<a href="' . \esc_url(self::reviewUrl($countId)) . '">' . \esc_html__('Review', 'kaupang-stock') . '</a>';
        } else {
            $links[] = '<a href="' . \esc_url(self::reviewUrl($countId)) . '">' . \esc_html__('Open', 'kaupang-stock') . '</a>';
        }
        return implode(' | ', $links);
    }

    /** A small POST form button for a lifecycle transition (nonce-protected). */
    private static function transitionButton(int $countId, string $to, string $label, string $class, bool $confirm = false): string {
        $onclick = $confirm
            ? ' onclick="return confirm(\'' . \esc_js(__('Cancel this count? This cannot be undone.', 'kaupang-stock')) . '\');"'
            : '';
        ob_start();
        ?>
        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" style="display:inline">
            <?php \wp_nonce_field(self::A_TRANSITION); ?>
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::A_TRANSITION); ?>" />
            <input type="hidden" name="count" value="<?php echo (int) $countId; ?>" />
            <input type="hidden" name="to" value="<?php echo \esc_attr($to); ?>" />
            <button type="submit" class="button <?php echo \esc_attr($class); ?>"<?php echo $onclick; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo \esc_html($label); ?></button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private static function exportButton(int $countId): string {
        $url = \wp_nonce_url(
            \add_query_arg(['action' => self::A_EXPORT, 'count' => $countId], \admin_url('admin-post.php')),
            self::A_EXPORT
        );
        return '<a class="button" href="' . \esc_url($url) . '">' . \esc_html__('Export CSV', 'kaupang-stock') . '</a>';
    }

    /* ------------------------------ Helpers ------------------------------- */

    /** @return array<int,string> term_id => name (flat, indented by depth) */
    private static function categoryChoices(): array {
        if (!function_exists('get_terms')) {
            return [];
        }
        $terms = \get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (!is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $t) {
            if ($t instanceof \WP_Term) {
                $out[(int) $t->term_id] = $t->name;
            }
        }
        asort($out);
        return $out;
    }

    /**
     * Stock-managed products for the manual-pick checkbox list, title + SKU
     * resolved in one query. Single-sheet counts are the 25-product reality, so a
     * server-rendered, client-filtered list needs no extra REST surface.
     *
     * @return array<int,array{id:int,title:string,sku:string}>
     */
    private static function managedProductChoices(): array {
        return array_values(ProductSearch::rows(\Kaupang\Stock\Observe\Seeder::stockManagedProductIds()));
    }

    private static function userName(int $userId): string {
        if ($userId <= 0) {
            return \__('system', 'kaupang-stock');
        }
        $user = \get_userdata($userId);
        return $user instanceof \WP_User && $user->display_name !== '' ? $user->display_name : ('#' . $userId);
    }

    private static function fmt(float $n): string {
        // Integer v1: show without decimals when whole (the common case).
        return abs($n - round($n)) < 1e-9 ? (string) (int) round($n) : rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    private static function fmtSigned(float $n): string {
        $s = self::fmt(abs($n));
        if ($n > 0) {
            return '+' . $s;
        }
        if ($n < 0) {
            return '−' . $s;
        }
        return '0';
    }

    private static function notices(): void {
        $n = isset($_GET['n']) ? (int) $_GET['n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        Screen::notices(
            [
                'created'     => \__('Count created. Start scanning below.', 'kaupang-stock'),
                'empty_scope' => [\__('The count was created but no stock-managed products matched the scope.', 'kaupang-stock'), 'warning'],
                'transition'  => \__('Count updated.', 'kaupang-stock'),
                'imported'    => sprintf(
                    /* translators: %d: number of matched lines filled from the CSV. */
                    \_n('%d line filled from the CSV. The count is now in review.', '%d lines filled from the CSV. The count is now in review.', $n, 'kaupang-stock'),
                    $n
                ),
            ],
            [
                'create_failed'       => \__('Could not create the count.', 'kaupang-stock'),
                'transition_failed'   => \__('That status change is not allowed.', 'kaupang-stock'),
                'not_found'           => \__('Count not found.', 'kaupang-stock'),
                'import_wrong_status' => \__('This count can no longer be imported into.', 'kaupang-stock'),
                'no_file'             => \__('No CSV file was uploaded.', 'kaupang-stock'),
                'parse_failed'        => \__('The CSV could not be read.', 'kaupang-stock'),
            ]
        );
    }
}
