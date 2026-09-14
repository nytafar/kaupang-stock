<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Schema;

/**
 * Valuation reads — total value of goods on hand, point-in-time capable.
 * Append-only tables make "as of" a filtered sum: layers born at or before T
 * minus consumptions that occurred at or before T. Always computed live from
 * the tables (the cache is for quick current reads and verify, not reports).
 *
 * @internal Implementation of the Costing module — reach it through Costing.
 */
final class Valuation {

    /**
     * Per-product valuation rows.
     *
     * @param string|null $asOfUtc UTC 'Y-m-d H:i:s' (null = now)
     * @return array<int,array{
     *   product_id:int, location_id:int,
     *   open_qty:float, value_ore:int, uncosted_qty:float,
     *   provisional_qty:float, provisional_cost_ore:int,
     *   estimate_qty:float
     * }> keyed by product id and aggregated across locations
     */
    public static function rows(?string $asOfUtc = null): array {
        $out = [];
        foreach (self::rowsByLocation($asOfUtc) as $row) {
            $productId = $row['product_id'];
            if (!isset($out[$productId])) {
                $out[$productId] = self::emptyRow($productId, 0);
            }
            foreach (['open_qty', 'uncosted_qty', 'estimate_qty', 'provisional_qty'] as $field) {
                $out[$productId][$field] += (float) $row[$field];
            }
            foreach (['value_ore', 'provisional_cost_ore'] as $field) {
                $out[$productId][$field] += (int) $row[$field];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Per-product, per-location rows for multi-location reporting.
     *
     * @return array<int,array{
     *   product_id:int, location_id:int, open_qty:float, value_ore:int,
     *   uncosted_qty:float, estimate_qty:float, provisional_qty:float,
     *   provisional_cost_ore:int
     * }> ordered by product and location
     */
    public static function rowsByLocation(?string $asOfUtc = null): array {
        global $wpdb;
        $timeFilterLayers       = $asOfUtc !== null ? $wpdb->prepare(' AND l.occurred_at <= %s', $asOfUtc) : '';
        $timeFilterConsumptions = $asOfUtc !== null ? $wpdb->prepare(' AND c.occurred_at <= %s', $asOfUtc) : '';

        $layerRows = (array) $wpdb->get_results(
            'SELECT product_id, location_id,
                    SUM(remaining) AS open_qty,
                    COALESCE(SUM(CASE WHEN unit_cost_ore IS NOT NULL THEN ROUND(remaining * unit_cost_ore) ELSE 0 END), 0) AS value_ore,
                    COALESCE(SUM(CASE WHEN unit_cost_ore IS NULL THEN remaining ELSE 0 END), 0) AS uncosted_qty,
                    COALESCE(SUM(CASE WHEN is_estimate = 1 THEN remaining ELSE 0 END), 0) AS estimate_qty
             FROM (
                SELECT l.product_id, l.location_id, l.unit_cost_ore, l.is_estimate,
                       l.qty_original - COALESCE(SUM(c.qty), 0) AS remaining
                FROM ' . Schema::costLayers() . ' l
                LEFT JOIN ' . Schema::costConsumptions() . " c
                  ON c.layer_id = l.id$timeFilterConsumptions
                WHERE 1=1$timeFilterLayers
                GROUP BY l.id, l.product_id, l.location_id, l.unit_cost_ore, l.is_estimate, l.qty_original
             ) t
             WHERE t.remaining > 0
             GROUP BY product_id, location_id",
            ARRAY_A
        );

        $provRows = (array) $wpdb->get_results(
            'SELECT product_id, location_id,
                    COALESCE(SUM(qty), 0) AS prov_qty,
                    COALESCE(SUM(cost_ore), 0) AS prov_cost
             FROM ' . Schema::costConsumptions() . " c
             WHERE kind IN ('provisional', 'prov_reversal')$timeFilterConsumptions
             GROUP BY product_id, location_id",
            ARRAY_A
        );

        $out = [];
        foreach ($layerRows as $row) {
            $productId = (int) $row['product_id'];
            $locationId = (int) $row['location_id'];
            $key = $productId . ':' . $locationId;
            if (!isset($out[$key])) {
                $out[$key] = self::emptyRow($productId, $locationId);
            }
            $out[$key]['open_qty'] += (float) $row['open_qty'];
            $out[$key]['value_ore'] += (int) $row['value_ore'];
            $out[$key]['uncosted_qty'] += (float) $row['uncosted_qty'];
            $out[$key]['estimate_qty'] += (float) $row['estimate_qty'];
        }
        foreach ($provRows as $row) {
            $productId = (int) $row['product_id'];
            if ((float) $row['prov_qty'] <= 1e-9) {
                continue;
            }
            $locationId = (int) $row['location_id'];
            $key = $productId . ':' . $locationId;
            if (!isset($out[$key])) {
                $out[$key] = self::emptyRow($productId, $locationId);
            }
            $out[$key]['provisional_qty']      += (float) $row['prov_qty'];
            $out[$key]['provisional_cost_ore'] += (int) $row['prov_cost'];
        }
        $rows = array_values($out);
        usort($rows, static fn (array $a, array $b): int => [$a['product_id'], $a['location_id']] <=> [$b['product_id'], $b['location_id']]);
        return $rows;
    }

    /** @return array<string,int|float> */
    private static function emptyRow(int $productId, int $locationId): array {
        return [
            'product_id'           => $productId,
            'location_id'          => $locationId,
            'open_qty'             => 0.0,
            'value_ore'            => 0,
            'uncosted_qty'         => 0.0,
            'estimate_qty'         => 0.0,
            'provisional_qty'      => 0.0,
            'provisional_cost_ore' => 0,
        ];
    }

    /**
     * Aggregate totals for the valuation payload / report header.
     *
     * @return array{
     *   total_ore:int, open_qty:float, uncosted_qty:float, estimate_qty:float,
     *   provisional_qty:float, provisional_cost_ore:int, products:int, uncosted_products:int
     * }
     */
    public static function totals(?string $asOfUtc = null): array {
        $totals = [
            'total_ore'            => 0,
            'open_qty'             => 0.0,
            'uncosted_qty'         => 0.0,
            'estimate_qty'         => 0.0,
            'provisional_qty'      => 0.0,
            'provisional_cost_ore' => 0,
            'products'             => 0,
            'uncosted_products'    => 0,
        ];
        foreach (self::rows($asOfUtc) as $row) {
            $totals['total_ore']            += $row['value_ore'];
            $totals['open_qty']             += $row['open_qty'];
            $totals['uncosted_qty']         += $row['uncosted_qty'];
            $totals['estimate_qty']         += $row['estimate_qty'];
            $totals['provisional_qty']      += $row['provisional_qty'];
            $totals['provisional_cost_ore'] += $row['provisional_cost_ore'];
            $totals['products']++;
            if ($row['uncosted_qty'] > 1e-9) {
                $totals['uncosted_products']++;
            }
        }
        return $totals;
    }

    /**
     * COGS report over a UTC datetime range: by movement reason + the period
     * reconciliation identity (opening + inbound − COGS = closing).
     *
     * @return array{
     *   from:string, to:string,
     *   by_reason:array<string,array{qty:float,cost_ore:int,uncosted_qty:float}>,
     *   cogs_ore:int,
     *   opening_ore:int, closing_ore:int, inbound_ore:int,
     *   identity_gap_ore:int
     * }
     */
    public static function cogsReport(string $fromUtc, string $toUtc): array {
        global $wpdb;
        $byReason = Consumptions::cogsByReason($fromUtc, $toUtc);

        $cogs = 0;
        foreach ($byReason as $bucket) {
            $cogs += $bucket['cost_ore'];
        }

        $openingTotals = self::totals(self::justBefore($fromUtc));
        $closingTotals = self::totals($toUtc);

        // Inbound basis added in the period = Σ (qty_original × cost) of layers
        // born in the window (opening/correction layers included — corrections
        // net against their correction consumption inside the same window).
        $inbound = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(CASE WHEN unit_cost_ore IS NOT NULL THEN ROUND(qty_original * unit_cost_ore) ELSE 0 END), 0)
             FROM ' . Schema::costLayers() . '
             WHERE occurred_at >= %s AND occurred_at <= %s',
            $fromUtc,
            $toUtc
        ));

        $opening = $openingTotals['total_ore'] - $openingTotals['provisional_cost_ore'];
        $closing = $closingTotals['total_ore'] - $closingTotals['provisional_cost_ore'];

        return [
            'from'             => $fromUtc,
            'to'               => $toUtc,
            'by_reason'        => $byReason,
            'cogs_ore'         => $cogs,
            'opening_ore'      => $opening,
            'closing_ore'      => $closing,
            'inbound_ore'      => $inbound,
            'identity_gap_ore' => ($opening + $inbound - $cogs) - $closing,
        ];
    }

    private static function justBefore(string $utc): string {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return $utc;
        }
        return $dt->modify('-1 second')->format('Y-m-d H:i:s');
    }
}
