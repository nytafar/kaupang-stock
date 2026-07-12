<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Schema;

/**
 * Rebuild + append-only cost correction.
 *
 * Rebuild proves the projection claim: every cost fact re-derives from durable
 * inputs — the movements stream, opening layers, cost_inputs and PO lines.
 * Delete everything derived, reset the watermarks to the anchor, sweep.
 * Opening layers survive (they ARE operator input, not derivation).
 */
final class Rebuild {

    /** @return array{products:int,movements:int} sweep result */
    public static function run(): array {
        if (Costing::anchorId() === null) {
            throw new CostingException('Costing has not been enabled yet (no anchor)');
        }
        global $wpdb;

        ProductCost::begin();
        try {
            $wpdb->query('DELETE FROM ' . Schema::costConsumptions());
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . Schema::costLayers() . ' WHERE origin <> %s',
                Layers::ORIGIN_OPENING
            ));
            $wpdb->query('DELETE FROM ' . Schema::productCost());
            ProductCost::commit();
        } catch (\Throwable $e) {
            ProductCost::rollback();
            throw new CostingException('Rebuild reset failed: ' . $e->getMessage(), 0, $e);
        }

        Logger::info('cost_rebuild_reset', []);

        // Re-fold everything past the anchor…
        $swept = Sweeper::sweepAll();

        // …then prime cache rows for products whose only cost state is an
        // opening layer (no post-anchor movements → the sweep never saw them).
        $orphans = (array) $wpdb->get_results(
            'SELECT DISTINCT l.product_id, l.location_id
             FROM ' . Schema::costLayers() . ' l
             LEFT JOIN ' . Schema::productCost() . ' pc
               ON pc.product_id = l.product_id AND pc.location_id = l.location_id
             WHERE pc.product_id IS NULL',
            ARRAY_A
        );
        foreach ($orphans as $row) {
            Engine::refreshAggregates((int) $row['product_id'], (int) $row['location_id']);
        }

        return $swept;
    }

    /**
     * Append-only cost correction for a layer whose entered cost was wrong:
     * a `correction` consumption drains the layer's REMAINING qty at the old
     * cost, and a new correction layer re-enters that qty at the corrected
     * cost — dated NOW, so historical as-of valuations never restate. COGS on
     * already-sold units keeps its original snapshot (documented; the
     * corrected goods simply rejoin the FIFO queue at today's date).
     *
     * @return int the new layer id
     */
    public static function correctLayer(int $layerId, int $newUnitCostOre, string $note, ?int $actorId = null): int {
        if ($newUnitCostOre < 0) {
            throw new CostingException('Corrected cost cannot be negative');
        }
        if (trim($note) === '') {
            throw new CostingException('A correction requires a note');
        }
        $layer = Layers::find($layerId);
        if ($layer === null) {
            throw new CostingException("Layer $layerId not found");
        }
        $productId  = (int) $layer['product_id'];
        $locationId = Balances::resolveLocation((int) $layer['location_id']);
        $oldOre     = $layer['unit_cost_ore'] !== null ? (int) $layer['unit_cost_ore'] : null;
        if ($oldOre === $newUnitCostOre) {
            throw new CostingException('New cost equals the current cost — nothing to correct');
        }

        ProductCost::begin();
        try {
            ProductCost::lockRow($productId, $locationId);

            $remaining = Layers::remaining($layerId);
            if ($remaining <= 1e-9) {
                throw new CostingException("Layer $layerId is fully consumed — correct the period via reporting, not the layer");
            }

            $now = gmdate('Y-m-d H:i:s');
            Consumptions::insert([
                'layer_id'      => $layerId,
                'movement_id'   => null,
                'product_id'    => $productId,
                'location_id'   => $locationId,
                'kind'          => Consumptions::KIND_CORRECTION,
                'qty'           => $remaining,
                'unit_cost_ore' => $oldOre,
                'cost_ore'      => $oldOre !== null ? (int) round($remaining * $oldOre) : null,
                'occurred_at'   => $now,
            ]);
            $newLayerId = Layers::insert([
                'product_id'    => $productId,
                'location_id'   => $locationId,
                'origin'        => Layers::ORIGIN_CORRECTION,
                'ref_type'      => 'cost_layer',
                'ref_id'        => $layerId,
                'unit_cost_ore' => $newUnitCostOre,
                'qty_original'  => $remaining,
                'is_estimate'   => 0,
                'actor_id'      => $actorId ?? \get_current_user_id(),
                'note'          => mb_substr($note, 0, 255),
                'occurred_at'   => $now,
            ]);

            ProductCost::recomputeAndUpdateLocked($productId, $locationId, null);
            ProductCost::commit();

            Logger::info('cost_layer_corrected', [
                'layer'     => $layerId,
                'new_layer' => $newLayerId,
                'old_ore'   => $oldOre,
                'new_ore'   => $newUnitCostOre,
                'qty'       => $remaining,
            ]);
            return $newLayerId;
        } catch (\Throwable $e) {
            ProductCost::rollback();
            throw $e instanceof CostingException ? $e : new CostingException($e->getMessage(), 0, $e);
        }
    }
}
