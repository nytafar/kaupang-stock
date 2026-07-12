<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Schema;

/**
 * The product_cost cache — the costing analogue of the balances projection.
 * Never source of truth: open_qty/value_ore/provisional_* re-derive from
 * cost_layers + cost_consumptions at any time (recomputeAggregates()).
 *
 * last_movement_id is the costing watermark: every movement with id at or
 * below it has been folded for this product. It is checked and advanced under
 * the same FOR UPDATE row-lock discipline Balances uses, in one transaction
 * with the cost rows — a crash before commit replays cleanly.
 *
 * Also owns the module's transaction helpers: every cost write happens inside
 * ProductCost::begin()/commit() around a lockRow().
 */
final class ProductCost {

    /**
     * Ensure a row exists (watermark seeded at the anchor), then lock it.
     * MUST be called inside an open transaction.
     *
     * @return array<string,mixed> the locked row
     */
    public static function lockRow(int $productId, int $locationId): array {
        global $wpdb;
        $locationId = Balances::resolveLocation($locationId);
        $table      = Schema::productCost();
        $anchor     = (int) (Costing::anchorId() ?? 0);

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (product_id, location_id, open_qty, value_ore, provisional_qty, provisional_cost_ore, last_movement_id, updated_at)
             VALUES (%d, %d, 0, 0, 0, 0, %d, %s)",
            $productId,
            $locationId,
            $anchor,
            gmdate('Y-m-d H:i:s')
        ));
        if ($inserted === false) {
            throw new CostingException("product_cost row ensure failed for product $productId: " . $wpdb->last_error);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND location_id = %d FOR UPDATE",
            $productId,
            $locationId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new CostingException("Could not lock product_cost row for product $productId: " . $wpdb->last_error);
        }
        return $row;
    }

    /**
     * Recompute the cached aggregates from the cost tables (sees the current
     * transaction's own inserts) and write them together with the watermark.
     * Caller holds the row lock.
     */
    public static function recomputeAndUpdateLocked(int $productId, int $locationId, ?int $lastMovementId = null): void {
        global $wpdb;
        $locationId = Balances::resolveLocation($locationId);

        // Open layers: qty_original − Σ drawn (fifo/backfill/correction rows all
        // carry layer_id and positive qty). NULL-cost layers count in qty only.
        $layerAgg = $wpdb->get_row($wpdb->prepare(
            'SELECT
                COALESCE(SUM(remaining), 0) AS open_qty,
                COALESCE(SUM(CASE WHEN unit_cost_ore IS NOT NULL THEN ROUND(remaining * unit_cost_ore) ELSE 0 END), 0) AS value_ore
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

        // Outstanding provisionals: provisional (+qty) net of prov_reversal (−qty).
        $provAgg = $wpdb->get_row($wpdb->prepare(
            'SELECT
                COALESCE(SUM(qty), 0) AS prov_qty,
                COALESCE(SUM(cost_ore), 0) AS prov_cost
             FROM ' . Schema::costConsumptions() . "
             WHERE product_id = %d AND location_id = %d AND kind IN ('provisional', 'prov_reversal')",
            $productId,
            $locationId
        ), ARRAY_A);

        $watermarkSql = $lastMovementId !== null
            ? $wpdb->prepare('last_movement_id = %d', $lastMovementId)
            : 'last_movement_id = last_movement_id';

        $set = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::productCost() . "
             SET open_qty = %s, value_ore = %d, provisional_qty = %s, provisional_cost_ore = %d,
                 $watermarkSql, updated_at = %s
             WHERE product_id = %d AND location_id = %d",
            number_format((float) ($layerAgg['open_qty'] ?? 0), 3, '.', ''),
            (int) ($layerAgg['value_ore'] ?? 0),
            number_format((float) ($provAgg['prov_qty'] ?? 0), 3, '.', ''),
            (int) ($provAgg['prov_cost'] ?? 0),
            gmdate('Y-m-d H:i:s'),
            $productId,
            $locationId
        ));
        if ($set === false) {
            throw new CostingException("product_cost update failed for product $productId: " . $wpdb->last_error);
        }
    }

    /** @return array<string,mixed>|null */
    public static function row(int $productId, int $locationId = 0): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::productCost() . ' WHERE product_id = %d AND location_id = %d',
            $productId,
            Balances::resolveLocation($locationId)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** All cache rows keyed by product_id (single-location v1). @return array<int,array<string,mixed>> */
    public static function rows(): array {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results('SELECT * FROM ' . Schema::productCost(), ARRAY_A) as $row) {
            $out[(int) $row['product_id']] = $row;
        }
        return $out;
    }

    /* ---------------------- Transaction helpers --------------------------- */

    public static function begin(): void {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new CostingException('Could not open transaction: ' . $wpdb->last_error);
        }
    }

    public static function commit(): void {
        global $wpdb;
        if ($wpdb->query('COMMIT') === false) {
            throw new CostingException('Commit failed: ' . $wpdb->last_error);
        }
    }

    public static function rollback(): void {
        global $wpdb;
        $wpdb->query('ROLLBACK');
    }
}
