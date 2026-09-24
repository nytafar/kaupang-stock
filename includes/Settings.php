<?php
declare(strict_types=1);

namespace Kaupang\Stock;

/**
 * Single autoloaded option, static request-cache — the suite's settings contract.
 *
 * Every flag defaults false/safe: a fresh activation records nothing and shows
 * only the settings screen until stock_enabled is switched on. `mode` starts in
 * 'shadow' (observe + record only, write-through disabled) — run that first and
 * prove reconciliation is clean before flipping to 'active'.
 */
final class Settings {

    public const OPTION_KEY = 'kaupang_stock_settings';

    public const MODE_SHADOW = 'shadow';
    public const MODE_ACTIVE = 'active';

    private static ?array $cache = null;

    public static function defaults(): array {
        return [
            // Master switch: observer, reconciler, admin screens beyond Innstillinger.
            'stock_enabled'          => false,
            // shadow = observe/record only; active = owned operations may write through.
            'mode'                   => self::MODE_SHADOW,
            // Innkjøp (suppliers, purchase orders, receiving).
            'po_enabled'             => false,
            // Varetelling (counting).
            'counting_enabled'       => false,
            // Review lines whose |variance| exceeds this % are flagged for recount.
            'variance_threshold_pct' => 20,
            // Surface negative on-hand as a warning chip on Lagerstatus.
            'negative_warning'       => true,
            // Owned operations may take an individual location below zero.
            'allow_negative_locations' => true,
            // WC_Order::get_created_via() value => active location id.
            'order_location_map'     => [],
            // FIFO cost layers: fold movements into cost basis + COGS (projection
            // only — works in shadow mode; receipts still require active mode).
            'costing_enabled'        => false,
            // Stamp per-order-item COGS meta (analytics surface; see WcCogsBridge).
            'cogs_order_meta_enabled' => false,
            // Purge a product's cached page on stock changes (Support\PageCache):
            // off | status (stock status changes) | low_stock (status + quantity
            // changes at or below the low-stock threshold). Independent of the
            // ledger — runs even with stock_enabled off.
            'page_cache_purge'       => 'off',
        ];
    }

    /** @return mixed */
    public static function get(string $key, $default = null) {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function all(): array {
        if (self::$cache === null) {
            $stored = \get_option(self::OPTION_KEY, []);
            self::$cache = array_replace(self::defaults(), is_array($stored) ? $stored : []);
        }
        return self::$cache;
    }

    public static function update(array $patch): void {
        $merged = array_replace(self::all(), $patch);
        self::$cache = $merged;
        \update_option(self::OPTION_KEY, $merged, true);
    }

    public static function flushCache(): void {
        self::$cache = null;
    }

    public static function enabled(): bool {
        return (bool) self::get('stock_enabled');
    }

    /** True when owned operations (adjust/receipt/count/reversal) may write through to Woo. */
    public static function activeMode(): bool {
        return self::enabled() && self::get('mode') === self::MODE_ACTIVE;
    }

    /** Admin/REST capability, overridable via the kaupang/stock/can_manage seam. */
    /**
     * Actor for a movement recorded now: the current user when they manage
     * stock, else 0 (system). A customer checking out is not an actor — their
     * order is the reference, and listing customers as actors would enumerate
     * the whole customer base in the Movements filter.
     */
    public static function actorId(): int {
        $uid = \get_current_user_id();
        return $uid > 0 && \user_can($uid, self::capability()) ? $uid : 0;
    }

    public static function capability(): string {
        $cap = \apply_filters('kaupang/stock/can_manage', 'manage_woocommerce');
        return is_string($cap) && $cap !== '' ? $cap : 'manage_woocommerce';
    }
}
