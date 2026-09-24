<?php
declare(strict_types=1);

namespace Kaupang\Stock\Support;

use Kaupang\Stock\Settings;

/**
 * Purge a product's cached pages when its visible stock changes.
 *
 * Nginx Helper only purges on post edits; WooCommerce writes stock through
 * CRUD (orders, refunds, Lager adjustments), which fires no post hook, so a
 * cached product page kept saying "på lager" after it sold out.
 *
 * Setting page_cache_purge decides what purges:
 * - 'status': stock status changes (in stock / out of stock / backorder);
 * - 'low_stock': those, plus quantity changes where either the old or the new
 *   quantity is at or below the product's low-stock threshold ("bare N igjen"
 *   shows, or showed — a restock clears it). Never with stock format
 *   'no_amount'. Above the threshold a sale changes nothing on the page.
 *
 * IDs are collected and purged once at shutdown (an order touching several
 * lines, set_stock + set_stock_status on one product, all collapse to one
 * purge per parent product). purge_post() applies the site's own Nginx Helper
 * "purge on edit" policy (product URL, plus archives if configured).
 */
final class PageCache {

    /** @var array<int,true> */
    private static array $queue = [];

    /** @var array<int,int|float|null> Stock before the pending change, per product ID. */
    private static array $before = [];

    public static function register(): void {
        $mode = Settings::get('page_cache_purge');
        if ('status' !== $mode && 'low_stock' !== $mode) {
            return;
        }

        \add_action('woocommerce_product_set_stock_status', [self::class, 'onStatus'], 10, 3);
        \add_action('woocommerce_variation_set_stock_status', [self::class, 'onStatus'], 10, 3);

        if ('low_stock' === $mode && 'no_amount' !== \get_option('woocommerce_stock_format')) {
            // Both stock write paths (CRUD save, wc_update_product_stock) fire
            // before_set_stock while get_data() still holds the old quantity.
            \add_action('woocommerce_product_before_set_stock', [self::class, 'rememberStock']);
            \add_action('woocommerce_variation_before_set_stock', [self::class, 'rememberStock']);
            \add_action('woocommerce_product_set_stock', [self::class, 'onStock']);
            \add_action('woocommerce_variation_set_stock', [self::class, 'onStock']);
        }
    }

    /**
     * @param int|string       $id
     * @param string           $status
     * @param \WC_Product|null $product
     */
    public static function onStatus($id, $status, $product = null): void {
        $product = $product instanceof \WC_Product ? $product : \wc_get_product((int) $id);
        if ($product) {
            self::queue($product);
        }
    }

    public static function rememberStock(\WC_Product $product): void {
        self::$before[$product->get_id()] = $product->get_data()['stock_quantity'] ?? null;
    }

    public static function onStock(\WC_Product $product): void {
        $low = \wc_get_low_stock_amount($product);
        $new = $product->get_stock_quantity();
        $old = self::$before[$product->get_id()] ?? null;
        unset(self::$before[$product->get_id()]);

        if ((null !== $new && $new <= $low) || (null !== $old && $old <= $low)) {
            self::queue($product);
        }
    }

    public static function queue(\WC_Product $product): void {
        if (!self::$queue) {
            \add_action('shutdown', [self::class, 'flush']);
        }
        self::$queue[$product->get_parent_id() ?: $product->get_id()] = true;
    }

    /**
     * @return int[] Product IDs purged (for the self-check).
     */
    public static function flush(): array {
        global $nginx_purger;

        $ids = array_keys(self::$queue);
        self::$queue = [];

        if (is_object($nginx_purger) && method_exists($nginx_purger, 'purge_post')) {
            foreach ($ids as $id) {
                $nginx_purger->purge_post($id);
            }
        }

        return $ids;
    }
}
