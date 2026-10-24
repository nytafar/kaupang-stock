<?php
/**
 * PageCache self-check: the page_cache_purge setting decides which stock
 * changes purge a product's cached page.
 *
 *   wp eval-file wp-content/plugins/kaupang-stock/tests/page-cache-check.php [product_id]
 *
 * Writes no stock and no settings: modes are swapped through an option filter,
 * quantity rules run on in-memory products, so it leaves no movements behind.
 * The end-to-end half (HIT -> purge -> MISS) runs when the site's own setting
 * is not 'off' and the page cache sends X-Grid-Cache (GridPane).
 * Exits non-zero on failure.
 */

use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\PageCache;

defined('ABSPATH') || exit(1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        WP_CLI::error('page-cache: ' . $message);
    }
};

$id = (int) ($args[0] ?? 0) ?: (int) (wc_get_products(['status' => 'publish', 'type' => 'simple', 'limit' => 1, 'return' => 'ids'])[0] ?? 0);
$product = wc_get_product($id);
$assert((bool) $product, 'a published product to test with');

$siteMode = Settings::get('page_cache_purge');
$hooks = [
    'woocommerce_product_set_stock_status' => 'onStatus', 'woocommerce_variation_set_stock_status' => 'onStatus',
    'woocommerce_product_before_set_stock' => 'rememberStock', 'woocommerce_variation_before_set_stock' => 'rememberStock',
    'woocommerce_product_set_stock' => 'onStock', 'woocommerce_variation_set_stock' => 'onStock',
];
// Hooks are registered everywhere (Plugin::boot()); the mode gates the handlers.
foreach ($hooks as $hook => $method) {
    $assert(false !== has_action($hook, [PageCache::class, $method]), "$hook registered");
}
$modeFilter = static fn(string $mode) => static fn($v) => array_replace(is_array($v) ? $v : [], ['page_cache_purge' => $mode]);

// Per mode, via an option filter (nothing is written).
foreach (['off' => '', 'status' => 'status', 'low_stock' => 'low_stock'] as $mode => $effective) {
    $filter = $modeFilter($mode);
    add_filter('option_' . Settings::OPTION_KEY, $filter);
    Settings::flushCache();
    $noAmount = static fn() => 'no_amount';
    add_filter('pre_option_woocommerce_stock_format', $noAmount);
    $assert(PageCache::mode() === ('low_stock' === $effective ? 'status' : $effective), "$mode: no_amount drops quantity purges");
    remove_filter('pre_option_woocommerce_stock_format', $noAmount);
    $assert(PageCache::mode() === $effective || 'no_amount' === get_option('woocommerce_stock_format'), "$mode: effective mode");
    PageCache::flush();
    PageCache::onStatus($id, $product->get_stock_status(), $product);
    $assert(('' !== $effective) === (PageCache::flush() === [$id]), "$mode: status change purges only when on");
    remove_filter('option_' . Settings::OPTION_KEY, $filter);
}

// The quantity rules run in low_stock mode whatever the site's own setting.
$lowFilter = $modeFilter('low_stock');
add_filter('option_' . Settings::OPTION_KEY, $lowFilter);
Settings::flushCache();

// Quantity rule on in-memory products: purge when the old or new quantity is
// at or below the low-stock threshold.
$low = wc_get_low_stock_amount($product);
$change = static function (int $old, int $new) use ($id): array {
    $p = new WC_Product_Simple();
    $p->set_id($id);
    $p->set_props(['manage_stock' => true, 'stock_quantity' => $old]);
    $p->apply_changes();
    $p->set_stock_quantity($new);
    PageCache::rememberStock($p);
    PageCache::onStock($p);
    return PageCache::flush();
};
PageCache::flush();
$assert($change($low + 5, $low + 1) === [], "sale above threshold ($low) does not purge");
$assert($change($low + 1, $low) === [$id], "sale reaching threshold ($low) purges");
$assert($change($low, $low + 10) === [$id], "restock from threshold ($low) purges");
remove_filter('option_' . Settings::OPTION_KEY, $lowFilter);
Settings::flushCache();

if ('off' === $siteMode) {
    WP_CLI::success('page-cache: rules ok (site setting is off; end-to-end skipped).');
    return;
}

$cache = static function () use ($product): string {
    $r = wp_remote_get($product->get_permalink(), ['timeout' => 20, 'sslverify' => false]);
    return is_wp_error($r) ? 'error' : strtoupper((string) wp_remote_retrieve_header($r, 'x-grid-cache'));
};

// Status change -> one purge per product, even when fired twice.
do_action('woocommerce_product_set_stock_status', $id, $product->get_stock_status(), $product);
do_action('woocommerce_product_set_stock_status', $id, $product->get_stock_status(), $product);

$cache(); // warm
$warm = $cache();
$purged = PageCache::flush();
$assert($purged === [$id], 'queue collapses to one purge for product ' . $id . ', got ' . wp_json_encode($purged));

if ('HIT' !== $warm) {
    WP_CLI::warning("No page-cache HIT to purge (X-Grid-Cache: '$warm'); end-to-end skipped.");
    WP_CLI::success('page-cache: rules ok.');
    return;
}

$after = $cache();
$assert('MISS' === $after, "product page purged (want MISS after flush, got '$after')");
WP_CLI::success("page-cache: rules ok; product $id HIT -> purge -> MISS ($siteMode).");
