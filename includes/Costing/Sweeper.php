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

        $products  = 0;
        $movements = 0;
        foreach ($lagging as $row) {
            try {
                $movements += Engine::processProduct((int) $row['product_id'], (int) $row['location_id']);
                $products++;
            } catch (\Throwable $e) {
                Logger::error('cost_sweep_failed', [
                    'product' => (int) $row['product_id'],
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($movements > 0) {
            Logger::info('cost_sweep_caught_up', ['products' => $products, 'movements' => $movements]);
        }
        return ['products' => $products, 'movements' => $movements];
    }
}
