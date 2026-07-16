<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Schema;

/**
 * Append-only consumption rows — the COGS side of the projection.
 *
 * kind:
 *  - fifo          layer drawn by a negative movement (the normal case)
 *  - provisional   negative movement with no open layer (oversell); layer_id NULL,
 *                  valued at the estimate current when it happened
 *  - prov_reversal −qty compensator written when a later layer settles a
 *                  provisional (movement_id = the ORIGINAL sale movement)
 *  - backfill      +qty re-draw of that settled qty against the real layer
 *                  (movement_id = the original sale movement — so per-movement
 *                  sums always net to the movement's true COGS)
 *  - correction    operator cost correction drain (movement_id NULL,
 *                  ref = the corrected layer via its layer_id)
 *  - transfer_out  FIFO layer drawn by the source side of a transfer
 *
 * cost_ore is qty × unit_cost_ore, exact integer øre; NULL when the source
 * layer/estimate is uncosted.
 */
final class Consumptions {

    public const KIND_FIFO          = 'fifo';
    public const KIND_PROVISIONAL   = 'provisional';
    public const KIND_PROV_REVERSAL = 'prov_reversal';
    public const KIND_BACKFILL      = 'backfill';
    public const KIND_CORRECTION    = 'correction';
    public const KIND_TRANSFER_OUT  = 'transfer_out';

    /**
     * @param array<string,mixed> $data column => value; NULLs omitted
     * @return int new consumption id
     */
    public static function insert(array $data): int {
        global $wpdb;

        $columns = [
            'layer_id'      => '%d',
            'movement_id'   => '%d',
            'product_id'    => '%d',
            'location_id'   => '%d',
            'kind'          => '%s',
            'qty'           => '%s',
            'unit_cost_ore' => '%d',
            'cost_ore'      => '%d',
            'occurred_at'   => '%s',
            'created_at'    => '%s',
        ];
        $data['created_at'] = $data['created_at'] ?? gmdate('Y-m-d H:i:s');

        $row     = [];
        $formats = [];
        foreach ($columns as $column => $format) {
            if (!array_key_exists($column, $data) || $data[$column] === null) {
                continue;
            }
            $value = $data[$column];
            if ($column === 'qty') {
                $value = number_format((float) $value, 3, '.', '');
            }
            $row[$column] = $value;
            $formats[]    = $format;
        }

        $ok = $wpdb->insert(Schema::costConsumptions(), $row, $formats);
        if ($ok === false || $wpdb->insert_id <= 0) {
            throw new CostingException('Cost consumption insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /** All consumption rows of one movement. @return array<int,array<string,mixed>> */
    public static function forMovement(int $movementId): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::costConsumptions() . ' WHERE movement_id = %d ORDER BY id ASC',
            $movementId
        ), ARRAY_A);
    }

    /**
     * Net COGS of one movement: Σ cost_ore across all its rows (fifo +
     * provisional + prov_reversal + backfill net out to actual). Returns
     * [qty, cost_ore|null, all_costed] — cost NULL when no row carried a cost.
     *
     * @return array{qty:float,cost_ore:?int,all_costed:bool}
     */
    public static function netForMovement(int $movementId): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COALESCE(SUM(qty), 0) AS qty,
                    SUM(cost_ore) AS cost_ore,
                    SUM(CASE WHEN cost_ore IS NULL THEN 1 ELSE 0 END) AS uncosted_rows
             FROM ' . Schema::costConsumptions() . ' WHERE movement_id = %d',
            $movementId
        ), ARRAY_A);
        return [
            'qty'        => (float) ($row['qty'] ?? 0),
            'cost_ore'   => isset($row['cost_ore']) && $row['cost_ore'] !== null ? (int) $row['cost_ore'] : null,
            'all_costed' => (int) ($row['uncosted_rows'] ?? 0) === 0,
        ];
    }

    /**
     * Cost basis moved out by the matching side of a transfer.
     *
     * Null means the transfer_out movement has not been folded yet. A present
     * result may still have a null unit cost: that is a real, fully folded but
     * uncosted source, and transfer_in must preserve it as uncosted/estimated.
     *
     * @return array{qty:float,cost_ore:?int,all_costed:bool,is_estimate:bool}|null
     */
    public static function transferBasis(string $batch, int $productId): ?array {
        global $wpdb;

        $movement = $wpdb->get_row($wpdb->prepare(
            'SELECT id, delta FROM ' . Schema::movements() . '
             WHERE batch = %s AND product_id = %d AND reason = %s
             ORDER BY id ASC LIMIT 1',
            $batch,
            $productId,
            \Kaupang\Stock\Ledger\Reasons::TRANSFER_OUT
        ), ARRAY_A);
        if (!is_array($movement)) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS row_count,
                    COALESCE(SUM(c.qty), 0) AS qty,
                    SUM(c.cost_ore) AS cost_ore,
                    SUM(CASE WHEN c.cost_ore IS NULL THEN 1 ELSE 0 END) AS uncosted_rows,
                    MAX(CASE
                        WHEN c.kind = %s OR c.cost_ore IS NULL OR COALESCE(l.is_estimate, 0) = 1 THEN 1
                        ELSE 0
                    END) AS is_estimate
             FROM ' . Schema::costConsumptions() . ' c
             LEFT JOIN ' . Schema::costLayers() . ' l ON l.id = c.layer_id
             WHERE c.movement_id = %d',
            self::KIND_PROVISIONAL,
            (int) $movement['id']
        ), ARRAY_A);

        $expected = abs((float) $movement['delta']);
        $qty      = (float) ($row['qty'] ?? 0);
        if ((int) ($row['row_count'] ?? 0) === 0 || abs($qty - $expected) > 1e-9) {
            return null;
        }

        return [
            'qty'         => $qty,
            'cost_ore'    => isset($row['cost_ore']) && $row['cost_ore'] !== null ? (int) $row['cost_ore'] : null,
            'all_costed'  => (int) ($row['uncosted_rows'] ?? 0) === 0,
            'is_estimate' => (int) ($row['is_estimate'] ?? 0) === 1,
        ];
    }

    /**
     * Net consumption for one ORDER × product — the returns-policy source.
     * Joins through movements on (ref_type='order', ref_id): sale, order_edit
     * and their true-up rows all net in.
     *
     * @return array{qty:float,costed_qty:float,cost_ore:int}
     */
    public static function netForOrderProduct(int $orderId, int $productId, int $locationId): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COALESCE(SUM(c.qty), 0) AS qty,
                    COALESCE(SUM(CASE WHEN c.cost_ore IS NOT NULL THEN c.qty ELSE 0 END), 0) AS costed_qty,
                    COALESCE(SUM(c.cost_ore), 0) AS cost_ore
             FROM ' . Schema::costConsumptions() . ' c
             JOIN ' . Schema::movements() . " m ON m.id = c.movement_id
             WHERE m.ref_type = 'order' AND m.ref_id = %d
               AND c.product_id = %d AND c.location_id = %d AND m.delta < 0",
            $orderId,
            $productId,
            Balances::resolveLocation($locationId)
        ), ARRAY_A);
        return [
            'qty'        => (float) ($row['qty'] ?? 0),
            'costed_qty' => (float) ($row['costed_qty'] ?? 0),
            'cost_ore'   => (int) ($row['cost_ore'] ?? 0),
        ];
    }

    /**
     * Outstanding provisionals for a product, oldest movement first: qty net of
     * prior reversals, with the estimate each was valued at.
     *
     * @return array<int,array{movement_id:int,outstanding:float,unit_cost_ore:?int,occurred_at:string}>
     */
    public static function outstandingProvisionals(int $productId, int $locationId): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT movement_id,
                    SUM(qty) AS outstanding,
                    MAX(CASE WHEN kind = %s THEN unit_cost_ore ELSE NULL END) AS unit_cost_ore,
                    MIN(occurred_at) AS occurred_at
             FROM ' . Schema::costConsumptions() . '
             WHERE product_id = %d AND location_id = %d AND kind IN (%s, %s)
             GROUP BY movement_id
             HAVING outstanding > 0
             ORDER BY movement_id ASC',
            self::KIND_PROVISIONAL,
            $productId,
            Balances::resolveLocation($locationId),
            self::KIND_PROVISIONAL,
            self::KIND_PROV_REVERSAL
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'movement_id'   => (int) $row['movement_id'],
                'outstanding'   => (float) $row['outstanding'],
                'unit_cost_ore' => $row['unit_cost_ore'] !== null ? (int) $row['unit_cost_ore'] : null,
                'occurred_at'   => (string) $row['occurred_at'],
            ];
        }
        return $out;
    }

    /** Recent consumptions for a product (drill-down). @return array<int,array<string,mixed>> */
    public static function forProduct(int $productId, int $locationId, int $limit = 100): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, m.reason, m.ref_type, m.ref_id, m.ref_line
             FROM ' . Schema::costConsumptions() . ' c
             LEFT JOIN ' . Schema::movements() . ' m ON m.id = c.movement_id
             WHERE c.product_id = %d AND c.location_id = %d
             ORDER BY c.id DESC
             LIMIT %d',
            $productId,
            Balances::resolveLocation($locationId),
            $limit
        ), ARRAY_A);
    }

    /**
     * COGS grouped by movement reason over a UTC date range (correction rows,
     * which have no movement, appear under 'correction').
     *
     * @return array<string,array{qty:float,cost_ore:int,uncosted_qty:float}>
     */
    public static function cogsByReason(string $fromUtc, string $toUtc): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(m.reason, 'correction') AS reason,
                    SUM(c.qty) AS qty,
                    COALESCE(SUM(c.cost_ore), 0) AS cost_ore,
                    COALESCE(SUM(CASE WHEN c.cost_ore IS NULL THEN c.qty ELSE 0 END), 0) AS uncosted_qty
             FROM " . Schema::costConsumptions() . ' c
             LEFT JOIN ' . Schema::movements() . ' m ON m.id = c.movement_id
             WHERE c.occurred_at >= %s AND c.occurred_at <= %s
             GROUP BY reason
             ORDER BY cost_ore DESC',
            $fromUtc,
            $toUtc
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['reason']] = [
                'qty'          => (float) $row['qty'],
                'cost_ore'     => (int) $row['cost_ore'],
                'uncosted_qty' => (float) $row['uncosted_qty'],
            ];
        }
        return $out;
    }
}
