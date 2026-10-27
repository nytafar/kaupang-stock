<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Counting\Counts;
use Kaupang\Stock\Support\ProductSearch;

/**
 * The Varetelling sheets — capture and review — split out of CountsPage, which
 * keeps the router, list, create form and admin-post handlers.
 *
 * A count with one location (every single-location store, every count migrated
 * from schema v6) renders exactly the v1 markup: one row per line, no location
 * toggles, no location columns. A count that spans several locations renders one
 * row per product and one column group per location (PRD-04 §2), with display-only
 * location toggles remembered per device and a "Teller i" picker for the scanner;
 * both live in counting.js. Hidden columns stay uncounted, and uncounted posts
 * nothing.
 */
final class CountSheet {

    /* ------------------------------ Capture ------------------------------- */

    public static function capture(array $count): void {
        $countId   = (int) $count['id'];
        $blind     = !empty($count['blind']);
        $locations = Counts::locations($countId);
        $multi     = count($locations) > 1;
        $lines     = Counts::lines($countId);

        // SKU → {location id → line id} (server-rendered) so the scanner resolves
        // a line client-side; repeated scans increment via REST.
        $skuMap = [];
        foreach (self::decorate($lines) as $r) {
            if ($r['sku'] !== '') {
                $skuMap[$r['sku']][$r['location_id']] = $r['id'];
            }
        }
        ?>
        <div class="wrap ks-counts ks-capture <?php echo $blind ? 'ks-capture--blind' : ''; ?><?php echo $multi ? ' ks-multi' : ''; ?>"
             data-count-id="<?php echo (int) $countId; ?>"
             data-sku-map="<?php echo \esc_attr((string) \wp_json_encode((object) $skuMap)); ?>"
             <?php echo self::locationsAttr($locations); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
            <h1>
                <?php echo \esc_html(sprintf(\__('Varetelling #%d — capture', 'kaupang-stock'), $countId)); ?>
                <?php echo CountsPage::statusChip((string) $count['status']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </h1>
            <?php CountsPage::notices(); ?>
            <p class="ks-back"><a href="<?php echo \esc_url(CountsPage::listUrl()); ?>">&larr; <?php \esc_html_e('All counts', 'kaupang-stock'); ?></a></p>

            <div class="ks-capture__toolbar">
                <label class="ks-blind-toggle">
                    <input type="checkbox" id="ks-show-expected" <?php echo $blind ? '' : 'checked'; ?> />
                    <?php \esc_html_e('Vis forventet', 'kaupang-stock'); ?>
                </label>
                <?php if ($multi) { self::locationToggles($locations, true); } ?>
                <?php echo CountsPage::transitionButton($countId, 'to_review', \__('Til gjennomgang', 'kaupang-stock'), 'button-primary'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php echo CountsPage::exportButton($countId); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>

            <div class="ks-scanbar">
                <input type="text" id="ks-scan-input" class="ks-scanbar__input" autocomplete="off" autofocus
                       placeholder="<?php \esc_attr_e('Scan or type a SKU, then Enter…', 'kaupang-stock'); ?>" />
                <span class="ks-scanbar__status" id="ks-scan-status" role="status" aria-live="polite"></span>
            </div>

            <div class="ks-tablewrap">
            <?php $multi ? self::captureGrid($lines, $locations, $blind) : self::captureSheet($lines); ?>
            </div>
        </div>
        <?php
    }

    /** One-location sheet: the v1 markup, one row per line. */
    private static function captureSheet(array $lines): void {
        ?>
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
                <?php foreach (self::decorate($lines) as $r): ?>
                    <tr data-line-id="<?php echo (int) $r['id']; ?>" data-sku="<?php echo \esc_attr($r['sku']); ?>">
                        <td><code><?php echo \esc_html($r['sku'] !== '' ? $r['sku'] : '—'); ?></code></td>
                        <td><?php echo \esc_html($r['title']); ?></td>
                        <td class="ks-col-expected"><?php echo \esc_html(CountsPage::fmt($r['expected'])); ?></td>
                        <td>
                            <input type="number" step="1" min="0" class="ks-count-input small-text" data-line-id="<?php echo (int) $r['id']; ?>"
                                   value="<?php echo $r['counted'] === null ? '' : \esc_attr(CountsPage::fmt($r['counted'])); ?>" />
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php
    }

    /** Multi-location sheet: one row per product, Forventet + Counted per location. */
    private static function captureGrid(array $lines, array $locations, bool $blind): void {
        ?>
            <table class="wp-list-table widefat striped ks-sheet ks-sheet--multi">
                <thead>
                    <tr>
                        <th rowspan="2"><?php \esc_html_e('SKU', 'kaupang-stock'); ?></th>
                        <th rowspan="2"><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <?php foreach ($locations as $locId => $name): ?>
                            <th class="ks-loc-head" colspan="<?php echo $blind ? 1 : 2; ?>" data-loc="<?php echo (int) $locId; ?>" data-span="2"><?php echo \esc_html($name); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($locations as $locId => $name): ?>
                            <th class="ks-col-expected ks-loc-first" data-loc="<?php echo (int) $locId; ?>"><?php \esc_html_e('Forventet', 'kaupang-stock'); ?></th>
                            <th data-loc="<?php echo (int) $locId; ?>"><?php \esc_html_e('Counted', 'kaupang-stock'); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (self::byProduct($lines) as $p): ?>
                    <tr data-product-id="<?php echo (int) $p['product_id']; ?>" data-sku="<?php echo \esc_attr($p['sku']); ?>">
                        <td><code><?php echo \esc_html($p['sku'] !== '' ? $p['sku'] : '—'); ?></code></td>
                        <td><?php echo \esc_html($p['title']); ?></td>
                        <?php foreach ($locations as $locId => $name):
                            $c = $p['cells'][$locId] ?? null; ?>
                            <td class="ks-col-expected ks-loc-first" data-loc="<?php echo (int) $locId; ?>"><?php echo $c === null ? '' : \esc_html(CountsPage::fmt($c['expected'])); ?></td>
                            <td data-loc="<?php echo (int) $locId; ?>"<?php echo $c !== null && $c['recount'] ? ' class="ks-cell--recount"' : ''; ?>>
                                <?php if ($c === null): ?>—<?php else: ?>
                                <input type="number" step="1" min="0" class="ks-count-input small-text" data-line-id="<?php echo (int) $c['id']; ?>"
                                       aria-label="<?php echo \esc_attr($p['title'] . ' — ' . $name); ?>"
                                       value="<?php echo $c['counted'] === null ? '' : \esc_attr(CountsPage::fmt($c['counted'])); ?>" />
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php
    }

    /* ------------------------------ Review -------------------------------- */

    public static function review(array $count): void {
        $countId   = (int) $count['id'];
        $status    = (string) $count['status'];
        $lines     = Counts::lines($countId);
        $rows      = self::decorate($lines);
        $locations = Counts::locations($countId);
        $multi     = count($locations) > 1;
        $since     = (string) $count['created_at']; // UTC; drill-down cutoff

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
        <div class="wrap ks-counts ks-review<?php echo $multi ? ' ks-multi' : ''; ?>"
             data-count-id="<?php echo (int) $countId; ?>"
             data-since="<?php echo \esc_attr($since); ?>"
             <?php echo self::locationsAttr($locations); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
            <h1>
                <?php echo \esc_html(sprintf(\__('Varetelling #%d', 'kaupang-stock'), $countId)); ?>
                <?php echo CountsPage::statusChip($status); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </h1>
            <?php CountsPage::notices(); ?>
            <p class="ks-back"><a href="<?php echo \esc_url(CountsPage::listUrl()); ?>">&larr; <?php \esc_html_e('All counts', 'kaupang-stock'); ?></a></p>

            <?php if ((string) ($count['note'] ?? '') !== ''): ?>
                <p class="ks-note-trail"><strong><?php \esc_html_e('Note:', 'kaupang-stock'); ?></strong> <?php echo \esc_html((string) $count['note']); ?></p>
            <?php endif; ?>

            <div class="ks-review__toolbar">
                <?php if ($multi) { self::locationToggles($locations, false); } ?>
                <?php if ($canApply): ?>
                    <?php echo CountsPage::transitionButton($countId, 'reopen', \__('Reopen for counting', 'kaupang-stock'), 'button'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <button type="button" class="button button-primary ks-apply-btn"
                            <?php echo $recountCount > 0 ? 'disabled' : ''; ?>>
                        <?php \esc_html_e('Bruk telling', 'kaupang-stock'); ?>
                    </button>
                    <?php if ($recountCount > 0): ?>
                        <span class="description"><?php echo \esc_html(sprintf(\__('%d line(s) need a recount or override before applying.', 'kaupang-stock'), $recountCount)); ?></span>
                    <?php endif; ?>
                    <?php echo CountsPage::transitionButton($countId, 'cancel', \__('Cancel count', 'kaupang-stock'), 'button ks-danger', true); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php elseif ($applied): ?>
                    <p class="description"><?php echo \esc_html(sprintf(\__('Applied %s by %s.', 'kaupang-stock'),
                        \get_date_from_gmt((string) ($count['applied_at'] ?? gmdate('Y-m-d H:i:s')), 'Y-m-d H:i'),
                        CountsPage::userName((int) ($count['applied_by'] ?? 0)))); ?></p>
                <?php endif; ?>
                <?php echo CountsPage::exportButton($countId); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>

            <div class="ks-tablewrap">
            <?php $multi ? self::reviewGrid($lines, $locations, $canApply) : self::reviewSheet($rows, $canApply); ?>
            </div>

            <?php if ($uncounted > 0 && $canApply): ?>
                <p class="description ks-uncounted-note">
                    <?php echo \esc_html(sprintf(\__('%d line(s) have not been counted. They post nothing and will require a combined confirmation at apply.', 'kaupang-stock'), $uncounted)); ?>
                </p>
            <?php endif; ?>
        </div>

        <?php self::importForm($count); ?>
        <?php
    }

    /** One-location review grid: the v1 markup. */
    private static function reviewSheet(array $rows, bool $canApply): void {
        ?>
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
                    ?>
                    <tr data-line-id="<?php echo (int) $r['id']; ?>" data-product-id="<?php echo (int) $r['product_id']; ?>">
                        <td>
                            <?php echo \esc_html($r['title']); ?>
                            <?php if ($r['sku'] !== ''): ?><code class="ks-sku"><?php echo \esc_html($r['sku']); ?></code><?php endif; ?>
                            <?php if ($r['recount']): ?>
                                <span class="ks-chip ks-chip--recount"><?php \esc_html_e('recount', 'kaupang-stock'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ks-num ks-cell-expected"><?php echo \esc_html(CountsPage::fmt($r['expected'])); ?></td>
                        <td class="ks-num ks-cell-counted"><?php echo $r['counted'] === null ? '<em>—</em>' : \esc_html(CountsPage::fmt($r['counted'])); ?></td>
                        <td class="ks-num ks-cell-diff <?php echo \esc_attr(self::diffClass($diff)); ?>"><?php echo $diff === null ? '' : \esc_html(CountsPage::fmtSigned($diff)); ?></td>
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
        <?php
    }

    /**
     * Multi-location review grid: Forventet / Talt / Differanse grouped under each
     * location name. Recount chip and override sit in the location's cell — they
     * are per line, i.e. per location.
     */
    private static function reviewGrid(array $lines, array $locations, bool $canApply): void {
        $span = 2 + 3 * count($locations);
        ?>
            <table class="wp-list-table widefat striped ks-grid ks-grid--multi">
                <thead>
                    <tr>
                        <th rowspan="2"><?php \esc_html_e('Product', 'kaupang-stock'); ?></th>
                        <?php foreach ($locations as $locId => $name): ?>
                            <th class="ks-loc-head" colspan="3" data-loc="<?php echo (int) $locId; ?>"><?php echo \esc_html($name); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2"></th>
                    </tr>
                    <tr>
                        <?php foreach ($locations as $locId => $name): ?>
                            <th class="ks-num ks-loc-first" data-loc="<?php echo (int) $locId; ?>"><?php \esc_html_e('Forventet', 'kaupang-stock'); ?></th>
                            <th class="ks-num" data-loc="<?php echo (int) $locId; ?>"><?php \esc_html_e('Counted', 'kaupang-stock'); ?></th>
                            <th class="ks-num" data-loc="<?php echo (int) $locId; ?>"><?php \esc_html_e('Differanse', 'kaupang-stock'); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (self::byProduct($lines) as $p): ?>
                    <tr data-product-id="<?php echo (int) $p['product_id']; ?>">
                        <td>
                            <?php echo \esc_html($p['title']); ?>
                            <?php if ($p['sku'] !== ''): ?><code class="ks-sku"><?php echo \esc_html($p['sku']); ?></code><?php endif; ?>
                        </td>
                        <?php foreach ($locations as $locId => $name):
                            $c = $p['cells'][$locId] ?? null;
                            if ($c === null): ?>
                                <td class="ks-num ks-loc-first" data-loc="<?php echo (int) $locId; ?>"></td>
                                <td class="ks-num" data-loc="<?php echo (int) $locId; ?>"></td>
                                <td class="ks-num" data-loc="<?php echo (int) $locId; ?>"></td>
                            <?php continue; endif;
                            $diff = $c['counted'] === null ? null : $c['counted'] - $c['expected']; ?>
                            <td class="ks-num ks-cell-expected ks-loc-first" data-loc="<?php echo (int) $locId; ?>"><?php echo \esc_html(CountsPage::fmt($c['expected'])); ?></td>
                            <td class="ks-num ks-cell-counted" data-loc="<?php echo (int) $locId; ?>"><?php echo $c['counted'] === null ? '<em>—</em>' : \esc_html(CountsPage::fmt($c['counted'])); ?></td>
                            <td class="ks-num ks-cell-diff <?php echo \esc_attr(self::diffClass($diff)); ?>" data-loc="<?php echo (int) $locId; ?>" data-line-id="<?php echo (int) $c['id']; ?>">
                                <?php echo $diff === null ? '' : \esc_html(CountsPage::fmtSigned($diff)); ?>
                                <?php if ($c['recount']): ?>
                                    <span class="ks-chip ks-chip--recount"><?php \esc_html_e('recount', 'kaupang-stock'); ?></span>
                                    <?php if ($canApply): ?>
                                        <button type="button" class="button-link ks-override-btn"><?php \esc_html_e('Override', 'kaupang-stock'); ?></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="ks-row-actions">
                            <button type="button" class="button-link ks-drill-btn" aria-expanded="false"><?php \esc_html_e('Movements since', 'kaupang-stock'); ?></button>
                        </td>
                    </tr>
                    <tr class="ks-drill-row" hidden><td colspan="<?php echo (int) $span; ?>" class="ks-drill-cell"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php
    }

    /* ------------------------------ Import form --------------------------- */

    private static function importForm(array $count): void {
        $status = (string) $count['status'];
        // Import re-fills counted and lands in review — offered while still open or
        // in review, never once applied/cancelled.
        if (!in_array($status, [Counts::STATUS_OPEN, Counts::STATUS_REVIEW], true)) {
            return;
        }
        $multi = Counts::isMulti((int) $count['id']);
        ?>
        <div class="ks-import">
            <h2><?php \esc_html_e('Import counted quantities (CSV)', 'kaupang-stock'); ?></h2>
            <p class="description"><?php \esc_html_e('Upload the exported sheet with the counted column filled. Matching is by SKU (falling back to a product id column if present). The count lands in review — never applied directly.', 'kaupang-stock'); ?></p>
            <?php if ($multi): ?>
                <p class="description"><?php \esc_html_e('This count covers several locations: every row needs its location (the location_id or location column from the export). Rows without one are rejected.', 'kaupang-stock'); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php \wp_nonce_field(CountsPage::A_IMPORT); ?>
                <input type="hidden" name="action" value="<?php echo \esc_attr(CountsPage::A_IMPORT); ?>" />
                <input type="hidden" name="count" value="<?php echo (int) $count['id']; ?>" />
                <input type="file" name="sheet" accept=".csv,text/csv" required />
                <button type="submit" class="button"><?php \esc_html_e('Upload', 'kaupang-stock'); ?></button>
            </form>
        </div>
        <?php
    }

    /* ------------------------------ Location UI --------------------------- */

    /**
     * The display-only location toggles (+ "Teller i" on capture). Server renders
     * everything visible; counting.js applies the device's remembered choice.
     *
     * @param array<int,string> $locations
     */
    private static function locationToggles(array $locations, bool $countIn): void {
        ?>
        <fieldset class="ks-loc-toggles">
            <legend class="ks-loc-toggles__label"><?php \esc_html_e('Show locations', 'kaupang-stock'); ?></legend>
            <?php foreach ($locations as $locId => $name): ?>
                <label><input type="checkbox" class="ks-loc-toggle" value="<?php echo (int) $locId; ?>" checked /> <?php echo \esc_html($name); ?></label>
            <?php endforeach; ?>
        </fieldset>
        <?php if ($countIn): ?>
            <label class="ks-count-in">
                <?php \esc_html_e('Count into', 'kaupang-stock'); ?>
                <select id="ks-count-in">
                    <?php foreach ($locations as $locId => $name): ?>
                        <option value="<?php echo (int) $locId; ?>"><?php echo \esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif;
    }

    /** @param array<int,string> $locations */
    private static function locationsAttr(array $locations): string {
        $list = [];
        foreach ($locations as $id => $name) {
            $list[] = ['id' => (int) $id, 'name' => $name];
        }
        return 'data-locations="' . \esc_attr((string) \wp_json_encode($list)) . '"';
    }

    /* ------------------------------ Decorate ------------------------------ */

    /**
     * Attach title + SKU to raw line rows in a single query batch (no WC_Product
     * loop). Returns typed, render-ready rows.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array{id:int,product_id:int,location_id:int,title:string,sku:string,expected:float,counted:?float,recount:bool}>
     */
    public static function decorate(array $lines): array {
        $meta = ProductSearch::rows(array_map(static fn ($l): int => (int) $l['product_id'], $lines));

        $out = [];
        foreach ($lines as $l) {
            $pid   = (int) $l['product_id'];
            $title = $meta[$pid]['title'] ?? ('#' . $pid);
            $out[] = [
                'id'          => (int) $l['id'],
                'product_id'  => $pid,
                'location_id' => Counts::lineLocation($l),
                'title'       => $title,
                'sku'         => $meta[$pid]['sku'] ?? '',
                'expected'    => (float) $l['expected'],
                'counted'     => $l['counted'] === null ? null : (float) $l['counted'],
                'recount'     => (int) $l['recount'] === 1,
            ];
        }
        return $out;
    }

    /**
     * Decorated lines grouped by product (first-appearance order), one cell per
     * location.
     *
     * @return array<int,array{product_id:int,title:string,sku:string,cells:array<int,array<string,mixed>>}>
     */
    private static function byProduct(array $lines): array {
        $out = [];
        foreach (self::decorate($lines) as $r) {
            $pid = $r['product_id'];
            $out[$pid] ??= ['product_id' => $pid, 'title' => $r['title'], 'sku' => $r['sku'], 'cells' => []];
            $out[$pid]['cells'][$r['location_id']] = $r;
        }
        return array_values($out);
    }

    private static function diffClass(?float $diff): string {
        return $diff === null ? '' : ($diff > 0 ? 'ks-pos' : ($diff < 0 ? 'ks-neg' : 'ks-zero'));
    }
}
