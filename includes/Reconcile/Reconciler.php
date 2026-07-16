<?php
declare(strict_types=1);

namespace Kaupang\Stock\Reconcile;

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Ledger\WriteThrough;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Observe\Seeder;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

/**
 * §5: the drift detector that makes every projection provably re-derivable.
 * Per product the invariant is
 *
 *   SUM(movements.delta across locations) == SUM(balances.on_hand)
 *     == _stock == lookup.stock_quantity
 *
 * The scheduled run REPORTS only (option + log); healing is operator-initiated
 * (report screen button / `wp kaupang-stock verify --heal`) and direction-aware:
 * an unwritten owned tail is REPLAYED into Woo (WriteThrough::healProduct),
 * only a mismatch with no tail becomes a compensating `external` movement.
 * History is never edited.
 */
final class Reconciler {

    public const HOOK          = 'kaupang_stock_reconcile';
    public const AS_GROUP      = 'kaupang-stock';
    public const REPORT_OPTION = 'kaupang_stock_last_reconcile';

    public static function register(): void {
        \add_action(self::HOOK, [self::class, 'runScheduled']);
        \add_action('init', [self::class, 'ensureScheduled'], 20);
    }

    public static function ensureScheduled(): void {
        if (!Settings::enabled() || !function_exists('as_has_scheduled_action')) {
            return;
        }
        if (!\as_has_scheduled_action(self::HOOK, [], self::AS_GROUP)) {
            \as_schedule_recurring_action(
                (int) strtotime('tomorrow 03:30'),
                DAY_IN_SECONDS,
                self::HOOK,
                [],
                self::AS_GROUP
            );
        }
    }

    public static function runScheduled(): void {
        try {
            $report = self::run();
            if (!empty($report['issues'])) {
                Logger::warning('reconcile_issues', ['count' => count($report['issues'])]);
            } else {
                Logger::info('reconcile_clean', ['products' => $report['checked']]);
            }
        } catch (\Throwable $e) {
            Logger::error('reconcile_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Run the invariant check and persist the report (non-autoloaded option —
     * the Diagnostics-style screen and CLI read it). When costing is on, the
     * catch-up sweep runs first (so the cost section never reports pure lag)
     * and the report gains a `cost` section (Costing\Verify).
     *
     * @return array{time:string,checked:int,issues:array,out_of_scope:array}
     */
    public static function run(): array {
        if (\Kaupang\Stock\Costing\Costing::enabled()) {
            \Kaupang\Stock\Costing\Sweeper::sweepAll();
        }
        $report = self::verify();
        if (\Kaupang\Stock\Costing\Costing::enabled()) {
            $report['cost'] = \Kaupang\Stock\Costing\Verify::run();
            if (!empty($report['cost']['issues'])) {
                Logger::warning('reconcile_cost_issues', ['count' => count($report['cost']['issues'])]);
            }
        }
        \update_option(self::REPORT_OPTION, $report, false);
        return $report;
    }

    /**
     * @return array{time:string,checked:int,issues:array,out_of_scope:array}
     */
    public static function verify(): array {
        global $wpdb;

        $managedIds = Seeder::stockManagedProductIds();
        $managedSet = array_fill_keys($managedIds, true);
        $balanceRows = Balances::rows();
        $balances   = Balances::aggregateRows();
        $sums       = Movements::sumPerProduct();
        $locationSums = Movements::sumPerProductLocation();

        $stocks = [];
        $lookup = [];
        if (!empty($managedIds)) {
            $in = implode(',', $managedIds);
            foreach ((array) $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_stock' AND post_id IN ($in)",
                ARRAY_A
            ) as $row) {
                $stocks[(int) $row['post_id']] = (float) $row['meta_value'];
            }
            foreach ((array) $wpdb->get_results(
                "SELECT product_id, stock_quantity FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN ($in)",
                ARRAY_A
            ) as $row) {
                $lookup[(int) $row['product_id']] = $row['stock_quantity'] === null ? null : (float) $row['stock_quantity'];
            }
        }

        $issues = [];
        foreach ($managedIds as $productId) {
            $sum    = $sums[$productId] ?? 0.0;
            $onHand = isset($balances[$productId]) ? (float) $balances[$productId]['on_hand'] : 0.0;
            $stock  = $stocks[$productId] ?? 0.0;
            $look   = $lookup[$productId] ?? null;
            $seeded = isset($balances[$productId]);

            $ok = abs($sum - $onHand) < 1e-9
                && abs($onHand - $stock) < 1e-9
                && ($look === null ? abs($stock) < 1e-9 : abs($stock - $look) < 1e-9);

            if ($ok && ($seeded || abs($stock) < 1e-9)) {
                continue;
            }
            $issues[] = [
                'type'       => 'aggregate_drift',
                'product_id' => $productId,
                'name'       => \get_the_title($productId),
                'sum'        => $sum,
                'on_hand'    => $onHand,
                'stock'      => $stock,
                'lookup'     => $look,
                'seeded'     => $seeded,
                'has_tail'   => self::hasOwnedTail($productId),
            ];
        }

        // A compensating drift in two locations can leave the aggregate green;
        // verify each immutable-ledger sum against its own balance row as well.
        foreach ($managedIds as $productId) {
            $locations = array_unique(array_merge(
                array_keys($balanceRows[$productId] ?? []),
                array_keys($locationSums[$productId] ?? [])
            ));
            foreach ($locations as $locationId) {
                $sum = (float) ($locationSums[$productId][$locationId] ?? 0.0);
                $row = $balanceRows[$productId][$locationId] ?? null;
                $onHand = $row !== null ? (float) $row['on_hand'] : 0.0;
                if (abs($sum - $onHand) < 1e-9) {
                    continue;
                }
                $issues[] = [
                    'type'        => 'location_drift',
                    'product_id'  => $productId,
                    'name'        => \get_the_title($productId),
                    'location_id' => (int) $locationId,
                    'location'    => \Kaupang\Stock\Locations::name((int) $locationId),
                    'sum'         => $sum,
                    'on_hand'     => $onHand,
                    'stock'       => $stocks[$productId] ?? 0.0,
                    'lookup'      => $lookup[$productId] ?? null,
                    'seeded'      => $row !== null,
                    'has_tail'    => self::hasOwnedTail($productId, (int) $locationId),
                ];
            }
        }

        // Balance rows whose product left scope (manage_stock off / deleted):
        // kept for audit, excluded from the invariant, surfaced separately.
        $outOfScope = [];
        foreach ($balances as $productId => $row) {
            if (isset($managedSet[$productId])) {
                continue;
            }
            $post          = \get_post($productId);
            $outOfScope[] = [
                'product_id' => $productId,
                'name'       => $post ? $post->post_title : ('#' . $productId),
                'on_hand'    => (float) $row['on_hand'],
                'state'      => $post ? 'not_managing' : 'deleted',
            ];
        }

        return [
            'time'         => gmdate('Y-m-d H:i:s'),
            'checked'      => count($managedIds),
            'issues'       => $issues,
            'out_of_scope' => $outOfScope,
        ];
    }

    /**
     * Direction-aware one-click heal for one product. Returns a short outcome
     * string for notices/CLI.
     */
    public static function healProduct(int $productId): string {
        // 1) Write side: replay any unwritten owned tail into Woo (or just
        //    advance a crash-stale watermark). Never compensates.
        $writeOutcome = WriteThrough::healProduct($productId);
        if ($writeOutcome === 'failed') {
            return 'failed';
        }

        // 2) Read side: any remaining divergence means Woo moved without us —
        //    compensating `external` movement (or `initial` on first sight).
        $movement = Ledger::absorbResidual($productId, 0, null, 'reconciler heal');

        // 3) Projection cache: lookup table follows _stock; re-mirror if stale.
        self::syncLookupRow($productId);

        if ($movement !== null) {
            return $writeOutcome === 'replayed' ? 'replayed+absorbed' : 'absorbed';
        }
        return $writeOutcome;
    }

    private static function hasOwnedTail(int $productId, ?int $onlyLocation = null): bool {
        global $wpdb;
        $in = "'" . implode("','", array_map('esc_sql', Reasons::owned())) . "'";
        $rows = Balances::rows([$productId])[$productId] ?? [];
        foreach ($rows as $locationId => $row) {
            if ($onlyLocation !== null && $locationId !== $onlyLocation) {
                continue;
            }
            if ((int) $row['last_written_id'] >= (int) $row['last_movement_id']) {
                continue;
            }
            $count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::movements()
                . " WHERE product_id = %d AND location_id = %d AND id > %d AND reason IN ($in)",
                $productId,
                $locationId,
                (int) $row['last_written_id']
            ));
            if ($count > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * wc_product_meta_lookup.stock_quantity is a projection of _stock; when it
     * lags (cache hiccup), re-mirror it the same way core's direct path does.
     */
    private static function syncLookupRow(int $productId): void {
        global $wpdb;
        $stock = Ledger::readStockDirect($productId);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}wc_product_meta_lookup SET stock_quantity = %f WHERE product_id = %d",
            $stock,
            $productId
        ));
    }
}
