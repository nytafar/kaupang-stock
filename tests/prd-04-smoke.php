<?php

/**
 * PRD-04 smoke test (§1 receiving-with-location + §3 per-location status inputs).
 *
 * Run: wp --path=/var/www/staging.zenso.no/htdocs eval-file \
 *          wp-content/plugins/kaupang-stock/tests/prd-04-smoke.php
 *
 * Covers the automatable acceptance criteria for the built PRD-04 slice:
 *  - A receipt targets ONE location: movements, balances, cost layer and _stock
 *    all land on the receiving location; the aggregate rises as before.
 *  - Two partial receipts of the SAME PO to two different locations both post,
 *    and the derived PO status stays correct (partial → received).
 *  - An unknown/inactive location on a receipt falls back to the default (the
 *    single guard in Receiving::receive), never posts to a bogus location.
 *  - Per-location on_hand feeds the same low-stock predicate the §3 columns use
 *    (Woo threshold vs the LOCATION's on_hand, not the aggregate).
 *
 * §2 (counting per location) and §4 (replenishment list) are deliberately NOT
 * built in this slice and are not exercised here. One-location regression cannot
 * be asserted on this two-location staging DB; it is a manual go-live check.
 *
 * Fixtures are durable and reused (KSTEST- product/supplier/location); every
 * quantity is zeroed back through the public ledger in finally{}.
 */

use Kaupang\Stock\Costing\Costing;
use Kaupang\Stock\Costing\Sweeper;
use Kaupang\Stock\Costing\Valuation;
use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Purchasing\Lines;
use Kaupang\Stock\Purchasing\PurchaseOrders;
use Kaupang\Stock\Purchasing\Receiving;
use Kaupang\Stock\Purchasing\Suppliers;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

defined('ABSPATH') || exit(1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('PRD-04 assertion failed: ' . $message);
    }
};

$storedSettings = get_option(Settings::OPTION_KEY, null);
$hadSettings    = is_array($storedSettings);
$originalUserId = get_current_user_id();
$originalDefault = 0;
$productId  = 0;
$storeLoc   = 0;
$createdPos = [];
$hadLowMeta = false;
$originalLowMeta = '';
$run = strtolower(wp_generate_password(8, false, false));

$aggregate = static function (int $id): float {
    $row = Balances::aggregateRows([$id])[$id] ?? null;
    return $row === null ? 0.0 : (float) $row['on_hand'];
};

$wooStock = static function (int $id): float {
    $product = wc_get_product($id);
    return $product instanceof WC_Product ? (float) $product->get_stock_quantity() : 0.0;
};

$locationValue = static function (int $id, int $location): int {
    foreach (Valuation::rowsByLocation() as $row) {
        if ((int) $row['product_id'] === $id && (int) $row['location_id'] === $location) {
            return (int) $row['value_ore'];
        }
    }
    return 0;
};

try {
    Schema::maybeUpgrade();
    $originalDefault = Balances::defaultLocationId();

    // WP-CLI has no logged-in user; exercise the real capability guard as an admin.
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
    $assert($admins !== [], 'an administrator exists for capability checks');
    wp_set_current_user((int) $admins[0]);

    // Durable, reusable stock-managed test product.
    $productId = (int) wc_get_product_id_by_sku('KSTEST-PRD04');
    if ($productId <= 0) {
        $product = new WC_Product_Simple();
        $product->set_name('KSTEST PRD-04 receiving');
        $product->set_sku('KSTEST-PRD04');
        $product->set_status('draft');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(0);
        $productId = (int) $product->save();
    }
    $assert($productId > 0, 'durable test product exists');
    $hadLowMeta = metadata_exists('post', $productId, '_low_stock_amount');
    $originalLowMeta = $hadLowMeta ? (string) get_post_meta($productId, '_low_stock_amount', true) : '';

    // Durable, reusable second active location (the "store").
    $storeLoc = 0;
    foreach (Locations::all() as $location) {
        if (str_starts_with((string) $location['name'], 'KSTEST PRD-04')) {
            $storeLoc = (int) $location['id'];
            break;
        }
    }
    if ($storeLoc <= 0) {
        $storeLoc = Locations::create('KSTEST PRD-04 store ' . $run);
    } else {
        Locations::rename($storeLoc, 'KSTEST PRD-04 store ' . $run);
        if (!Locations::isActive($storeLoc)) {
            Locations::setActive($storeLoc, true);
        }
    }
    $assert($storeLoc > 0 && $storeLoc !== $originalDefault, 'test store location is a distinct active location');
    $assert(Locations::isMulti(), 'a second active location makes the site multi-location');

    Settings::update([
        'stock_enabled'            => true,
        'mode'                     => Settings::MODE_ACTIVE,
        'po_enabled'               => true,
        'costing_enabled'          => true,
        'allow_negative_locations' => true,
    ]);
    Costing::stampAnchorIfMissing();
    Costing::register();

    // Reusable fixture: bring every active projection back to zero through the
    // public ledger before this run starts.
    foreach (Locations::all(true) as $location) {
        $id     = (int) $location['id'];
        $onHand = Balances::onHand($productId, $id);
        if (abs($onHand) > 1e-9) {
            Ledger::adjust($productId, -$onHand, 'KSTEST PRD-04 preflight zero', null, 'kstest-prd04-pre-' . $run . '-' . $id, $id);
        }
    }
    Sweeper::sweepAll();
    $assert(abs($aggregate($productId)) < 1e-9, 'fixture starts at aggregate zero');
    $assert(abs($wooStock($productId)) < 1e-9, 'Woo _stock starts at zero');

    // Durable, reusable supplier.
    $supplierId = 0;
    foreach (Suppliers::all() as $supplier) {
        if (str_starts_with((string) $supplier['name'], 'KSTEST PRD-04')) {
            $supplierId = (int) $supplier['id'];
            break;
        }
    }
    if ($supplierId <= 0) {
        $supplierId = Suppliers::create(['name' => 'KSTEST PRD-04 supplier']);
    }
    $assert($supplierId > 0, 'test supplier exists');

    $unitCost = 5000; // 50.00 kr ex-VAT per unit

    /* --- Test 1: receive 4 to the store location (non-default) ------------- */
    $poA = PurchaseOrders::create(['supplier_id' => $supplierId, 'supplier_ref' => 'KSTEST-A-' . $run]);
    $createdPos[] = $poA;
    [$lineA] = Lines::add($poA, $productId, 10, $unitCost);
    PurchaseOrders::order($poA);
    $assert(PurchaseOrders::statusFor($poA) === PurchaseOrders::STATUS_ORDERED, 'fresh PO is ordered');

    $tokenA = 'kstest-prd04-a-' . $run;
    $resA   = Receiving::receive($poA, $tokenA, [$lineA => 4], null, false, [$lineA => $unitCost], $storeLoc);
    $assert(($resA['status'] ?? '') === 'ok', 'receipt to store location returns ok');
    Sweeper::sweepAll();

    $assert(abs(Balances::onHand($productId, $storeLoc) - 4.0) < 1e-9, 'store on_hand is 4 after receipt');
    $assert(abs(Balances::onHand($productId, $originalDefault)) < 1e-9, 'default on_hand untouched by a store receipt');
    $assert(abs($aggregate($productId) - 4.0) < 1e-9, 'aggregate rises to 4');
    $assert(abs($wooStock($productId) - 4.0) < 1e-9, '_stock rises to 4 as before');

    $rowsA = Movements::forBatch($tokenA);
    $assert(count($rowsA) === 1, 'store receipt posts exactly one movement');
    $moveA = $rowsA[0];
    $assert((string) $moveA['reason'] === Reasons::RECEIPT, 'movement reason is receipt');
    $assert((int) $moveA['location_id'] === $storeLoc, 'receipt movement lands on the store location');
    $assert(abs((float) $moveA['delta'] - 4.0) < 1e-9, 'receipt delta is +4');
    $assert((string) $moveA['ref_type'] === 'po_line' && (int) $moveA['ref_id'] === $lineA, 'receipt refs the PO line');
    $assert($locationValue($productId, $storeLoc) === 4 * $unitCost, 'cost layer value lands on the store location');

    // Idempotency: the same token dedupes to the same single posting.
    $resAgain = Receiving::receive($poA, $tokenA, [$lineA => 4], null, false, [$lineA => $unitCost], $storeLoc);
    $assert(($resAgain['status'] ?? '') === 'ok', 'idempotent resend still returns ok');
    $assert(count(Movements::forBatch($tokenA)) === 1, 'resend of the same token posts no second movement');
    $assert(abs($aggregate($productId) - 4.0) < 1e-9, 'idempotent resend does not double the aggregate');

    /* --- Test 2: second partial receipt of the SAME PO to the default ------ */
    $tokenB = 'kstest-prd04-b-' . $run;
    $resB   = Receiving::receive($poA, $tokenB, [$lineA => 6], null, false, [$lineA => $unitCost], $originalDefault);
    $assert(($resB['status'] ?? '') === 'ok', 'second partial receipt (to default) returns ok');
    Sweeper::sweepAll();

    $assert(abs(Balances::onHand($productId, $storeLoc) - 4.0) < 1e-9, 'store on_hand stays 4');
    $assert(abs(Balances::onHand($productId, $originalDefault) - 6.0) < 1e-9, 'default on_hand is 6');
    $assert(abs($aggregate($productId) - 10.0) < 1e-9, 'aggregate is 10 across two locations');
    $assert(abs($wooStock($productId) - 10.0) < 1e-9, '_stock is 10');
    $assert(($resB['po_status'] ?? '') === PurchaseOrders::STATUS_RECEIVED, 'PO derives to received once both partials complete the order');
    $assert(PurchaseOrders::statusFor($poA) === PurchaseOrders::STATUS_RECEIVED, 'derived PO status is received');
    $assert($locationValue($productId, $originalDefault) === 6 * $unitCost, 'default cost layer value is 6 units');

    /* --- Test 3: unknown location on a receipt falls back to default ------- */
    $poB = PurchaseOrders::create(['supplier_id' => $supplierId, 'supplier_ref' => 'KSTEST-B-' . $run]);
    $createdPos[] = $poB;
    [$lineB] = Lines::add($poB, $productId, 5, $unitCost);
    PurchaseOrders::order($poB);

    $tokenC   = 'kstest-prd04-c-' . $run;
    $bogusLoc = 999999; // not a real location id
    $resC     = Receiving::receive($poB, $tokenC, [$lineB => 2], null, false, [], $bogusLoc);
    $assert(($resC['status'] ?? '') === 'ok', 'receipt with an unknown location still posts');
    Sweeper::sweepAll();

    $rowsC = Movements::forBatch($tokenC);
    $assert(count($rowsC) === 1, 'fallback receipt posts one movement');
    $assert((int) $rowsC[0]['location_id'] === $originalDefault, 'unknown location falls back to the default, never posts to a bogus id');
    $assert(abs(Balances::onHand($productId, $originalDefault) - 8.0) < 1e-9, 'default on_hand rises to 8 via the fallback');
    $assert(abs($aggregate($productId) - 12.0) < 1e-9, 'aggregate is 12');

    /* --- Test 4: §3 per-location low-stock predicate inputs ---------------- */
    // The §3 columns compare Woo's per-product threshold against the LOCATION's
    // on_hand. Assert the wiring at the data level (isLowStock/filterByLowLocation
    // are private): threshold 5 flags the store (4 ≤ 5) but not the default (8 > 5).
    $product = wc_get_product($productId);
    $product->update_meta_data('_low_stock_amount', '5');
    $product->save();
    $threshold = wc_get_low_stock_amount($product);
    $assert(is_numeric($threshold) && (float) $threshold === 5.0, 'per-product low threshold reads back as 5');

    $storeOnHand   = Balances::onHand($productId, $storeLoc);       // 4
    $defaultOnHand = Balances::onHand($productId, $originalDefault); // 8
    $isLow = static fn (float $onHand): bool => $onHand <= (float) $threshold && $onHand >= 0;
    $assert($isLow($storeOnHand), 'store (4) is low against its own on_hand at threshold 5');
    $assert(!$isLow($defaultOnHand), 'default (8) is NOT low — the aggregate (12) would hide this per-location signal');

    WP_CLI::success('PRD-04 receiving-with-location smoke test passed.');
} finally {
    // Best-effort cleanup also runs after a failed assertion. Zero the product
    // through the public ledger so verify stays green and _stock is left clean.
    if ($productId > 0) {
        try {
            Settings::update([
                'stock_enabled'            => true,
                'mode'                     => Settings::MODE_ACTIVE,
                'po_enabled'               => true,
                'costing_enabled'          => true,
                'allow_negative_locations' => true,
            ]);
            if ($storeLoc > 0 && !Locations::isActive($storeLoc)) {
                Locations::setActive($storeLoc, true);
            }
            foreach (Locations::all(true) as $location) {
                $id     = (int) $location['id'];
                $onHand = Balances::onHand($productId, $id);
                if (abs($onHand) > 1e-9) {
                    Ledger::adjust($productId, -$onHand, 'KSTEST PRD-04 final zero', null, 'kstest-prd04-fin-' . $run . '-' . $id, $id);
                }
            }
            Sweeper::sweepAll();

            if ($hadLowMeta) {
                update_post_meta($productId, '_low_stock_amount', $originalLowMeta);
            } else {
                delete_post_meta($productId, '_low_stock_amount');
            }
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-04 product cleanup failed: ' . $e->getMessage());
        }
    }

    // Cancel the run's POs (received stock was already zeroed above; cancel only
    // writes off remainders) so re-runs start clean.
    foreach ($createdPos as $poId) {
        try {
            PurchaseOrders::cancel((int) $poId);
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-04 PO cleanup failed: ' . $e->getMessage());
        }
    }

    // Return the store location to its inert fixture state.
    if ($storeLoc > 0) {
        try {
            if ($storeLoc === Balances::defaultLocationId() && $originalDefault > 0 && Locations::isActive($originalDefault)) {
                Locations::setDefault($originalDefault);
            }
            Locations::rename($storeLoc, 'KSTEST PRD-04 (inactive)');
            if (Locations::isActive($storeLoc)) {
                Locations::setActive($storeLoc, false);
            }
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-04 location cleanup failed: ' . $e->getMessage());
        }
    }

    wp_set_current_user($originalUserId);

    if ($hadSettings) {
        update_option(Settings::OPTION_KEY, $storedSettings, true);
    } else {
        delete_option(Settings::OPTION_KEY);
    }
    Settings::flushCache();
}
