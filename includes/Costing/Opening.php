<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Schema;

/**
 * Opening cost entry — the operator-entered layers that represent the ledger
 * state AT THE ANCHOR (movements at or below it never fold; this is their
 * valuation). Quantities are computed from the movements at the anchor, not
 * from current on_hand, so entering costs days later is safe: interim sales
 * folded as provisionals and settle against the opening layer the moment it
 * is saved (Engine::settleProvisionals).
 *
 * @internal Implementation of the Costing module — reach it through Costing.
 */
final class Opening {

    /**
     * Products still needing an opening cost: anchor-time qty ≠ 0 and no
     * opening layer yet. Keyed by product id.
     *
     * @return array<int,array{product_id:int,qty:float}>
     */
    public static function pending(): array {
        $anchorId = Costing::anchorId();
        if ($anchorId === null) {
            return [];
        }
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT m.product_id, m.location_id, SUM(m.delta) AS qty
             FROM ' . Schema::movements() . ' m
             WHERE m.id <= %d
             GROUP BY m.product_id, m.location_id
             HAVING ABS(qty) > 0.0000001',
            $anchorId
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            $productId  = (int) $row['product_id'];
            $locationId = (int) $row['location_id'];
            if (Layers::hasOpening($productId, $locationId)) {
                continue;
            }
            $out[$productId] = [
                'product_id' => $productId,
                'qty'        => (float) $row['qty'],
            ];
        }
        return $out;
    }

    /** Anchor-time quantity for one product (0.0 = no opening layer needed). */
    public static function qtyAtAnchor(int $productId, int $locationId = 0): float {
        $anchorId = Costing::anchorId();
        if ($anchorId === null) {
            return 0.0;
        }
        global $wpdb;
        $qty = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(delta), 0) FROM ' . Schema::movements() . '
             WHERE product_id = %d AND location_id = %d AND id <= %d',
            $productId,
            Balances::resolveLocation($locationId),
            $anchorId
        ));
        return $qty !== null ? (float) $qty : 0.0;
    }

    /**
     * Save the opening unit cost for a product: one immutable opening layer at
     * the anchor time. Refuses a second entry (append-only — a wrong opening
     * cost is corrected via the correction pair, or by rebuild after fixing).
     */
    public static function save(int $productId, int $unitCostOre, int $locationId = 0, ?int $actorId = null): int {
        $locationId = Balances::resolveLocation($locationId);
        $anchorTime = Costing::anchorTime();
        if ($anchorTime === null) {
            throw new CostingException('Costing has not been enabled yet (no anchor)');
        }
        if ($unitCostOre < 0) {
            throw new CostingException('Opening cost cannot be negative');
        }
        if (Layers::hasOpening($productId, $locationId)) {
            throw new CostingException("Product $productId already has an opening layer");
        }
        $qty = self::qtyAtAnchor($productId, $locationId);
        if (abs($qty) < 1e-9) {
            throw new CostingException("Product $productId had no stock at the costing anchor");
        }
        if ($qty < 0) {
            // Negative at the anchor: nothing to value — the shortfall folds as
            // provisionals against future receipts instead.
            throw new CostingException("Product $productId was negative at the anchor; receive stock instead of entering an opening cost");
        }

        $layerId = Layers::insert([
            'product_id'    => $productId,
            'location_id'   => $locationId,
            'origin'        => Layers::ORIGIN_OPENING,
            'ref_type'      => 'opening',
            'unit_cost_ore' => $unitCostOre,
            'qty_original'  => $qty,
            'is_estimate'   => 0,
            'actor_id'      => $actorId ?? \get_current_user_id(),
            'occurred_at'   => $anchorTime,
        ]);

        // Sales folded before this entry existed became provisionals — settle
        // them against the opening layer now, then refresh the cache.
        Engine::settleProvisionals($productId, $locationId);
        Engine::refreshAggregates($productId, $locationId);

        return $layerId;
    }
}
