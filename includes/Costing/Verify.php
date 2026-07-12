<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Schema;

/**
 * Cost invariants — the Reconciler's costing section and `wp kaupang-stock
 * cost verify`. Report-only, like the quantity reconciler.
 *
 * The quantity identity, generalised for products whose opening cost is not
 * entered yet (their anchor-time qty is deliberately not in the layers):
 *
 *   open_qty − provisional_qty == (on_hand − qty_at_anchor) + opening_entered_qty
 *
 * With the opening layer entered the right side collapses to on_hand. A
 * missing opening layer is therefore reported as `opening_pending`, never as
 * drift.
 */
final class Verify {

    /**
     * @return array{
     *   checked:int,
     *   issues:array<int,array<string,mixed>>,
     *   lagging:array<int,int>,
     *   opening_pending:array<int,float>,
     *   uncosted:array<int,float>,
     *   provisional:array<int,float>
     * }
     */
    public static function run(): array {
        $report = [
            'checked'         => 0,
            'issues'          => [],
            'lagging'         => [],
            'opening_pending' => [],
            'uncosted'        => [],
            'provisional'     => [],
        ];
        if (Costing::anchorId() === null) {
            return $report;
        }

        global $wpdb;
        $anchorId = (int) Costing::anchorId();
        $balances = Balances::rows();
        $cached   = ProductCost::rows();
        $openingPending = Opening::pending();

        foreach ($cached as $productId => $cache) {
            $locationId = (int) $cache['location_id'];
            $report['checked']++;

            // 1) Watermark lag (sweep should normally have drained this).
            $balanceRow = $balances[$productId] ?? null;
            $bLast      = $balanceRow !== null ? (int) $balanceRow['last_movement_id'] : 0;
            $pcLast     = (int) $cache['last_movement_id'];
            if ($bLast > max($pcLast, $anchorId)) {
                $report['lagging'][$productId] = $bLast - $pcLast;
                continue; // identity checks are meaningless while lagging
            }

            // 2) Cache vs live re-derivation (exact).
            $live = self::liveAggregates($productId, $locationId);
            foreach (['open_qty', 'provisional_qty'] as $key) {
                if (abs((float) $cache[$key] - $live[$key]) > 1e-6) {
                    $report['issues'][] = [
                        'product_id' => $productId,
                        'type'       => 'cache_drift',
                        'field'      => $key,
                        'cached'     => (float) $cache[$key],
                        'live'       => $live[$key],
                    ];
                }
            }
            foreach (['value_ore', 'provisional_cost_ore'] as $key) {
                if ((int) $cache[$key] !== $live[$key]) {
                    $report['issues'][] = [
                        'product_id' => $productId,
                        'type'       => 'cache_drift',
                        'field'      => $key,
                        'cached'     => (int) $cache[$key],
                        'live'       => $live[$key],
                    ];
                }
            }

            // 3) Quantity identity against the balances projection.
            $onHand         = $balanceRow !== null ? (float) $balanceRow['on_hand'] : 0.0;
            $qtyAtAnchor    = Opening::qtyAtAnchor($productId, $locationId);
            $openingEntered = self::openingEnteredQty($productId, $locationId);
            $expected       = ($onHand - $qtyAtAnchor) + $openingEntered;
            $actual         = $live['open_qty'] - $live['provisional_qty'];
            if (abs($actual - $expected) > 1e-6) {
                $report['issues'][] = [
                    'product_id' => $productId,
                    'type'       => 'quantity_identity',
                    'expected'   => $expected,
                    'actual'     => $actual,
                    'on_hand'    => $onHand,
                ];
            }

            // 4) Negative layer remainders (engine bug canary).
            $negative = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM (
                    SELECT l.id, l.qty_original - COALESCE(SUM(c.qty), 0) AS remaining
                    FROM ' . Schema::costLayers() . ' l
                    LEFT JOIN ' . Schema::costConsumptions() . ' c ON c.layer_id = l.id
                    WHERE l.product_id = %d AND l.location_id = %d
                    GROUP BY l.id
                    HAVING remaining < -0.0000001
                 ) t',
                $productId,
                $locationId
            ));
            if ($negative > 0) {
                $report['issues'][] = [
                    'product_id' => $productId,
                    'type'       => 'negative_layer_remaining',
                    'layers'     => $negative,
                ];
            }

            // 5) Informational flags.
            if ($live['uncosted_qty'] > 1e-9) {
                $report['uncosted'][$productId] = $live['uncosted_qty'];
            }
            if ($live['provisional_qty'] > 1e-9) {
                $report['provisional'][$productId] = $live['provisional_qty'];
            }
        }

        foreach ($openingPending as $productId => $info) {
            $report['opening_pending'][$productId] = $info['qty'];
        }

        return $report;
    }

    /** @return array{open_qty:float,value_ore:int,provisional_qty:float,provisional_cost_ore:int,uncosted_qty:float} */
    private static function liveAggregates(int $productId, int $locationId): array {
        global $wpdb;
        $layerAgg = $wpdb->get_row($wpdb->prepare(
            'SELECT
                COALESCE(SUM(remaining), 0) AS open_qty,
                COALESCE(SUM(CASE WHEN unit_cost_ore IS NOT NULL THEN ROUND(remaining * unit_cost_ore) ELSE 0 END), 0) AS value_ore,
                COALESCE(SUM(CASE WHEN unit_cost_ore IS NULL THEN remaining ELSE 0 END), 0) AS uncosted_qty
             FROM (
                SELECT l.unit_cost_ore, l.qty_original - COALESCE(SUM(c.qty), 0) AS remaining
                FROM ' . Schema::costLayers() . ' l
                LEFT JOIN ' . Schema::costConsumptions() . ' c ON c.layer_id = l.id
                WHERE l.product_id = %d AND l.location_id = %d
                GROUP BY l.id, l.unit_cost_ore, l.qty_original
             ) t WHERE t.remaining > 0',
            $productId,
            $locationId
        ), ARRAY_A);
        $provAgg = $wpdb->get_row($wpdb->prepare(
            'SELECT COALESCE(SUM(qty), 0) AS prov_qty, COALESCE(SUM(cost_ore), 0) AS prov_cost
             FROM ' . Schema::costConsumptions() . "
             WHERE product_id = %d AND location_id = %d AND kind IN ('provisional', 'prov_reversal')",
            $productId,
            $locationId
        ), ARRAY_A);
        return [
            'open_qty'             => (float) ($layerAgg['open_qty'] ?? 0),
            'value_ore'            => (int) ($layerAgg['value_ore'] ?? 0),
            'provisional_qty'      => (float) ($provAgg['prov_qty'] ?? 0),
            'provisional_cost_ore' => (int) ($provAgg['prov_cost'] ?? 0),
            'uncosted_qty'         => (float) ($layerAgg['uncosted_qty'] ?? 0),
        ];
    }

    private static function openingEnteredQty(int $productId, int $locationId): float {
        global $wpdb;
        $qty = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(qty_original), 0) FROM ' . Schema::costLayers() . '
             WHERE product_id = %d AND location_id = %d AND origin = %s',
            $productId,
            $locationId,
            Layers::ORIGIN_OPENING
        ));
        return $qty !== null ? (float) $qty : 0.0;
    }
}
