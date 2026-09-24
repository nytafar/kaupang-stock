<?php
declare(strict_types=1);

namespace Kaupang\Stock\Support;

/**
 * Purge a product's cached pages when its visible stock changes.
 *
 * Nginx Helper only purges on post edits; WooCommerce writes stock through
 * CRUD (orders, refunds, Lager adjustments), which fires no post hook, so a
 * cached product page kept saying "på lager" after it sold out.
 *
 * What the page shows decides what must purge:
 * - stock status (in stock / out of stock / backorder) — always;
 * - the quantity, only at or below the product's low-stock threshold (where
 *   "bare N igjen" shows), and never when woocommerce_stock_format is
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

    public static function register(): void {
        \add_action('woocommerce_product_set_stock_status', [self::class, 'onStatus'], 10, 3);
        \add_action('woocommerce_variation_set_stock_status', [self::class, 'onStatus'], 10, 3);

        if ('no_amount' !== \get_option('woocommerce_stock_format')) {
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

    // ponytail: a restock from below the threshold to above it doesn't purge, so
    // "bare N igjen" can linger until TTL; purge on old <= threshold if that matters.
    public static function onStock(\WC_Product $product): void {
        $stock = $product->get_stock_quantity();
        if (null !== $stock && $stock <= \wc_get_low_stock_amount($product)) {
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
