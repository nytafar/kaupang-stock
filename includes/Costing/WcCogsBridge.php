<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Movement;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

/**
 * Order-COGS stamping — the analytics surface (the iteration's primary
 * consumer). Once a sale's consumptions land in the ledger, each order line
 * gets:
 *
 *  1. `_kaupang_stock_cogs_ore` — integer øre, exact, this plugin's own meta.
 *     THE value downstream order analytics should read for margin math.
 *  2. WooCommerce core's `_cogs_value` / `_cogs_total_value` (float kr) —
 *     served through the `woocommerce_calculated_order_item_cogs_value`
 *     filter so WC's native COGS machinery carries ledger truth instead of
 *     the static product-field snapshot. Requires the WC `cost_of_goods_sold`
 *     feature; the øre meta is written regardless.
 *
 * Trigger: order-referencing movements folded this request mark the order;
 * stamping runs once per order on shutdown at priority 30 — AFTER the
 * Absorber (20), so order_edit residuals recorded at shutdown are included.
 *
 * Semantics: the stamp is Σ cost over the ledger consumptions of the line's
 * NEGATIVE movements (sale + order_edit reductions, incl. provisional
 * true-up restatements). Refund restocks re-enter stock as layers and leave
 * the stamp untouched — analytics nets refunds from the refund objects.
 * A provisional stamped at estimate is restated in the ledger by a later
 * receipt's true-up; the stamp refreshes the next time the order is touched
 * (or via restampOrder()).
 */
final class WcCogsBridge {

    public const META_ORE = '_kaupang_stock_cogs_ore';

    /** @var array<int,bool> order ids touched by folded movements this request */
    private static array $touched = [];

    private static bool $armed = false;

    public static function register(): void {
        if (!Costing::enabled() || !Settings::get('cogs_order_meta_enabled')) {
            return;
        }
        // Serve ledger truth into WC's item-level COGS calculation (fires
        // whenever core recalculates an order's COGS, incl. our own restamp).
        \add_filter('woocommerce_calculated_order_item_cogs_value', [self::class, 'serveItemCogs'], 10, 2);
        // After the costing fold (priority 10 on the same action).
        \add_action('kaupang/stock/movement_recorded', [self::class, 'onMovement'], 20);
    }

    public static function onMovement(Movement $movement): void {
        if ($movement->refType !== 'order' || $movement->refId === null) {
            return;
        }
        self::$touched[$movement->refId] = true;
        if (!self::$armed) {
            self::$armed = true;
            \add_action('shutdown', [self::class, 'stampTouched'], 30);
        }
    }

    public static function stampTouched(): void {
        $orderIds      = array_keys(self::$touched);
        self::$touched = [];
        foreach ($orderIds as $orderId) {
            try {
                self::restampOrder($orderId);
            } catch (\Throwable $e) {
                Logger::error('cogs_stamp_failed', ['order' => $orderId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Write the øre meta on every line with ledger consumptions, then let WC
     * recalculate its native COGS fields (the item filter serves our values).
     * Idempotent; callable from CLI/UI to refresh after true-ups.
     */
    public static function restampOrder(int $orderId): void {
        $order = \wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $stamped = false;
        foreach ($order->get_items('line_item') as $item) {
            $ore = self::ledgerCogsOreForItem($orderId, (int) $item->get_id());
            if ($ore === null) {
                continue; // no ledger truth for this line — leave it alone
            }
            if ((string) $item->get_meta(self::META_ORE, true) !== (string) $ore) {
                $item->update_meta_data(self::META_ORE, (string) $ore);
                $item->save();
            }
            $stamped = true;
        }

        // WC-native fields: recalculate through core so the item filter serves
        // the ledger values into _cogs_value / _cogs_total_value.
        if ($stamped && self::wcCogsFeatureEnabled() && $order->has_cogs()) {
            $order->calculate_cogs_total_value();
            $order->save();
        }
    }

    /**
     * `woocommerce_calculated_order_item_cogs_value` — replace core's
     * product-field snapshot with the ledger's consumed cost when it exists.
     *
     * @param float|null $value core's calculated value
     * @param \WC_Order_Item $item
     * @return float|null
     */
    public static function serveItemCogs($value, $item) {
        if (!$item instanceof \WC_Order_Item_Product) {
            return $value;
        }
        $orderId = (int) $item->get_order_id();
        $itemId  = (int) $item->get_id();
        if ($orderId <= 0 || $itemId <= 0) {
            return $value;
        }
        $ore = self::ledgerCogsOreForItem($orderId, $itemId);
        return $ore !== null ? $ore / 100 : $value;
    }

    /**
     * Net ledger COGS for one order line: Σ cost over the consumptions of the
     * line's negative movements (fifo + provisional + true-up pairs net to
     * actual). NULL when the ledger holds nothing for the line, or only
     * uncosted rows — core's value passes through in both cases.
     */
    public static function ledgerCogsOreForItem(int $orderId, int $itemId): ?int {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS consumption_rows,
                    SUM(c.cost_ore) AS cost_ore
             FROM ' . Schema::costConsumptions() . ' c
             JOIN ' . Schema::movements() . " m ON m.id = c.movement_id
             WHERE m.ref_type = 'order' AND m.ref_id = %d AND m.ref_line = %d AND m.delta < 0",
            $orderId,
            $itemId
        ), ARRAY_A);
        if (!is_array($row) || (int) $row['consumption_rows'] === 0 || $row['cost_ore'] === null) {
            return null;
        }
        return (int) $row['cost_ore'];
    }

    public static function wcCogsFeatureEnabled(): bool {
        return class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')
            && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('cost_of_goods_sold');
    }
}
