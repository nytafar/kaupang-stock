<?php
declare(strict_types=1);

namespace Kaupang\Stock\Observe;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Logging\Logger;

/**
 * Enable-time sweep (§4 seeding path a): a balance row for every stock-managed
 * product, an `initial` movement only where _stock ≠ 0. Idempotent and safe to
 * interleave with live checkouts — the per-product check and write share one
 * balance-row lock inside Ledger::seedProduct().
 *
 * Lazy seeding (paths b/c) needs no sweep: the ledger seeds any product the
 * first time it must touch it. The sweep just primes the whole catalog so
 * Lagerstatus and the reconciler are complete from day one.
 */
final class Seeder {

    /** @return array{products:int,seeded:int} */
    public static function sweep(): array {
        $seeded = 0;
        $ids    = self::stockManagedProductIds();
        foreach ($ids as $productId) {
            try {
                if (Ledger::seedProduct($productId)) {
                    $seeded++;
                }
            } catch (\Throwable $e) {
                Logger::error('seed_failed', ['product' => $productId, 'error' => $e->getMessage()]);
            }
        }
        Logger::notice('seed_sweep', ['products' => count($ids), 'seeded' => $seeded]);
        return ['products' => count($ids), 'seeded' => $seeded];
    }

    /**
     * Every entity that owns _stock: products and variations whose OWN
     * _manage_stock is 'yes' (a parent-managed variation stores 'parent'/'no'
     * and is keyed by its parent — exactly get_stock_managed_by_id()'s rule).
     *
     * @return int[]
     */
    public static function stockManagedProductIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                     ON pm.post_id = p.ID AND pm.meta_key = '_manage_stock' AND pm.meta_value = 'yes'
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')"
        );
        return array_map('intval', (array) $ids);
    }
}
