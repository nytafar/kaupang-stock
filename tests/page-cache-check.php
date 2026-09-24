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
$unhook = static function () use ($hooks): void {
    foreach ($hooks as $hook => $method) {
        remove_action($hook, [PageCache::class, $method]);
    }
};

// Registration per mode, via an option filter (nothing is written).
foreach (['off' => [false, false], 'status' => [true, false], 'low_stock' => [true, true]] as $mode => [$status, $stock]) {
    $filter = static fn($v) => array_replace(is_array($v) ? $v : [], ['page_cache_purge' => $mode]);
    add_filter('option_' . Settings::OPTION_KEY, $filter);
    Settings::flushCache();
    $unhook();
    PageCache::register();
    $assert($status === (false !== has_action('woocommerce_product_set_stock_status', [PageCache::class, 'onStatus'])), "$mode: status hook");
    $assert($stock === (false !== has_action('woocommerce_product_set_stock', [PageCache::class, 'onStock'])), "$mode: quantity hook");
    remove_filter('option_' . Settings::OPTION_KEY, $filter);
}
Settings::flushCache();
$unhook();
PageCache::register(); // back to the site's own mode

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
