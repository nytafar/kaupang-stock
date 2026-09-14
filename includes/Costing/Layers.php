<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Schema;

/**
 * Cost-layer reads and writes. A layer is IMMUTABLE: born once (from a
 * positive movement, an opening entry, or a correction), never updated or
 * deleted; its remaining quantity is always derived (qty_original − Σ drawn).
 * unit_cost_ore NULL means "cost unknown" — never a fake zero (0 øre is a
 * legitimate cost for samples/bonus goods).
 *
 * @internal Implementation of the Costing module — reach it through Costing.
 */
final class Layers {

    public const ORIGIN_OPENING    = 'opening';
    public const ORIGIN_CORRECTION = 'correction';

    /**
     * @param array<string,mixed> $data column => value; NULLs omitted
     * @return int new layer id
     */
    public static function insert(array $data): int {
        global $wpdb;

        $columns = [
            'product_id'         => '%d',
            'location_id'        => '%d',
            'source_movement_id' => '%d',
            'origin'             => '%s',
            'ref_type'           => '%s',
            'ref_id'             => '%d',
            'ref_line'           => '%d',
            'batch'              => '%s',
            'unit_cost_ore'      => '%d',
            'qty_original'       => '%s',
            'is_estimate'        => '%d',
            'actor_id'           => '%d',
            'note'               => '%s',
            'occurred_at'        => '%s',
            'created_at'         => '%s',
        ];
        $data['created_at'] = $data['created_at'] ?? gmdate('Y-m-d H:i:s');

        $row     = [];
        $formats = [];
        foreach ($columns as $column => $format) {
            if (!array_key_exists($column, $data) || $data[$column] === null) {
                continue;
            }
            $value = $data[$column];
            if ($column === 'qty_original') {
                $value = number_format((float) $value, 3, '.', '');
            }
            $row[$column] = $value;
            $formats[]    = $format;
        }

        $ok = $wpdb->insert(Schema::costLayers(), $row, $formats);
        if ($ok === false || $wpdb->insert_id <= 0) {
            throw new CostingException('Cost layer insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /** The layer a positive movement created (UNIQUE on source_movement_id). */
    public static function bySourceMovement(int $movementId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::costLayers() . ' WHERE source_movement_id = %d',
            $movementId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function find(int $layerId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::costLayers() . ' WHERE id = %d',
            $layerId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Open layers with derived remaining qty, in FIFO order (occurred_at, id).
     * Sees the current transaction's own inserts.
     *
     * @return array<int,array<string,mixed>> rows + 'remaining' (float)
     */
    public static function openForProduct(int $productId, int $locationId): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT l.*, (l.qty_original - COALESCE(SUM(c.qty), 0)) AS remaining
             FROM ' . Schema::costLayers() . ' l
             LEFT JOIN ' . Schema::costConsumptions() . ' c ON c.layer_id = l.id
             WHERE l.product_id = %d AND l.location_id = %d
             GROUP BY l.id
             HAVING remaining > 0
             ORDER BY l.occurred_at ASC, l.id ASC',
            $productId,
            Balances::resolveLocation($locationId)
        ), ARRAY_A);
        foreach ($rows as &$row) {
            $row['remaining'] = (float) $row['remaining'];
        }
        return $rows;
    }

    /** Derived remaining qty for one layer. */
    public static function remaining(int $layerId): float {
        global $wpdb;
        $val = $wpdb->get_var($wpdb->prepare(
            'SELECT l.qty_original - COALESCE((SELECT SUM(c.qty) FROM ' . Schema::costConsumptions() . ' c WHERE c.layer_id = l.id), 0)
             FROM ' . Schema::costLayers() . ' l WHERE l.id = %d',
            $layerId
        ));
        return $val !== null ? (float) $val : 0.0;
    }

    /**
     * Newest known unit cost for the product — the estimate source for
     * lineage-less inbounds and provisional consumptions.
     */
    public static function lastKnownCost(int $productId, int $locationId): ?int {
        global $wpdb;
        $ore = $wpdb->get_var($wpdb->prepare(
            'SELECT unit_cost_ore FROM ' . Schema::costLayers() . '
             WHERE product_id = %d AND location_id = %d AND unit_cost_ore IS NOT NULL
             ORDER BY id DESC LIMIT 1',
            $productId,
            Balances::resolveLocation($locationId)
        ));
        return $ore !== null ? (int) $ore : null;
    }

    public static function hasOpening(int $productId, int $locationId): bool {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Schema::costLayers() . " WHERE product_id = %d AND location_id = %d AND origin = %s LIMIT 1",
            $productId,
            Balances::resolveLocation($locationId),
            self::ORIGIN_OPENING
        ));
        return $id !== null;
    }

    /**
     * Qty already re-entered for an order (restore/refund layers) — the cap
     * counter for the returns policy.
     */
    public static function restoredQtyForOrder(int $orderId, int $productId, int $locationId): float {
        global $wpdb;
        $qty = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(qty_original), 0) FROM ' . Schema::costLayers() . "
             WHERE product_id = %d AND location_id = %d AND ref_type = 'order' AND ref_id = %d
               AND origin IN ('sale_restore', 'refund_restock', 'order_edit')",
            $productId,
            Balances::resolveLocation($locationId),
            $orderId
        ));
        return $qty !== null ? (float) $qty : 0.0;
    }

    /** All layers for a product, newest first (drill-down). @return array<int,array<string,mixed>> */
    public static function forProduct(int $productId, int $locationId, int $limit = 100): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT l.*, (l.qty_original - COALESCE(SUM(c.qty), 0)) AS remaining
             FROM ' . Schema::costLayers() . ' l
             LEFT JOIN ' . Schema::costConsumptions() . ' c ON c.layer_id = l.id
             WHERE l.product_id = %d AND l.location_id = %d
             GROUP BY l.id
             ORDER BY l.occurred_at DESC, l.id DESC
             LIMIT %d',
            $productId,
            Balances::resolveLocation($locationId),
            $limit
        ), ARRAY_A);
    }
}
