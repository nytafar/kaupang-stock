<?php
declare(strict_types=1);

namespace Kaupang\Stock\Observe;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Settings;

/**
 * The shadow side (§4): WooCommerce executes every sale-path stock write —
 * we never veto, never take over. The verified 10.9.4 event map becomes
 * movements via record-rich-then-absorb-residual:
 *
 *  - three rich hooks (reduce / restore / restock-refunded) record immediately
 *    with per-hook delta sources that are arithmetic identities or document
 *    quantities — never the hooks' non-atomic from/to reads as balances;
 *  - every set_stock/variation_set_stock/updated_product_stock marks the
 *    product dirty; write paths that can announce themselves file claims;
 *  - the shutdown Absorber trues up each dirty product inside a balance-row
 *    lock. Rich-hook paths produce residual 0, so double-recording is
 *    structurally impossible.
 *
 * Every handler is wrapped: the ledger never blocks or breaks a checkout —
 * failures are logged and the scheduled reconciler records the tail later.
 */
final class Observer {

    /** @var array<int,array<int,array<int,array{qty:float,item_id:int}>>> */
    private static array $refundQtyQueues = [];

    public static function register(): void {
        // Rich hooks — fire after the item's _stock write.
        \add_action('woocommerce_reduce_order_item_stock', [self::class, 'onReduceOrderItemStock'], 10, 3);
        \add_action('woocommerce_restore_order_item_stock', [self::class, 'onRestoreOrderItemStock'], 10, 4);
        \add_action('woocommerce_restock_refunded_item', [self::class, 'onRestockRefundedItem'], 10, 5);

        // Claims — write paths that announce themselves. Observe-only: the
        // prevent filter is returned unchanged (§0 principle 3 — mirror, never
        // fight; vetoing would desync core's stock_reduced bookkeeping).
        \add_filter('woocommerce_prevent_adjust_line_item_product_stock', [self::class, 'claimOrderItemEdit'], 10, 3);
        \add_action('woocommerce_product_quick_edit_save', [self::class, 'claimAdminEdit']);
        \add_action('woocommerce_product_bulk_edit_save', [self::class, 'claimAdminEdit']);
        \add_action('woocommerce_admin_process_product_object', [self::class, 'claimAdminEdit']);
        \add_action('woocommerce_admin_process_variation_object', [self::class, 'claimAdminEdit'], 10, 1);
        \add_action('woocommerce_rest_insert_product_object', [self::class, 'claimRest'], 10, 1);
        \add_action('woocommerce_rest_insert_product_variation_object', [self::class, 'claimRest'], 10, 1);
        \add_action('woocommerce_product_import_inserted_product_object', [self::class, 'claimImport'], 10, 1);

        // Dirty marking — the one pair that fires on EVERY core write path
        // (including the $updating=true raw-SQL one), plus the id-only safety
        // net for hypothetical direct data-store callers.
        \add_action('woocommerce_product_set_stock', [self::class, 'markDirtyProduct']);
        \add_action('woocommerce_variation_set_stock', [self::class, 'markDirtyProduct']);
        \add_action('woocommerce_updated_product_stock', [self::class, 'markDirtyId']);

        // Cross-plugin claim seam: an external system about to move WC stock
        // (e.g. spis-fiken mirroring a decrease made in Fiken) announces its
        // attribution first, so the absorber labels the residual with it
        // instead of a bare `external`. Registry: kaupang-dev-context.md §4.
        \add_action('kaupang/stock/claim', [self::class, 'claimExternal'], 10, 2);

        Absorber::register();
    }

    /**
     * `do_action('kaupang/stock/claim', $managedId, $claim)` — file a claim on
     * behalf of an external mover. Claim keys: reason? (validated, default
     * `external`), ref_type?, ref_id?, ref_line?, via?, actor_id?, note is NOT
     * carried (the movement note comes from the absorber caller).
     *
     * @param int|mixed   $managedId stock-managing product id
     * @param array|mixed $claim
     */
    public static function claimExternal($managedId, $claim = []): void {
        try {
            $managedId = (int) $managedId;
            $claim     = is_array($claim) ? $claim : [];
            if ($managedId <= 0) {
                return;
            }
            $reason = isset($claim['reason']) && Reasons::isValid((string) $claim['reason'])
                ? (string) $claim['reason']
                : Reasons::EXTERNAL;
            DirtyRegistry::claim($managedId, [
                'reason'   => $reason,
                'ref_type' => isset($claim['ref_type']) ? \sanitize_key((string) $claim['ref_type']) : null,
                'ref_id'   => isset($claim['ref_id']) ? (int) $claim['ref_id'] : null,
                'ref_line' => isset($claim['ref_line']) ? (int) $claim['ref_line'] : null,
                'via'      => isset($claim['via']) ? mb_substr((string) $claim['via'], 0, 40) : 'seam',
                'actor_id' => isset($claim['actor_id']) ? (int) $claim['actor_id'] : null,
                'location_id' => self::validatedLocation(isset($claim['location_id']) ? (int) $claim['location_id'] : 0, 'claim'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('claim_seam_failed', ['error' => $e->getMessage()]);
        }
    }

    /* ----------------------------- Rich hooks ----------------------------- */

    /**
     * Checkout/payment/admin stock reduction. delta = −(from − to) is an
     * arithmetic identity equal to the woocommerce_order_item_quantity-filtered
     * qty, so e.g. Subscriptions' switch-suppression is honoured for free.
     *
     * @param \WC_Order_Item_Product $item
     * @param array{product:\WC_Product,from:int|float,to:int|float} $change
     * @param \WC_Order $order
     */
    public static function onReduceOrderItemStock($item, $change, $order): void {
        self::safely(static function () use ($item, $change, $order): void {
            $product = $change['product'] ?? null;
            if (!$product instanceof \WC_Product) {
                return;
            }
            $delta = -((float) $change['from'] - (float) $change['to']);
            if (abs($delta) < 1e-9) {
                return;
            }
            $locationId = self::resolveOrderLocation($order, $item, Reasons::SALE, $product->get_stock_managed_by_id());
            Ledger::record(new MovementIntent(
                $product->get_stock_managed_by_id(),
                $delta,
                Reasons::SALE,
                'order',
                $order instanceof \WC_Order ? $order->get_id() : 0,
                $item instanceof \WC_Order_Item ? $item->get_id() : null,
                null, null, '', null, null, null,
                $locationId
            ));
            if ($order instanceof \WC_Order && (int) $order->get_meta('_kaupang_stock_location_id', true) <= 0) {
                $order->update_meta_data('_kaupang_stock_location_id', $locationId);
                $order->save_meta_data();
            }
        }, 'observe_reduce');
    }

    /**
     * Restore on cancelled/pending (failed does NOT restore — verified).
     * delta = +(new − old), exact by construction; `_reduced_stock` item meta
     * is already deleted when this fires, so it is not a valid delta source.
     *
     * @param \WC_Order_Item_Product $item
     * @param int|float $newStock
     * @param int|float $oldStock
     * @param \WC_Order $order
     */
    public static function onRestoreOrderItemStock($item, $newStock, $oldStock, $order): void {
        self::safely(static function () use ($item, $newStock, $oldStock, $order): void {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            if (!$product instanceof \WC_Product) {
                return;
            }
            $delta = (float) $newStock - (float) $oldStock;
            if (abs($delta) < 1e-9) {
                return;
            }
            $managedId = $product->get_stock_managed_by_id();
            $locationId = self::resolveOrderLocation($order, $item, Reasons::SALE_RESTORE, $managedId);
            Ledger::record(new MovementIntent(
                $managedId,
                $delta,
                Reasons::SALE_RESTORE,
                'order',
                $order instanceof \WC_Order ? $order->get_id() : 0,
                $item instanceof \WC_Order_Item ? $item->get_id() : null,
                null, null, '', null, null, null,
                $locationId
            ));
        }, 'observe_restore');
    }

    /**
     * Refund with the restock checkbox. The hook's $old/$new come from two
     * non-atomic reads (display-only); the WC_Order_Refund is saved before the
     * hook fires, so the per-line restocked qty is read from the refund itself —
     * positionally per product, because the restock loop and the refund's line
     * items iterate the order items in the same order.
     *
     * @param int $productId
     * @param int|float $oldStock
     * @param int|float $newStock
     * @param \WC_Order $order
     * @param \WC_Product $product
     */
    public static function onRestockRefundedItem($productId, $oldStock, $newStock, $order, $product): void {
        self::safely(static function () use ($oldStock, $newStock, $order, $product): void {
            if (!$product instanceof \WC_Product || !$order instanceof \WC_Order) {
                return;
            }
            $managedId = $product->get_stock_managed_by_id();
            $fallback  = (float) $newStock - (float) $oldStock;
            $refundId  = 0;
            $orderItemId = 0;
            $delta     = self::refundRestockQty($order, $managedId, $fallback, $refundId, $orderItemId);
            $orderItem = $orderItemId > 0 ? $order->get_item($orderItemId) : null;
            $locationId = self::resolveOrderLocation($order, $orderItem, Reasons::REFUND_RESTOCK, $managedId);

            if ($delta <= 1e-9) {
                // Concurrency artefact — leave it to the absorber, attributed.
                DirtyRegistry::claim($managedId, [
                    'reason'   => Reasons::REFUND_RESTOCK,
                    'ref_type' => 'order',
                    'ref_id'   => $order->get_id(),
                    'ref_line' => $refundId > 0 ? $refundId : null,
                    'location_id' => $locationId,
                ]);
                Logger::warning('refund_restock_no_delta', ['product' => $managedId, 'order' => $order->get_id()]);
                return;
            }
            Ledger::record(new MovementIntent(
                $managedId,
                $delta,
                Reasons::REFUND_RESTOCK,
                'order',
                $order->get_id(),
                $orderItemId > 0 ? $orderItemId : null,
                null, null, '', null, null, null,
                $locationId
            ));
        }, 'observe_refund_restock');
    }

    /* ------------------------------- Claims -------------------------------- */

    /**
     * Admin order line-item edits (qty change AND the item-deletion path).
     * Observe-only: $prevent is returned unchanged.
     *
     * @param mixed $prevent
     * @param \WC_Order_Item_Product $item
     * @param int|float $itemQuantity
     * @return mixed
     */
    public static function claimOrderItemEdit($prevent, $item, $itemQuantity) {
        self::safely(static function () use ($item): void {
            if (!$item instanceof \WC_Order_Item_Product) {
                return;
            }
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                return;
            }
            DirtyRegistry::claim($product->get_stock_managed_by_id(), [
                'reason'   => Reasons::ORDER_EDIT,
                'ref_type' => 'order',
                'ref_id'   => $item->get_order_id(),
                'ref_line' => $item->get_id(),
                'via'      => Ledger::currentVia(),
                'location_id' => self::resolveOrderLocation(\wc_get_order($item->get_order_id()), $item, Reasons::ORDER_EDIT, $product->get_stock_managed_by_id()),
            ]);
        }, 'claim_order_edit');
        return $prevent;
    }

    /** @param \WC_Product $product */
    public static function claimAdminEdit($product): void {
        self::claimProduct($product, Reasons::ADMIN_EDIT, 'claim_admin_edit');
    }

    /** @param \WC_Product $product */
    public static function claimRest($product): void {
        self::claimProduct($product, Reasons::REST, 'claim_rest');
    }

    /** @param \WC_Product $product */
    public static function claimImport($product): void {
        self::claimProduct($product, Reasons::IMPORT, 'claim_import');
    }

    /* ---------------------------- Dirty marking ---------------------------- */

    /** @param \WC_Product $product */
    public static function markDirtyProduct($product): void {
        self::safely(static function () use ($product): void {
            if ($product instanceof \WC_Product) {
                DirtyRegistry::markDirty($product->get_stock_managed_by_id());
            }
        }, 'mark_dirty');
    }

    /** @param int $productIdWithStock already the stock-managing id */
    public static function markDirtyId($productIdWithStock): void {
        self::safely(static function () use ($productIdWithStock): void {
            DirtyRegistry::markDirty((int) $productIdWithStock);
        }, 'mark_dirty_id');
    }

    /* ------------------------------ Internals ------------------------------ */

    private static function claimProduct($product, string $reason, string $context): void {
        self::safely(static function () use ($product, $reason): void {
            if (!$product instanceof \WC_Product) {
                return;
            }
            DirtyRegistry::claim($product->get_stock_managed_by_id(), [
                'reason' => $reason,
                'via'    => Ledger::currentVia(),
            ]);
        }, $context);
    }

    /**
     * Per-line restock qty from the just-saved refund, matched positionally per
     * product (two order lines sharing a product each consume one queue entry).
     */
    private static function refundRestockQty(\WC_Order $order, int $managedId, float $fallback, int &$refundId, int &$orderItemId): float {
        $refunds = $order->get_refunds(); // newest first
        $refund  = !empty($refunds) ? $refunds[0] : null;
        if (!$refund instanceof \WC_Order_Refund) {
            return $fallback;
        }
        $refundId = $refund->get_id();
        if (!isset(self::$refundQtyQueues[$refundId])) {
            $queues = [];
            foreach ($refund->get_items() as $refundItem) {
                if (!$refundItem instanceof \WC_Order_Item_Product) {
                    continue;
                }
                $refProduct = $refundItem->get_product();
                if (!$refProduct instanceof \WC_Product) {
                    continue;
                }
                $qty = abs((float) $refundItem->get_quantity()); // refund lines store negatives
                if ($qty <= 1e-9) {
                    continue;
                }
                $queues[$refProduct->get_stock_managed_by_id()][] = [
                    'qty' => $qty,
                    'item_id' => (int) $refundItem->get_meta('_refunded_item_id', true),
                ];
            }
            self::$refundQtyQueues[$refundId] = $queues;
        }
        if (!empty(self::$refundQtyQueues[$refundId][$managedId])) {
            $entry = array_shift(self::$refundQtyQueues[$refundId][$managedId]);
            $orderItemId = (int) ($entry['item_id'] ?? 0);
            return (float) ($entry['qty'] ?? $fallback);
        }
        return $fallback;
    }

    /** Deterministic order routing: sale location, meta, mapping→filter, default. */
    private static function resolveOrderLocation($order, $item, string $reason, int $productId): int {
        if (!$order instanceof \WC_Order) {
            return \Kaupang\Stock\Ledger\Balances::defaultLocationId();
        }

        if ($reason === Reasons::SALE_RESTORE || $reason === Reasons::REFUND_RESTOCK) {
            $itemId = $item instanceof \WC_Order_Item ? (int) $item->get_id() : null;
            $saleLocation = Movements::saleLocation((int) $order->get_id(), $itemId, $productId);
            if ($saleLocation !== null) {
                return self::validatedLocation($saleLocation, 'sale_restore');
            }
        }

        $meta = (int) $order->get_meta('_kaupang_stock_location_id', true);
        if ($meta > 0) {
            return self::validatedLocation($meta, 'order_meta');
        }

        $map = Settings::get('order_location_map', []);
        $via = method_exists($order, 'get_created_via') ? (string) $order->get_created_via() : '';
        $mapped = is_array($map) && isset($map[$via]) ? (int) $map[$via] : 0;
        $filtered = \apply_filters('kaupang/stock/order_location', $mapped, $order, $item);
        return self::validatedLocation(is_numeric($filtered) ? (int) $filtered : 0, 'order_route');
    }

    private static function validatedLocation(int $locationId, string $context): int {
        if ($locationId <= 0) {
            return \Kaupang\Stock\Ledger\Balances::defaultLocationId();
        }
        if (Locations::isActive($locationId)) {
            return $locationId;
        }
        Logger::warning('unknown_order_location', ['location' => $locationId, 'context' => $context]);
        return \Kaupang\Stock\Ledger\Balances::defaultLocationId();
    }

    /** Observer code never throws into core's flow. */
    private static function safely(callable $fn, string $context): void {
        try {
            $fn();
        } catch (\Throwable $e) {
            Logger::error('observer_error', ['context' => $context, 'error' => $e->getMessage()]);
        }
    }
}
