<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Movement;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Schema;

/**
 * The fold: deterministic replay of a product's movement stream (movement-id
 * order — the ledger's commit order) into cost layers and consumptions.
 *
 * Transaction shape per batch (strictly AFTER the ledger's own commit):
 *   lock product_cost row FOR UPDATE → read watermark → select movements
 *   beyond it → apply each (pure INSERTs) → recompute aggregates + advance
 *   watermark → COMMIT.
 * The row lock serialises every concurrent fold for the product (live hook vs
 * live hook vs sweep); the watermark makes replays no-ops; a crash before
 * commit rolls back completely and the sweep re-folds identically.
 *
 * Policy summary (docs/kaupang-stock-costing-research.md §4 + plan):
 *   inbound  receipt        cost_inputs[key] → PO line → last-known* → NULL*
 *            adjust         cost_inputs[key] → last-known* → NULL*
 *            restore/refund blended cost of the order's own consumptions,
 *                           capped at (net consumed − already restored)*
 *            reversal(+)    blended cost of the reversed movement's rows
 *            initial        product WC COGS value → last-known* → NULL*
 *            anything else  last-known* → NULL*        (* = is_estimate)
 *   outbound reversal(−)    drain the specific layer it created first
 *            all            FIFO (occurred_at, id); shortfall → provisional
 *                           at last-known cost, settled by a true-up pair
 *                           when the next layer lands.
 *
 * @internal Implementation of the Costing module — reach it through Costing.
 */
final class Engine {

    private const BATCH_LIMIT = 500;

    /**
     * Fold all pending movements for one product. Returns movements processed.
     * Safe to call concurrently and redundantly (lock + watermark).
     */
    public static function processProduct(int $productId, int $locationId): int {
        if (Costing::anchorId() === null) {
            return 0;
        }
        $locationId = Balances::resolveLocation($locationId);
        $processed  = 0;

        do {
            ProductCost::begin();
            try {
                $row  = ProductCost::lockRow($productId, $locationId);
                $from = max((int) $row['last_movement_id'], (int) Costing::anchorId());

                global $wpdb;
                $movementRows = (array) $wpdb->get_results($wpdb->prepare(
                    'SELECT * FROM ' . Schema::movements() . '
                     WHERE product_id = %d AND location_id = %d AND id > %d
                     ORDER BY id ASC
                     LIMIT %d',
                    $productId,
                    $locationId,
                    $from,
                    self::BATCH_LIMIT
                ), ARRAY_A);

                if ($movementRows === []) {
                    ProductCost::commit();
                    return $processed;
                }

                $state        = FoldState::load($productId, $locationId);
                $lastId       = $from;
                $batchCount   = 0;
                $wasDeferred  = false;
                foreach ($movementRows as $movementRow) {
                    $movement = Movement::fromRow($movementRow);
                    if (!self::applyMovement($movement, $state)) {
                        // transfer_in may sort before transfer_out. Stop before
                        // it and leave the location watermark in place; the
                        // sweeper retries after the source location has folded.
                        $wasDeferred = true;
                        break;
                    }
                    $lastId = $movement->id;
                    $batchCount++;
                }

                if ($batchCount > 0) {
                    ProductCost::recomputeAndUpdateLocked($productId, $locationId, $lastId);
                }
                ProductCost::commit();
                $processed += $batchCount;
                if ($wasDeferred) {
                    return $processed;
                }
            } catch (\Throwable $e) {
                ProductCost::rollback();
                if ($e instanceof CostingException) {
                    throw $e;
                }
                throw new CostingException($e->getMessage(), 0, $e);
            }
        } while (count($movementRows) === self::BATCH_LIMIT);

        return $processed;
    }

    /**
     * Re-derive the cached aggregates without folding (used after out-of-band
     * layer writes: opening entry, cost correction, rebuild priming).
     */
    public static function refreshAggregates(int $productId, int $locationId): void {
        $locationId = Balances::resolveLocation($locationId);
        ProductCost::begin();
        try {
            ProductCost::lockRow($productId, $locationId);
            ProductCost::recomputeAndUpdateLocked($productId, $locationId, null);
            ProductCost::commit();
        } catch (\Throwable $e) {
            ProductCost::rollback();
            throw $e instanceof CostingException ? $e : new CostingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Settle outstanding provisionals against already-existing open layers —
     * used when an OPENING layer is entered after post-anchor sales already
     * folded as provisionals (the layer-creation true-up can't fire for a
     * layer inserted outside the fold).
     */
    public static function settleProvisionals(int $productId, int $locationId): int {
        $locationId = Balances::resolveLocation($locationId);
        $settled    = 0;
        ProductCost::begin();
        try {
            ProductCost::lockRow($productId, $locationId);
            $state = FoldState::load($productId, $locationId);

            while (($prov = $state->oldestOutstandingProvisional()) !== null) {
                $layer = $state->oldestOpenLayer();
                if ($layer === null) {
                    break;
                }
                $qty = min($prov['outstanding'], $layer['remaining']);
                self::writeTrueUpPair($productId, $locationId, $prov, $layer, $qty, gmdate('Y-m-d H:i:s'));
                $state->settleProvisional($prov['movement_id'], $qty);
                $state->consumeLayer($layer['id'], $qty);
                $settled++;
            }

            ProductCost::recomputeAndUpdateLocked($productId, $locationId, null);
            ProductCost::commit();
            return $settled;
        } catch (\Throwable $e) {
            ProductCost::rollback();
            throw $e instanceof CostingException ? $e : new CostingException($e->getMessage(), 0, $e);
        }
    }

    /* ------------------------------ The fold ------------------------------ */

    /** False means deliberately deferred before this movement. */
    private static function applyMovement(Movement $movement, FoldState $state): bool {
        if ($movement->delta > 0) {
            return self::applyInbound($movement, $state);
        } elseif ($movement->delta < 0) {
            self::applyOutbound($movement, $state);
        }
        return true;
    }

    private static function applyInbound(Movement $m, FoldState $state): bool {
        $origin = $m->reason;
        if ($m->reason === Reasons::TRANSFER_IN) {
            $basis = self::transferInboundCost($m);
            if ($basis === null) {
                return false;
            }
            [$unitCostOre, $isEstimate] = $basis;
            $origin = 'transfer';
        } else {
            [$unitCostOre, $isEstimate] = self::resolveInboundCost($m, $state);
        }

        $layerId = Layers::insert([
            'product_id'         => $m->productId,
            'location_id'        => $m->locationId,
            'source_movement_id' => $m->id,
            'origin'             => $origin,
            'ref_type'           => $m->refType,
            'ref_id'             => $m->refId,
            'ref_line'           => $m->refLine,
            'batch'              => $m->batch,
            'unit_cost_ore'      => $unitCostOre,
            'qty_original'       => $m->delta,
            'is_estimate'        => $isEstimate ? 1 : 0,
            'actor_id'           => $m->actorId,
            'occurred_at'        => $m->occurredAt,
        ]);
        $state->pushLayer($layerId, $m->id, $unitCostOre, $m->delta, $m->occurredAt);

        // True-up: settle outstanding provisionals (oldest sale first) against
        // this layer — restates their estimated COGS to the actual cost.
        while (($prov = $state->oldestOutstandingProvisional()) !== null) {
            $remaining = $state->layerRemaining($layerId);
            if ($remaining <= 1e-9) {
                break;
            }
            $qty   = min($prov['outstanding'], $remaining);
            $layer = ['id' => $layerId, 'unit_cost_ore' => $unitCostOre];
            self::writeTrueUpPair($m->productId, $m->locationId, $prov, $layer, $qty, $m->occurredAt);
            $state->settleProvisional($prov['movement_id'], $qty);
            $state->consumeLayer($layerId, $qty);
        }
        return true;
    }

    private static function applyOutbound(Movement $m, FoldState $state): void {
        $need = -$m->delta;

        // Reversal of a positive movement: specific identification — drain the
        // exact layer it created before falling back to FIFO.
        if ($m->reason === Reasons::REVERSAL && $m->refType === 'movement' && $m->refId !== null) {
            $own = $state->layerBySourceMovement($m->refId);
            if ($own !== null) {
                $qty = min($need, $own['remaining']);
                self::writeConsumption($m, $own['id'], $own['unit_cost_ore'], $qty, Consumptions::KIND_FIFO);
                $state->consumeLayer($own['id'], $qty);
                $need -= $qty;
            }
        }

        while ($need > 1e-9 && ($layer = $state->oldestOpenLayer()) !== null) {
            $qty = min($need, $layer['remaining']);
            $kind = $m->reason === Reasons::TRANSFER_OUT ? Consumptions::KIND_TRANSFER_OUT : Consumptions::KIND_FIFO;
            self::writeConsumption($m, $layer['id'], $layer['unit_cost_ore'], $qty, $kind);
            $state->consumeLayer($layer['id'], $qty);
            $need -= $qty;
        }

        if ($need > 1e-9) {
            // Nothing left to draw (negative stock / uncosted history): value the
            // shortfall at the last known cost and flag it provisional. The next
            // inbound layer restates it via the true-up pair.
            $estimate = $state->lastKnownCost();
            self::writeConsumption($m, null, $estimate, $need, Consumptions::KIND_PROVISIONAL);
            $state->addProvisional($m->id, $need, $estimate);
        }
    }

    /* --------------------------- Cost resolution -------------------------- */

    /** @return array{0:?int,1:bool} [unit_cost_ore|null, is_estimate] */
    private static function resolveInboundCost(Movement $m, FoldState $state): array {
        switch ($m->reason) {
            case Reasons::RECEIPT:
                $entered = CostInputs::forKey($m->idempotencyKey);
                if ($entered !== null) {
                    return [$entered, false];
                }
                if ($m->refType === 'po_line' && $m->refId !== null) {
                    $line = Lines::find($m->refId);
                    if ($line !== null && $line['unit_cost_ore'] !== null) {
                        return [(int) $line['unit_cost_ore'], false];
                    }
                }
                return self::estimateFallback($state);

            case Reasons::ADJUST:
                $entered = CostInputs::forKey($m->idempotencyKey);
                if ($entered !== null) {
                    return [$entered, false];
                }
                return self::estimateFallback($state);

            case Reasons::SALE_RESTORE:
            case Reasons::REFUND_RESTOCK:
            case Reasons::ORDER_EDIT:
                return self::returnCost($m, $state);

            case Reasons::REVERSAL:
                return self::reversedOutboundCost($m, $state);

            case Reasons::INITIAL:
                $wcCost = self::productWcCogsOre($m->productId);
                if ($wcCost !== null) {
                    return [$wcCost, true];
                }
                return self::estimateFallback($state);

            default: // count overage, external, import, rest, admin_edit, site-extended
                return self::estimateFallback($state);
        }
    }

    /** @return array{0:?int,1:bool} */
    private static function estimateFallback(FoldState $state): array {
        return [$state->lastKnownCost(), true];
    }

    /**
     * Resolve transfer_in from the completed transfer_out consumptions. Null is
     * the deferral signal; [null, true] is a real uncosted source basis.
     *
     * @return array{0:?int,1:bool}|null
     */
    private static function transferInboundCost(Movement $m): ?array {
        if ($m->batch === null) {
            return null;
        }
        $basis = Consumptions::transferBasis($m->batch, $m->productId);
        if ($basis === null || $basis['qty'] <= 1e-9) {
            return null;
        }
        if (!$basis['all_costed'] || $basis['cost_ore'] === null) {
            return [null, true];
        }
        // ponytail: blended source lots collapse to one rounded per-unit cost, so
        // total inventory value is conserved only within integer rounding
        // (≤ floor(qty/2) øre per transfer, and a reverse transfer need not zero
        // value exactly); quantity/_stock stay exact. Upgrade path if exact value
        // conservation is ever required: carry the remainder as a compensating
        // row, or recreate the drawn source lots individually on the destination.
        return [
            (int) round($basis['cost_ore'] / $basis['qty']),
            $basis['is_estimate'],
        ];
    }

    /**
     * Returns policy: re-enter at the blended cost of THIS order's own prior
     * consumptions for the product, capped at (net consumed − already
     * restored). Excess (or no history) is valued at the last known cost and
     * the whole layer flagged estimate. One movement = one layer, so a partial
     * cap blends the two costs by weight.
     *
     * @return array{0:?int,1:bool}
     */
    private static function returnCost(Movement $m, FoldState $state): array {
        if ($m->refType !== 'order' || $m->refId === null) {
            return self::estimateFallback($state);
        }

        $net      = Consumptions::netForOrderProduct($m->refId, $m->productId, $m->locationId);
        $restored = Layers::restoredQtyForOrder($m->refId, $m->productId, $m->locationId);
        $blended  = $net['costed_qty'] > 1e-9 ? $net['cost_ore'] / $net['costed_qty'] : null;
        $cap      = max(0.0, $net['qty'] - $restored);

        if ($blended === null) {
            return self::estimateFallback($state);
        }
        if ($cap >= $m->delta - 1e-9) {
            return [(int) round($blended), false];
        }
        if ($cap > 1e-9) {
            $excess   = $m->delta - $cap;
            $fallback = $state->lastKnownCost() ?? $blended;
            $weighted = ($cap * $blended + $excess * $fallback) / $m->delta;
            return [(int) round($weighted), true];
        }
        return self::estimateFallback($state);
    }

    /**
     * Reversal of a negative movement: re-enter at the blended actual cost of
     * the reversed movement's own consumption rows (true-up pairs net in).
     *
     * @return array{0:?int,1:bool}
     */
    private static function reversedOutboundCost(Movement $m, FoldState $state): array {
        if ($m->refType !== 'movement' || $m->refId === null) {
            return self::estimateFallback($state);
        }
        $net = Consumptions::netForMovement($m->refId);
        if ($net['qty'] > 1e-9 && $net['all_costed'] && $net['cost_ore'] !== null) {
            return [(int) round($net['cost_ore'] / $net['qty']), false];
        }
        return self::estimateFallback($state);
    }

    /** WooCommerce product-level COGS value (kr float) → øre, if set. */
    private static function productWcCogsOre(int $productId): ?int {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        $product = \wc_get_product($productId);
        if (!$product instanceof \WC_Product || !method_exists($product, 'get_cogs_value')) {
            return null;
        }
        $value = (float) $product->get_cogs_value();
        return $value > 0 ? (int) round($value * 100) : null;
    }

    /* ------------------------------- Writes ------------------------------- */

    private static function writeConsumption(Movement $m, ?int $layerId, ?int $unitCostOre, float $qty, string $kind): void {
        Consumptions::insert([
            'layer_id'      => $layerId,
            'movement_id'   => $m->id,
            'product_id'    => $m->productId,
            'location_id'   => $m->locationId,
            'kind'          => $kind,
            'qty'           => $qty,
            'unit_cost_ore' => $unitCostOre,
            'cost_ore'      => $unitCostOre !== null ? (int) round($qty * $unitCostOre) : null,
            'occurred_at'   => $m->occurredAt,
        ]);
    }

    /**
     * The provisional true-up pair: reverse the estimated valuation of a
     * provisional consumption (−qty) and re-draw the same qty from the real
     * layer (+qty), both on the ORIGINAL sale movement so its net stays exact.
     *
     * @param array{movement_id:int,outstanding:float,unit_cost_ore:?int} $prov
     * @param array{id:int,unit_cost_ore:?int}                            $layer
     */
    private static function writeTrueUpPair(int $productId, int $locationId, array $prov, array $layer, float $qty, string $occurredAt): void {
        Consumptions::insert([
            'layer_id'      => null,
            'movement_id'   => $prov['movement_id'],
            'product_id'    => $productId,
            'location_id'   => $locationId,
            'kind'          => Consumptions::KIND_PROV_REVERSAL,
            'qty'           => -$qty,
            'unit_cost_ore' => $prov['unit_cost_ore'],
            'cost_ore'      => $prov['unit_cost_ore'] !== null ? -((int) round($qty * $prov['unit_cost_ore'])) : null,
            'occurred_at'   => $occurredAt,
        ]);
        Consumptions::insert([
            'layer_id'      => $layer['id'],
            'movement_id'   => $prov['movement_id'],
            'product_id'    => $productId,
            'location_id'   => $locationId,
            'kind'          => Consumptions::KIND_BACKFILL,
            'qty'           => $qty,
            'unit_cost_ore' => $layer['unit_cost_ore'],
            'cost_ore'      => $layer['unit_cost_ore'] !== null ? (int) round($qty * $layer['unit_cost_ore']) : null,
            'occurred_at'   => $occurredAt,
        ]);
    }
}
