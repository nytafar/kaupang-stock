<?php
declare(strict_types=1);

namespace Kaupang\Stock\Ledger;

use Kaupang\Stock\Logging\Logger;

/**
 * The wc_update_product_stock() bridge for owned movements, plus the
 * directional recovery ("heal") both the §3 retry path and the §5 reconciler
 * key on.
 *
 * Always relative, never 'set': core's increase/decrease SQL is the atomic
 * `meta_value = meta_value ± X`, which composes correctly with a concurrent
 * checkout decrement — a 'set' would clobber it. $updating stays false so
 * $product->save() runs: stock_status derives, set_stock hooks fire, the
 * meta-lookup row updates — Back In Stock Notifications, MnM availability and
 * every other reader keep working untouched.
 *
 * The reentrancy guard tells the Observer that the set_stock events now firing
 * are our own write-through — it records nothing new for them.
 */
final class WriteThrough {

    private static bool $guard = false;
    private static string $context = '';

    public static function guardActive(): bool {
        return self::$guard;
    }

    public static function guardContext(): string {
        return self::$context;
    }

    /**
     * Push one owned movement into WooCommerce. Post-commit, so a crash between
     * ledger commit and here leaves `_stock` lagging — recovered directionally
     * by completeIfPending()/healProduct(), never by a compensating entry that
     * would erase the operator's recorded movement.
     */
    public static function apply(Movement $movement): bool {
        $product = \wc_get_product($movement->productId);
        if (!$product instanceof \WC_Product) {
            Logger::error('write_through_no_product', ['movement' => $movement->id, 'product' => $movement->productId]);
            return false;
        }
        if (!$product->managing_stock()) {
            // wc_update_product_stock() would silently no-op; leave the
            // watermark alone and let the reconciler surface the product as
            // out-of-scope instead of pretending the write happened.
            Logger::warning('write_through_skipped_not_managing', ['movement' => $movement->id, 'product' => $movement->productId]);
            return false;
        }

        $result = self::guarded('write_through', static function () use ($product, $movement) {
            return \wc_update_product_stock(
                $product,
                abs($movement->delta),
                $movement->delta > 0 ? 'increase' : 'decrease'
            );
        });

        if ($result === false || $result === null || \is_wp_error($result)) {
            Logger::error('write_through_failed', [
                'movement' => $movement->id,
                'product'  => $movement->productId,
                'error'    => \is_wp_error($result) ? $result->get_error_message() : 'no_result',
            ]);
            return false;
        }

        Balances::advanceWritten($movement->productId, $movement->locationId, $movement->id);
        return true;
    }

    /**
     * §3 step 3: an idempotency-key hit means a prior attempt recorded this
     * movement — if that attempt died before its write-through ran, finish the
     * job so an operator retry is safe end-to-end, not merely ledger-idempotent.
     */
    public static function completeIfPending(Movement $movement): void {
        $row = Balances::row($movement->productId, $movement->locationId);
        if ($row !== null && (int) $row['last_written_id'] < $movement->id) {
            self::healProduct($movement->productId, $movement->locationId);
        }
    }

    /**
     * Directional recovery for one product (§3 step 7 / §5):
     *
     *   unwritten owned tail (last_written_id < last_movement_id with owned
     *   movements in the window) → REPLAY the ledger↔Woo gap into Woo as one
     *   relative update under the 'write_through_replay' guard;
     *   gap already closed (write ran, watermark crash) → just advance the
     *   watermark; no owned tail → 'no_tail' (any divergence is external — the
     *   absorb/compensate path owns it).
     *
     * @return string clean|aligned|replayed|no_tail|failed
     */
    public static function healProduct(int $productId, int $locationId = 0): string {
        global $wpdb;
        $locationId = Balances::resolveLocation($locationId);

        $wpdb->query('START TRANSACTION');
        try {
            $rows = Balances::lockProductRows($productId, $locationId);
            $owned = "'" . implode("','", array_map('esc_sql', Reasons::owned())) . "'";
            $targets = [];
            foreach ($rows as $locId => $row) {
                $lastMovement = (int) $row['last_movement_id'];
                $lastWritten = (int) $row['last_written_id'];
                if ($lastWritten >= $lastMovement) {
                    continue;
                }
                $tailCount = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . \Kaupang\Stock\Schema::movements()
                    . " WHERE product_id = %d AND location_id = %d AND id > %d AND reason IN ($owned)",
                    $productId,
                    $locId,
                    $lastWritten
                ));
                if ($tailCount > 0) {
                    $targets[$locId] = $lastMovement;
                }
            }

            if ($targets === []) {
                $wpdb->query('COMMIT');
                $pending = array_filter($rows, static fn (array $row): bool => (int) $row['last_written_id'] < (int) $row['last_movement_id']);
                return $pending === [] ? 'clean' : 'no_tail';
            }

            $fresh  = Ledger::readStockDirect($productId);
            $onHand = array_sum(array_map(static fn (array $row): float => (float) $row['on_hand'], $rows));
            $gap    = $onHand - $fresh;

            if (abs($gap) < 1e-9) {
                // Tail was actually written; only the watermark update crashed.
                foreach ($targets as $locId => $target) {
                    $row = $rows[$locId];
                    Balances::updateLocked($productId, $locId, (float) $row['on_hand'], (int) $row['last_movement_id'], $target);
                }
                $wpdb->query('COMMIT');
                return 'aligned';
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            Logger::error('heal_failed', ['product' => $productId, 'error' => $e->getMessage()]);
            return 'failed';
        }

        // Outside the transaction (third-party hook code runs in save()).
        // Relative, so a checkout decrement that lands in between composes.
        $product = \wc_get_product($productId);
        if (!$product instanceof \WC_Product || !$product->managing_stock()) {
            Logger::warning('heal_replay_skipped', ['product' => $productId]);
            return 'failed';
        }
        $result = self::guarded('write_through_replay', static function () use ($product, $gap) {
            return \wc_update_product_stock($product, abs($gap), $gap > 0 ? 'increase' : 'decrease');
        });
        if ($result === false || $result === null || \is_wp_error($result)) {
            Logger::error('heal_replay_failed', ['product' => $productId, 'gap' => $gap]);
            return 'failed';
        }

        foreach ($targets as $locId => $target) {
            Balances::advanceWritten($productId, (int) $locId, (int) $target);
        }
        Logger::notice('heal_replayed', ['product' => $productId, 'gap' => $gap, 'locations' => array_keys($targets)]);
        return 'replayed';
    }

    /** @return mixed the callback result */
    private static function guarded(string $context, callable $fn) {
        self::$guard   = true;
        self::$context = $context;
        try {
            return $fn();
        } finally {
            self::$guard   = false;
            self::$context = '';
        }
    }
}
