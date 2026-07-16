<?php

use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Counting\Apply;
use Kaupang\Stock\Counting\CountLines;
use Kaupang\Stock\Counting\Counts;
use Kaupang\Stock\Costing\Costing;
use Kaupang\Stock\Costing\ProductCost;
use Kaupang\Stock\Costing\Sweeper;
use Kaupang\Stock\Observe\DirtyRegistry;
use Kaupang\Stock\Observe\Observer;
use Kaupang\Stock\Observe\Seeder;
use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\Receiving;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Reconcile\Reconciler;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

defined('ABSPATH') || exit(1);

global $wpdb;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('PRD-02 assertion failed: ' . $message);
    }
};

$storedSettings = get_option(Settings::OPTION_KEY, null);
$hadSettings = is_array($storedSettings);
$productId = 0;
$locationId = 0;
$originalDefault = 0;
$orders = [];
$poIds = [];
$supplierIds = [];
$countIds = [];
$driftInjected = false;
$routeFilter = null;
$allowFilter = null;
$run = strtolower(wp_generate_password(8, false, false));

$productAggregate = static function (int $id): float {
    $row = Balances::aggregateRows([$id])[$id] ?? null;
    return $row === null ? 0.0 : (float) $row['on_hand'];
};

$newOrder = static function (int $id, string $createdVia, int $override = 0): WC_Order {
    $order = wc_create_order(['status' => 'pending']);
    if (!$order instanceof WC_Order) {
        throw new RuntimeException('Could not create PRD-02 test order');
    }
    $order->set_created_via($createdVia);
    $product = wc_get_product($id);
    if (!$product instanceof WC_Product) {
        throw new RuntimeException('Could not load PRD-02 test product');
    }
    $order->add_product($product, 1);
    if ($override > 0) {
        $order->update_meta_data('_kaupang_stock_location_id', $override);
    }
    $order->save();
    return $order;
};

try {
    Schema::maybeUpgrade();
    $originalDefault = Balances::defaultLocationId();

    // Reuse the durable smoke product. Movements intentionally remain as an
    // immutable audit trail; every run returns both projections to exactly zero.
    $productId = (int) wc_get_product_id_by_sku('KSTEST-PRD02');
    if ($productId <= 0) {
        $product = new WC_Product_Simple();
        $product->set_name('KSTEST PRD-02 multi-location');
        $product->set_sku('KSTEST-PRD02');
        $product->set_status('draft');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(0);
        $productId = (int) $product->save();
    }
    $assert($productId > 0, 'test product exists');

    $ownedLocation = null;
    foreach (Locations::all() as $location) {
        if (str_starts_with((string) $location['name'], 'KSTEST PRD-02')) {
            $ownedLocation = $location;
            break;
        }
    }
    if ($ownedLocation !== null && !empty($ownedLocation['active']) && Locations::activeCount() > 1) {
        Locations::setActive((int) $ownedLocation['id'], false);
        $ownedLocation['active'] = 0;
    }
    if (Locations::activeCount() === 1) {
        $assert(!Locations::isMulti(), 'one-location precondition keeps multi-location UI inert');

        Settings::update([
            'stock_enabled' => true,
            'mode' => Settings::MODE_ACTIVE,
            'po_enabled' => true,
            'counting_enabled' => true,
            'costing_enabled' => false,
            'allow_negative_locations' => true,
            'order_location_map' => [],
        ]);
        Seeder::sweep();

        // Existing one-location observer behavior remains deterministic: an
        // unmapped order lands on default, freezes there, and restores there.
        $singleOrder = $newOrder($productId, 'kstest-single');
        $orders[] = $singleOrder;
        $singleItem = current($singleOrder->get_items('line_item'));
        Observer::onReduceOrderItemStock($singleItem, ['product' => wc_get_product($productId), 'from' => 1, 'to' => 0], $singleOrder);
        $assert(Movements::saleLocation((int) $singleOrder->get_id(), (int) $singleItem->get_id(), $productId) === $originalDefault, 'one-location observer uses default');
        Observer::onRestoreOrderItemStock($singleItem, 1, 0, $singleOrder);

        // Receiving still posts through the public ledger path, and its
        // compensating reversal returns the durable fixture to zero.
        $supplierId = Suppliers::create(['name' => 'KSTEST PRD-02 supplier', 'country' => 'NO', 'active' => 1]);
        $supplierIds[] = $supplierId;
        $poId = PurchaseOrders::create(['supplier_id' => $supplierId, 'supplier_ref' => 'KSTEST-PRD02']);
        $poIds[] = $poId;
        [$poLineId] = Lines::add($poId, $productId, 1, null, null);
        PurchaseOrders::order($poId);
        $receiveToken = 'kstest-prd02-' . $run;
        $received = Receiving::receive($poId, $receiveToken, [$poLineId => 1], null, false);
        $assert($received['status'] === 'ok' && $received['movements'] === 1, 'one-location receiving still works');
        $receiptRows = Movements::forBatch($receiveToken);
        $assert(count($receiptRows) === 1 && (string) $receiptRows[0]['reason'] === Reasons::RECEIPT, 'receipt stays on the existing ledger path');
        Ledger::reverse((int) $receiptRows[0]['id'], 'KSTEST PRD-02 receiving cleanup');

        // A zero-variance manual count exercises capture→review→apply without
        // introducing an extra stock movement.
        $countId = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$productId], 'blind' => true, 'note' => 'KSTEST PRD-02']);
        $countIds[] = $countId;
        $countLines = Counts::lines($countId);
        $assert(count($countLines) === 1, 'one-location count snapshots the test product');
        CountLines::setCounted((int) $countLines[0]['id'], (float) $countLines[0]['expected']);
        $assert(Counts::toReview($countId), 'one-location count reaches review');
        $countResult = Apply::apply($countId, false);
        $assert($countResult['status'] === 'ok' && $countResult['movements'] === 0, 'zero-variance one-location count applies cleanly');

        $singleIssues = array_filter(Reconciler::verify()['issues'], static fn (array $issue): bool => (int) ($issue['product_id'] ?? 0) === $productId);
        $assert($singleIssues === [], 'one-location observer, receiving, counting and reconcile remain green');
    } else {
        WP_CLI::log('One-location precondition skipped: this dev database already has multiple active locations.');
    }

    if ($ownedLocation === null) {
        $locationId = Locations::create('KSTEST PRD-02 ' . $run);
    } else {
        $locationId = (int) $ownedLocation['id'];
        Locations::rename($locationId, 'KSTEST PRD-02 ' . $run);
        Locations::setActive($locationId, true);
    }
    $assert(Locations::isMulti(), 'second active location enables multi-location mode');

    Settings::update([
        'stock_enabled' => true,
        'mode' => Settings::MODE_ACTIVE,
        'costing_enabled' => true,
        'allow_negative_locations' => true,
        'order_location_map' => ['kstest-map' => $locationId],
    ]);
    Costing::stampAnchorIfMissing();
    Seeder::sweep();
    $assert(abs($productAggregate($productId)) < 1e-9, 'test product starts at aggregate zero');

    // Owned routing + idempotence: the same intent returns the same movement and
    // changes Woo/aggregate only once, then its immutable reversal zeros exactly.
    $idem = 'kstest:prd02:idem:' . $run;
    $first = Ledger::adjust($productId, 1, 'KSTEST PRD-02 idempotence', null, $idem, $locationId);
    $again = Ledger::adjust($productId, 1, 'KSTEST PRD-02 idempotence', null, $idem, $locationId);
    $assert($first->id === $again->id, 'owned write is idempotent');
    $assert(abs(Balances::onHand($productId, $locationId) - 1.0) < 1e-9, 'owned write targets the explicit location');
    $assert(abs((float) wc_get_product($productId)->get_stock_quantity() - 1.0) < 1e-9, '_stock follows the aggregate delta once');
    Ledger::reverse($first->id, 'KSTEST PRD-02 idempotence cleanup');
    $assert(abs($productAggregate($productId)) < 1e-9, 'owned reversal restores aggregate zero');

    // Mapping feeds the filter, the selected location freezes on the order, and
    // restore reuses the original sale location even after order meta changes.
    $mappedSeen = 0;
    $routeFilter = static function ($mapped) use (&$mappedSeen, $locationId) {
        $mappedSeen = (int) $mapped;
        return $locationId;
    };
    add_filter('kaupang/stock/order_location', $routeFilter, 10, 3);
    $order = $newOrder($productId, 'kstest-map');
    $orders[] = $order;
    $item = current($order->get_items('line_item'));
    $assert($item instanceof WC_Order_Item_Product, 'routed order has a line item');
    Observer::onReduceOrderItemStock($item, ['product' => wc_get_product($productId), 'from' => 1, 'to' => 0], $order);
    $assert($mappedSeen === $locationId, 'created_via mapping is the filter input');
    $assert((int) $order->get_meta('_kaupang_stock_location_id', true) === $locationId, 'first sale freezes location meta');
    $assert(Movements::saleLocation((int) $order->get_id(), (int) $item->get_id(), $productId) === $locationId, 'sale is recorded at mapped location');
    $saleRows = array_values(array_filter(
        Movements::forRef('order', (int) $order->get_id()),
        static fn (array $row): bool => (string) $row['reason'] === Reasons::SALE && (int) $row['ref_line'] === (int) $item->get_id()
    ));
    $assert(count($saleRows) === 1, 'routed sale has one attributed movement');
    Sweeper::sweepAll();
    $locationCost = ProductCost::row($productId, $locationId);
    $assert($locationCost !== null && (int) $locationCost['last_movement_id'] >= (int) $saleRows[0]['id'], 'costing folds the routed sale at location two');
    $order->update_meta_data('_kaupang_stock_location_id', $originalDefault);
    $order->save_meta_data();
    Observer::onRestoreOrderItemStock($item, 1, 0, $order);
    $assert(abs(Balances::onHand($productId, $locationId)) < 1e-9, 'sale restore reuses sale location rather than changed meta');

    // Explicit order meta wins over a filter that asks for location two.
    $overrideOrder = $newOrder($productId, 'kstest-map', $originalDefault);
    $orders[] = $overrideOrder;
    $overrideItem = current($overrideOrder->get_items('line_item'));
    Observer::onReduceOrderItemStock($overrideItem, ['product' => wc_get_product($productId), 'from' => 1, 'to' => 0], $overrideOrder);
    $assert(Movements::saleLocation((int) $overrideOrder->get_id(), (int) $overrideItem->get_id(), $productId) === $originalDefault, 'order meta override wins over filter');
    Observer::onRestoreOrderItemStock($overrideItem, 1, 0, $overrideOrder);
    remove_filter('kaupang/stock/order_location', $routeFilter, 10);

    // A location-less claim lands its residual on the default location.
    Observer::claimExternal($productId, ['reason' => Reasons::EXTERNAL, 'via' => 'kstest']);
    $claim = DirtyRegistry::consumeClaim($productId);
    wc_update_product_stock($productId, 1, 'increase');
    $absorbed = Ledger::absorbResidual($productId, 0, $claim, 'KSTEST PRD-02 claim');
    $assert($absorbed !== null && $absorbed->locationId === $originalDefault, 'location-less claim residual uses default');
    $claimCleanup = Ledger::adjust($productId, -1, 'KSTEST PRD-02 claim cleanup', null, 'kstest:prd02:claim:' . $run, $originalDefault);
    $assert($claimCleanup->locationId === $originalDefault, 'claim cleanup is routed explicitly');

    // Setting false blocks negative owned stock; observed stock still records.
    Settings::update(['allow_negative_locations' => false]);
    $refused = false;
    try {
        Ledger::adjust($productId, -1, 'KSTEST must refuse', null, 'kstest:prd02:refuse:' . $run, $locationId);
    } catch (LedgerException $e) {
        $refused = true;
    }
    $assert($refused, 'allow-negative off refuses an owned negative location');
    $sale = Ledger::record(new MovementIntent($productId, -1, Reasons::SALE, 'kstest', null, null, null, null, 'kstest', null, null, null, $locationId));
    $assert($sale->balanceAfter < 0, 'observed sale is recorded despite negative setting');
    Ledger::record(new MovementIntent($productId, 1, Reasons::SALE_RESTORE, 'kstest', null, null, null, null, 'kstest', null, null, null, $locationId));

    // Filter wins over the setting for owned operations.
    $allowFilter = static fn (): bool => true;
    add_filter('kaupang/stock/allow_negative', $allowFilter, 10, 3);
    $negative = Ledger::adjust($productId, -1, 'KSTEST filter override', null, 'kstest:prd02:allow:' . $run, $locationId);
    remove_filter('kaupang/stock/allow_negative', $allowFilter, 10);
    Ledger::reverse($negative->id, 'KSTEST filter override cleanup');

    // Fault injection deliberately corrupts only the balances projection in
    // opposite directions. Aggregate stays green while per-location verify fires.
    $wpdb->query($wpdb->prepare('UPDATE ' . Schema::balances() . ' SET on_hand = on_hand + 1 WHERE product_id = %d AND location_id = %d', $productId, $originalDefault));
    $wpdb->query($wpdb->prepare('UPDATE ' . Schema::balances() . ' SET on_hand = on_hand - 1 WHERE product_id = %d AND location_id = %d', $productId, $locationId));
    $driftInjected = true;
    $issues = Reconciler::verify()['issues'];
    $locationIssues = array_filter($issues, static fn (array $issue): bool => (int) ($issue['product_id'] ?? 0) === $productId && ($issue['type'] ?? '') === 'location_drift');
    $aggregateIssues = array_filter($issues, static fn (array $issue): bool => (int) ($issue['product_id'] ?? 0) === $productId && ($issue['type'] ?? '') === 'aggregate_drift');
    $assert(count($locationIssues) === 2, 'verify detects both per-location drifts');
    $assert(count($aggregateIssues) === 0, 'compensating per-location drift leaves aggregate invariant green');
    $wpdb->query($wpdb->prepare('UPDATE ' . Schema::balances() . ' SET on_hand = on_hand - 1 WHERE product_id = %d AND location_id = %d', $productId, $originalDefault));
    $wpdb->query($wpdb->prepare('UPDATE ' . Schema::balances() . ' SET on_hand = on_hand + 1 WHERE product_id = %d AND location_id = %d', $productId, $locationId));
    $driftInjected = false;

    $finalIssues = array_filter(Reconciler::verify()['issues'], static fn (array $issue): bool => (int) ($issue['product_id'] ?? 0) === $productId);
    $assert($finalIssues === [], 'test product finishes fully reconciled');
    $assert(abs($productAggregate($productId)) < 1e-9, 'aggregate invariant finishes at zero');

    WP_CLI::success('PRD-02 multi-location core smoke test passed.');
} finally {
    if ($driftInjected && $productId > 0 && $locationId > 0 && $originalDefault > 0) {
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::balances() . ' SET on_hand = on_hand - 1 WHERE product_id = %d AND location_id = %d', $productId, $originalDefault));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::balances() . ' SET on_hand = on_hand + 1 WHERE product_id = %d AND location_id = %d', $productId, $locationId));
    }
    if (is_callable($routeFilter)) {
        remove_filter('kaupang/stock/order_location', $routeFilter, 10);
    }
    if (is_callable($allowFilter)) {
        remove_filter('kaupang/stock/allow_negative', $allowFilter, 10);
    }

    // Best-effort projection zeroing through public ledger APIs only. Historical
    // KSTEST movements remain immutable by design; the product remains reusable.
    if ($productId > 0 && $locationId > 0) {
        Settings::update(['stock_enabled' => true, 'mode' => Settings::MODE_ACTIVE, 'allow_negative_locations' => true]);
        try {
            Ledger::absorbResidual($productId, 0, null, 'KSTEST PRD-02 final residual');
            $stock = (float) wc_get_product($productId)->get_stock_quantity();
            if (abs($stock) > 1e-9) {
                Ledger::adjust($productId, -$stock, 'KSTEST PRD-02 final zero', null, 'kstest:prd02:final:' . $run, $originalDefault);
            }
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-02 best-effort product cleanup failed: ' . $e->getMessage());
        }
    }
    foreach ($orders as $order) {
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    foreach ($countIds as $countId) {
        $wpdb->delete(Schema::countLines(), ['count_id' => (int) $countId], ['%d']);
        $wpdb->delete(Schema::counts(), ['id' => (int) $countId], ['%d']);
    }
    foreach ($poIds as $poId) {
        $wpdb->delete(Schema::purchaseOrderLines(), ['po_id' => (int) $poId], ['%d']);
        $wpdb->delete(Schema::purchaseOrders(), ['id' => (int) $poId], ['%d']);
    }
    foreach ($supplierIds as $supplierId) {
        $wpdb->delete(Schema::suppliers(), ['id' => (int) $supplierId], ['%d']);
    }
    if ($locationId > 0) {
        try {
            if ($locationId === Balances::defaultLocationId() && $originalDefault > 0 && Locations::isActive($originalDefault)) {
                Locations::setDefault($originalDefault);
            }
            Locations::rename($locationId, 'KSTEST PRD-02 (inactive)');
            if (Locations::isActive($locationId)) {
                Locations::setActive($locationId, false);
            }
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-02 location cleanup failed: ' . $e->getMessage());
        }
    }
    if ($hadSettings) {
        update_option(Settings::OPTION_KEY, $storedSettings, true);
    } else {
        delete_option(Settings::OPTION_KEY);
    }
    Settings::flushCache();
}
