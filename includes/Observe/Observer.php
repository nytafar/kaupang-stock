<?php
declare(strict_types=1);

namespace Kaupang\Stock\Observe;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Logging\Logger;

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

    /** @var array<int,array<int,float[]>> refund id → managed id → restock qtys */
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

        Absorber::register();
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
            Ledger::record(new MovementIntent(
                $product->get_stock_managed_by_id(),
                $delta,
                Reasons::SALE,
                'order',
                $order instanceof \WC_Order ? $order->get_id() : 0,
                $item instanceof \WC_Order_Item ? $item->get_id() : null
            ));
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
            Ledger::record(new MovementIntent(
                $product->get_stock_managed_by_id(),
                $delta,
                Reasons::SALE_RESTORE,
                'order',
                $order instanceof \WC_Order ? $order->get_id() : 0,
                $item instanceof \WC_Order_Item ? $item->get_id() : null
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
            $delta     = self::refundRestockQty($order, $managedId, $fallback, $refundId);

            if ($delta <= 1e-9) {
                // Concurrency artefact — leave it to the absorber, attributed.
                DirtyRegistry::claim($managedId, [
                    'reason'   => Reasons::REFUND_RESTOCK,
                    'ref_type' => 'order',
                    'ref_id'   => $order->get_id(),
                    'ref_line' => $refundId > 0 ? $refundId : null,
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
                $refundId > 0 ? $refundId : null
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
    private static function refundRestockQty(\WC_Order $order, int $managedId, float $fallback, int &$refundId): float {
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
                $queues[$refProduct->get_stock_managed_by_id()][] = $qty;
            }
            self::$refundQtyQueues[$refundId] = $queues;
        }
        if (!empty(self::$refundQtyQueues[$refundId][$managedId])) {
            return (float) array_shift(self::$refundQtyQueues[$refundId][$managedId]);
        }
        return $fallback;
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
