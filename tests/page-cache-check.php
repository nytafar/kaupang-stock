<?php
/**
 * PageCache self-check: a stock-status change purges the product's cached page.
 *
 *   wp eval-file wp-content/plugins/kaupang-stock/tests/page-cache-check.php [product_id]
 *
 * Writes no stock (fires woocommerce_product_set_stock_status directly, which
 * the ledger observer does not listen to), so it leaves no movements behind.
 * The end-to-end half needs a page cache that sends X-Grid-Cache (GridPane);
 * without it only the wiring is checked. Exits non-zero on failure.
 */

use Kaupang\Stock\Support\PageCache;

defined('ABSPATH') || exit(1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        WP_CLI::error('page-cache: ' . $message);
    }
};

$assert(false !== has_action('woocommerce_product_set_stock_status', [PageCache::class, 'onStatus']), 'status hook registered (is stock_enabled on?)');
$assert(false !== has_action('woocommerce_variation_set_stock_status', [PageCache::class, 'onStatus']), 'variation status hook registered');

$id = (int) ($args[0] ?? 0) ?: (int) (wc_get_products(['status' => 'publish', 'type' => 'simple', 'limit' => 1, 'return' => 'ids'])[0] ?? 0);
$product = wc_get_product($id);
$assert((bool) $product, 'a published product to test with');

$cache = static function () use ($product): string {
    $r = wp_remote_get($product->get_permalink(), ['timeout' => 20, 'sslverify' => false]);
    return is_wp_error($r) ? 'error' : strtoupper((string) wp_remote_retrieve_header($r, 'x-grid-cache'));
};

// Quantity rule: purge only at or below the low-stock threshold. Unsaved
// in-memory copies, called directly — nothing reaches the ledger observer.
PageCache::flush();
$low = wc_get_low_stock_amount($product);
$probe = clone $product;
$probe->set_manage_stock(true);
$probe->set_stock_quantity($low + 1);
PageCache::onStock($probe);
$assert(PageCache::flush() === [], "stock above threshold ($low) does not purge");
$probe->set_stock_quantity($low);
PageCache::onStock($probe);
$assert(PageCache::flush() === [$id], "stock at threshold ($low) purges");

// Wiring: the status hook queues the product and flush purges it once.
do_action('woocommerce_product_set_stock_status', $id, $product->get_stock_status(), $product);
do_action('woocommerce_product_set_stock_status', $id, $product->get_stock_status(), $product);

$cache(); // warm
$warm = $cache();
$purged = PageCache::flush();
$assert($purged === [$id], 'queue collapses to one purge for product ' . $id . ', got ' . wp_json_encode($purged));

if ('HIT' !== $warm) {
    WP_CLI::warning("No page-cache HIT to purge (X-Grid-Cache: '$warm'); wiring checked only.");
    WP_CLI::success('page-cache: wiring ok.');
    return;
}

$after = $cache();
$assert('MISS' === $after, "product page purged (want MISS after flush, got '$after')");
WP_CLI::success("page-cache: product $id HIT -> purge -> MISS.");
