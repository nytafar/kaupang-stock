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
 * Trigger: order-referencing movements folded this request mark the order,
 * and so does an order becoming paid (payment_complete, or a status change
 * into a paid status); stamping runs once per order on shutdown at priority
 * 30 — AFTER the Absorber (20), so order_edit residuals recorded at shutdown
 * are included, and after the caller's own saves, so a stale order object
 * saved later in the request cannot wipe the COGS we write.
 *
 * Fill: core calculates order COGS only in calculate_totals(), checkout and
 * refunds. Channels that build orders with bare wc_add_order_item() (the
 * BjornTech Zettle integration, imports) skip all three and end up with no
 * COGS. A paid order that carries no COGS yet is calculated once through core
 * (so the item filter still serves ledger truth); an order that already has
 * COGS is never recalculated by the fill — that would replace its historical
 * cost with today's product cost.
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
        // Channel-agnostic fill for orders no core path calculated COGS for.
        \add_action('woocommerce_payment_complete', [self::class, 'onPaid'], 10, 1);
        \add_action('woocommerce_order_status_changed', [self::class, 'onPaid'], 10, 3);
    }

    public static function onMovement(Movement $movement): void {
        if ($movement->refType !== 'order' || $movement->refId === null) {
            return;
        }
        self::touch($movement->refId);
    }

    /**
     * `woocommerce_payment_complete` (order id) and
     * `woocommerce_order_status_changed` (order id, from, to).
     *
     * @param int|string $orderId
     * @param string     $from
     * @param string|null $to null from payment_complete
     */
    public static function onPaid($orderId, $from = '', $to = null): void {
        if ($to !== null && !in_array($to, \wc_get_is_paid_statuses(), true)) {
            return;
        }
        if (!self::wcCogsFeatureEnabled()) {
            return;
        }
        self::touch((int) $orderId);
    }

    private static function touch(int $orderId): void {
        if ($orderId <= 0) {
            return;
        }
        self::$touched[$orderId] = true;
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
     * An order with no ledger line and no COGS yet gets its first calculation
     * (the fill); one that already has COGS and no ledger line is left alone.
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

        if (!self::wcCogsFeatureEnabled() || !$order->has_cogs()) {
            return;
        }
        // WC-native fields: recalculate through core so the item filter serves
        // the ledger values into _cogs_value / _cogs_total_value.
        if ($stamped) {
            $order->calculate_cogs_total_value();
            $order->save();
            return;
        }
        // Fill: first calculation for an order no core path calculated.
        if (!self::hasCogs($order) && $order->calculate_cogs_total_value() != 0.0) {
            $order->save(); // a zero result has nothing to persist (WC stores 0 as no meta)
        }
    }

    /**
     * Whether core already holds a COGS value for the order or any line.
     *
     * ponytail: WC deletes the meta for a 0.0 value, so "calculated as zero"
     * and "never calculated" read the same. Such an order is filled when it is
     * touched as paid — normally the moment it was sold, so today's cost is
     * that day's cost. A per-order "calculated" marker is the upgrade path if
     * zero-cost orders that turn paid much later ever matter.
     */
    private static function hasCogs(\WC_Order $order): bool {
        if ($order->get_cogs_total_value() != 0.0) {
            return true;
        }
        foreach ($order->get_items('line_item') as $item) {
            if ($item->get_cogs_value() != 0.0) {
                return true;
            }
        }
        return false;
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
     * actual). NULL when the ledger holds nothing for the line, or no costed
     * quantity — core's value passes through in both cases.
     *
     * Uncosted quantity (rows drawn from a NULL-cost layer or valued at a NULL
     * estimate) is priced at the line's own blended costed unit cost. SUM()
     * skips NULLs, so summing alone would cost those units at zero and
     * understate the line. Blending beats falling back to the product's WC
     * COGS value for the whole line: it keeps the ledger's actual cost for the
     * units it knows, it uses the same product's real purchase cost for the
     * rest, and it cannot drop to zero when the product field is unset. The
     * returns policy blends the same way (Engine::returnCost via
     * Consumptions::netForOrderProduct). Quantities are summed with their sign,
     * so a NULL provisional later settled by a costed backfill nets to zero
     * uncosted quantity and is not double-priced.
     */
    public static function ledgerCogsOreForItem(int $orderId, int $itemId): ?int {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COALESCE(SUM(CASE WHEN c.cost_ore IS NOT NULL THEN c.qty ELSE 0 END), 0) AS costed_qty,
                    COALESCE(SUM(CASE WHEN c.cost_ore IS NULL THEN c.qty ELSE 0 END), 0) AS uncosted_qty,
                    SUM(c.cost_ore) AS cost_ore
             FROM ' . Schema::costConsumptions() . ' c
             JOIN ' . Schema::movements() . " m ON m.id = c.movement_id
             WHERE m.ref_type = 'order' AND m.ref_id = %d AND m.ref_line = %d AND m.delta < 0",
            $orderId,
            $itemId
        ), ARRAY_A);
        $costedQty = is_array($row) ? (float) $row['costed_qty'] : 0.0;
        if ($costedQty <= 1e-9 || $row['cost_ore'] === null) {
            return null;
        }
        $ore         = (int) $row['cost_ore'];
        $uncostedQty = (float) $row['uncosted_qty'];
        if ($uncostedQty > 1e-9) {
            $ore += (int) round($uncostedQty * $ore / $costedQty);
        }
        return $ore;
    }

    public static function wcCogsFeatureEnabled(): bool {
        return class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')
            && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('cost_of_goods_sold');
    }
}
