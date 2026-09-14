<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Schema;

/**
 * Catch-up sweep — the correctness backstop behind the live movement hook.
 * balances.last_movement_id is already a per-product high-water mark over ALL
 * movements, so lag detection is one join: any balance row whose newest
 * movement is beyond the product's costing watermark (or the anchor, for
 * products with no cost row yet) has pending fold work.
 *
 * Runs from the daily Reconciler, the CLI, and the Lagerverdi render — never
 * needs its own schedule.
 *
 * @internal Implementation of the Costing module — reach it through Costing.
 */
final class Sweeper {

    /** @return array{products:int,movements:int} */
    public static function sweepAll(): array {
        if (!Costing::enabled() || Costing::anchorId() === null) {
            return ['products' => 0, 'movements' => 0];
        }

        global $wpdb;
        $lagging = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT b.product_id, b.location_id
             FROM ' . Schema::balances() . ' b
             LEFT JOIN ' . Schema::productCost() . ' pc
               ON pc.product_id = b.product_id AND pc.location_id = b.location_id
             WHERE b.last_movement_id > GREATEST(COALESCE(pc.last_movement_id, 0), %d)',
            (int) Costing::anchorId()
        ), ARRAY_A);

        $products  = [];
        $movements = 0;

        // A destination with a lower location id can be encountered before its
        // source and deliberately defer. Retry the same lagging set while a
        // pass makes progress, so the source then destination both fold in one
        // sweep without turning deferral into an exception/retry loop.
        $remainingPasses = max(2, count($lagging) + 1);
        do {
            $passMovements = 0;
            foreach ($lagging as $row) {
                try {
                    $count = Engine::processProduct((int) $row['product_id'], (int) $row['location_id']);
                    if ($count > 0) {
                        $key = (int) $row['product_id'] . ':' . (int) $row['location_id'];
                        $products[$key] = true;
                        $movements += $count;
                        $passMovements += $count;
                    }
                } catch (\Throwable $e) {
                    Logger::error('cost_sweep_failed', [
                        'product' => (int) $row['product_id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
            $remainingPasses--;
        } while ($passMovements > 0 && $remainingPasses > 0);

        if ($movements > 0) {
            Logger::info('cost_sweep_caught_up', ['products' => count($products), 'movements' => $movements]);
        }
        return ['products' => count($products), 'movements' => $movements];
    }
}
