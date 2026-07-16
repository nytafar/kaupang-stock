<?php

use Kaupang\Stock\Costing\Consumptions;
use Kaupang\Stock\Costing\CostInputs;
use Kaupang\Stock\Costing\Costing;
use Kaupang\Stock\Costing\Layers;
use Kaupang\Stock\Costing\ProductCost;
use Kaupang\Stock\Costing\Sweeper;
use Kaupang\Stock\Costing\Valuation;
use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Transfers;

defined('ABSPATH') || exit(1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('PRD-03 assertion failed: ' . $message);
    }
};

$storedSettings = get_option(Settings::OPTION_KEY, null);
$hadSettings = is_array($storedSettings);
$originalUserId = get_current_user_id();
$originalDefault = 0;
$productId = 0;
$locationId = 0;
$capabilityFilter = null;
$run = strtolower(wp_generate_password(8, false, false));

$aggregate = static function (int $id): float {
    $row = Balances::aggregateRows([$id])[$id] ?? null;
    return $row === null ? 0.0 : (float) $row['on_hand'];
};

$productValue = static function (int $id): int {
    return (int) (Valuation::rows()[$id]['value_ore'] ?? 0);
};

$locationValue = static function (int $id, int $location): int {
    foreach (Valuation::rowsByLocation() as $row) {
        if ((int) $row['product_id'] === $id && (int) $row['location_id'] === $location) {
            return (int) $row['value_ore'];
        }
    }
    return 0;
};

$expectRefusal = static function (callable $operation, string $message) use ($assert): void {
    $refused = false;
    try {
        $operation();
    } catch (LedgerException $e) {
        $refused = true;
    }
    $assert($refused, $message);
};

try {
    Schema::maybeUpgrade();
    $originalDefault = Balances::defaultLocationId();

    // WP-CLI normally has no logged-in user. Exercise the real capability
    // guard as an administrator, then override its filter for the denial case.
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
    $assert($admins !== [], 'an administrator exists for capability checks');
    wp_set_current_user((int) $admins[0]);

    $productId = (int) wc_get_product_id_by_sku('KSTEST-PRD03');
    if ($productId <= 0) {
        $product = new WC_Product_Simple();
        $product->set_name('KSTEST PRD-03 transfers');
        $product->set_sku('KSTEST-PRD03');
        $product->set_status('draft');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(0);
        $productId = (int) $product->save();
    }
    $assert($productId > 0, 'durable test product exists');

    $ownedLocation = null;
    foreach (Locations::all() as $location) {
        if (str_starts_with((string) $location['name'], 'KSTEST PRD-03')) {
            $ownedLocation = $location;
            break;
        }
    }
    if ($ownedLocation === null) {
        $locationId = Locations::create('KSTEST PRD-03 ' . $run);
    } else {
        $locationId = (int) $ownedLocation['id'];
        Locations::rename($locationId, 'KSTEST PRD-03 ' . $run);
        if (!Locations::isActive($locationId)) {
            Locations::setActive($locationId, true);
        }
    }
    $assert($locationId !== $originalDefault && $locationId > $originalDefault, 'test location has a higher id than the default');
    $assert(Locations::isMulti(), 'second active location enables transfer UI and routing');

    Settings::update([
        'stock_enabled' => true,
        'mode' => Settings::MODE_ACTIVE,
        'costing_enabled' => true,
        'allow_negative_locations' => true,
    ]);
    Costing::stampAnchorIfMissing();
    Costing::register();

    // Reusable fixtures keep immutable history. Bring every active projection
    // back to zero through the public ledger before this run starts.
    foreach (Locations::all(true) as $location) {
        $id = (int) $location['id'];
        $onHand = Balances::onHand($productId, $id);
        if (abs($onHand) > 1e-9) {
            Ledger::adjust($productId, -$onHand, 'KSTEST PRD-03 preflight zero', null, 'kstest-prd03-preflight-' . $run . '-' . $id, $id);
        }
    }
    Sweeper::sweepAll();
    $assert(abs($aggregate($productId)) < 1e-9, 'fixture starts at aggregate zero');
    $assert(abs((float) wc_get_product($productId)->get_stock_quantity()) < 1e-9, 'Woo stock starts at zero');

    // Establish an exact source layer at 12.34 kr/unit without bypassing either
    // the stock ledger or costing layer.
    $seedKey = 'adjust:kstest-prd03-seed-' . $run;
    CostInputs::stash($seedKey, 1234);
    $seed = Ledger::adjust($productId, 8, 'KSTEST PRD-03 exact source', null, $seedKey, $originalDefault);
    Sweeper::sweepAll();
    $initialStock = (float) wc_get_product($productId)->get_stock_quantity();
    $initialAggregate = $aggregate($productId);
    $initialValue = $productValue($productId);
    $assert(abs($initialStock - 8.0) < 1e-9 && abs($initialAggregate - 8.0) < 1e-9, 'exact source quantity is eight');
    $assert($initialValue === 8 * 1234, 'exact source value is held in øre');

    // Source id < destination id: out receives the lower movement id and the
    // transfer_in layer can fold immediately.
    $forwardToken = 'kstest-prd03-fwd-' . $run;
    $forward = Transfers::transfer($productId, 3, $originalDefault, $locationId, $forwardToken, 'KSTEST forward');
    $assert(count($forward) === 2, 'one transfer returns an immutable pair');
    $forwardAgain = Transfers::transfer($productId, 3, $originalDefault, $locationId, $forwardToken, 'KSTEST retry');
    $assert(array_map(static fn ($m): int => $m->id, $forwardAgain) === array_map(static fn ($m): int => $m->id, $forward), 'same token returns the same movement ids');

    $forwardRows = Movements::forBatch($forwardToken);
    $assert(count($forwardRows) === 2, 'idempotent retry leaves exactly two rows');
    $outRows = array_values(array_filter($forwardRows, static fn (array $row): bool => (string) $row['reason'] === Reasons::TRANSFER_OUT));
    $inRows = array_values(array_filter($forwardRows, static fn (array $row): bool => (string) $row['reason'] === Reasons::TRANSFER_IN));
    $assert(count($outRows) === 1 && count($inRows) === 1, 'batch contains one out and one in');
    $out = $outRows[0];
    $in = $inRows[0];
    $assert((int) $out['location_id'] === $originalDefault && (float) $out['delta'] === -3.0, 'forward out is source −3');
    $assert((int) $in['location_id'] === $locationId && (float) $in['delta'] === 3.0, 'forward in is destination +3');
    $assert((string) $out['ref_type'] === 'transfer' && (int) $out['ref_id'] === 0 && (string) $out['batch'] === $forwardToken, 'out carries transfer identity');
    $assert((string) $in['ref_type'] === 'transfer' && (int) $in['ref_id'] === 0 && (string) $in['batch'] === $forwardToken, 'in carries transfer identity');
    $assert((int) $out['id'] < (int) $in['id'], 'source-low ordering records out before in');
    $assert(abs(Balances::onHand($productId, $originalDefault) - 5.0) < 1e-9, 'source balance decreases');
    $assert(abs(Balances::onHand($productId, $locationId) - 3.0) < 1e-9, 'destination balance increases');
    $assert(abs($aggregate($productId) - $initialAggregate) < 1e-9, 'SUM(on_hand) is unchanged');
    $assert(abs((float) wc_get_product($productId)->get_stock_quantity() - $initialStock) < 1e-9, '_stock is unchanged after the batch');
    $assert($productValue($productId) === $initialValue, 'total value is unchanged after forward transfer');
    $assert($locationValue($productId, $originalDefault) === 5 * 1234 && $locationValue($productId, $locationId) === 3 * 1234, 'valuation is correct per location');

    $forwardLayer = Layers::bySourceMovement((int) $in['id']);
    $assert($forwardLayer !== null && (string) $forwardLayer['origin'] === 'transfer' && (int) $forwardLayer['unit_cost_ore'] === 1234, 'forward transfer creates an exact transfer cost layer');
    $forwardConsumptions = Consumptions::forProduct($productId, $originalDefault, 500);
    $transferDraws = array_filter($forwardConsumptions, static fn (array $row): bool => (int) $row['movement_id'] === (int) $out['id'] && (string) $row['kind'] === Consumptions::KIND_TRANSFER_OUT);
    $assert(count($transferDraws) > 0, 'transfer_out consumes FIFO with transfer_out kind');

    // Destination id < source id: transfer_in is recorded first. It must stop
    // before that movement, while the later source fold creates the basis.
    $reverseToken = 'kstest-prd03-rev-' . $run;
    $reverse = Transfers::transfer($productId, 3, $locationId, $originalDefault, $reverseToken, 'KSTEST reverse');
    $reverseRows = Movements::forBatch($reverseToken);
    $reverseOut = current(array_filter($reverseRows, static fn (array $row): bool => (string) $row['reason'] === Reasons::TRANSFER_OUT));
    $reverseIn = current(array_filter($reverseRows, static fn (array $row): bool => (string) $row['reason'] === Reasons::TRANSFER_IN));
    $assert(is_array($reverseOut) && is_array($reverseIn) && (int) $reverseIn['id'] < (int) $reverseOut['id'], 'source-high ordering records in before out');
    $defaultCostBeforeSweep = ProductCost::row($productId, $originalDefault);
    $assert($defaultCostBeforeSweep !== null && (int) $defaultCostBeforeSweep['last_movement_id'] < (int) $reverseIn['id'], 'deferred destination watermark does not pass transfer_in');
    $assert(Layers::bySourceMovement((int) $reverseIn['id']) === null, 'deferred transfer_in has no premature layer');
    $sweep = Sweeper::sweepAll();
    $reverseLayer = Layers::bySourceMovement((int) $reverseIn['id']);
    $assert($sweep['movements'] > 0 && $reverseLayer !== null, 'Sweeper folds deferred transfer_in after source basis exists');
    $assert((int) $reverseLayer['unit_cost_ore'] === 1234 && (int) $reverseLayer['is_estimate'] === 0, 'opposite transfer preserves exact cost');
    $defaultCostAfterSweep = ProductCost::row($productId, $originalDefault);
    $assert($defaultCostAfterSweep !== null && (int) $defaultCostAfterSweep['last_movement_id'] >= (int) $reverseIn['id'], 'Sweeper advances destination watermark');
    $assert(abs(Balances::onHand($productId, $originalDefault) - 8.0) < 1e-9 && abs(Balances::onHand($productId, $locationId)) < 1e-9, 'opposite transfer restores location quantities exactly');
    $assert($productValue($productId) === $initialValue && $locationValue($productId, $originalDefault) === $initialValue && $locationValue($productId, $locationId) === 0, 'opposite transfer restores location and total value exactly');
    $assert(abs($aggregate($productId) - $initialAggregate) < 1e-9 && abs((float) wc_get_product($productId)->get_stock_quantity() - $initialStock) < 1e-9, 'opposite transfer leaves stock invariants exact');

    // Estimate lineage also moves: create an estimated inbound after consuming
    // the exact fixture, transfer it both ways, then zero it through Ledger.
    Ledger::adjust($productId, -8, 'KSTEST consume exact fixture', null, 'kstest-prd03-exact-zero-' . $run, $originalDefault);
    $estimateSeed = Ledger::adjust($productId, 2, 'KSTEST estimated source', null, 'adjust:kstest-prd03-est-' . $run, $originalDefault);
    Sweeper::sweepAll();
    $estimateSourceLayer = Layers::bySourceMovement($estimateSeed->id);
    $assert($estimateSourceLayer !== null && (int) $estimateSourceLayer['is_estimate'] === 1, 'lineage-less inbound is estimated');
    $estimateForwardToken = 'kstest-prd03-estf-' . $run;
    $estimateForwardRows = Transfers::transfer($productId, 2, $originalDefault, $locationId, $estimateForwardToken, null);
    $estimateForwardIn = current(array_filter($estimateForwardRows, static fn ($movement): bool => $movement->reason === Reasons::TRANSFER_IN));
    $estimateDestinationLayer = Layers::bySourceMovement($estimateForwardIn->id);
    $assert($estimateDestinationLayer !== null && (int) $estimateDestinationLayer['is_estimate'] === 1, 'transfer_in inherits source estimate state');
    $estimateReverseToken = 'kstest-prd03-estr-' . $run;
    $estimateReverseRows = Transfers::transfer($productId, 2, $locationId, $originalDefault, $estimateReverseToken, null);
    $estimateReverseIn = current(array_filter($estimateReverseRows, static fn ($movement): bool => $movement->reason === Reasons::TRANSFER_IN));
    Sweeper::sweepAll();
    $estimateReturnLayer = Layers::bySourceMovement($estimateReverseIn->id);
    $assert($estimateReturnLayer !== null && (int) $estimateReturnLayer['is_estimate'] === 1, 'deferred opposite transfer preserves estimate state');
    $assert(abs(Balances::onHand($productId, $originalDefault) - 2.0) < 1e-9 && abs(Balances::onHand($productId, $locationId)) < 1e-9, 'estimated opposite transfer restores quantities exactly');
    Ledger::adjust($productId, -2, 'KSTEST estimated fixture cleanup', null, 'kstest-prd03-est-zero-' . $run, $originalDefault);
    Sweeper::sweepAll();

    // Validation seams: shadow, capability denial, same-location, and an
    // inactive location all reject before a batch can be written.
    Settings::update(['mode' => Settings::MODE_SHADOW]);
    $expectRefusal(static fn () => Transfers::transfer($productId, 1, $originalDefault, $locationId, 'kstest-shadow-' . $run), 'shadow mode refuses transfer');
    Settings::update(['mode' => Settings::MODE_ACTIVE]);

    $capabilityFilter = static fn (): string => 'kstest_capability_no_user_has';
    add_filter('kaupang/stock/can_manage', $capabilityFilter);
    $expectRefusal(static fn () => Transfers::transfer($productId, 1, $originalDefault, $locationId, 'kstest-capdeny-' . $run), 'capability seam refuses transfer');
    remove_filter('kaupang/stock/can_manage', $capabilityFilter);
    $capabilityFilter = null;

    $expectRefusal(static fn () => Transfers::transfer($productId, 1, $originalDefault, $originalDefault, 'kstest-same-' . $run), 'same source and destination are refused');
    Locations::rename($locationId, 'KSTEST PRD-03 (inactive)');
    Locations::setActive($locationId, false);
    $expectRefusal(static fn () => Transfers::transfer($productId, 1, $originalDefault, $locationId, 'kstest-inactive-' . $run), 'inactive destination is refused');

    $assert(abs($aggregate($productId)) < 1e-9, 'fixture finishes at aggregate zero');
    $assert(abs((float) wc_get_product($productId)->get_stock_quantity()) < 1e-9, 'fixture finishes with Woo stock zero');
    $assert($productValue($productId) === 0, 'fixture finishes with zero stock value');

    WP_CLI::success('PRD-03 transfer and costing smoke test passed.');
} finally {
    if (is_callable($capabilityFilter)) {
        remove_filter('kaupang/stock/can_manage', $capabilityFilter);
    }

    // Best-effort zeroing also runs after a failed assertion. Keep the admin
    // identity and active mode until all quantity cleanup has gone through the
    // public ledger path; then return the location to its inert fixture state.
    if ($productId > 0 && $locationId > 0) {
        try {
            Settings::update([
                'stock_enabled' => true,
                'mode' => Settings::MODE_ACTIVE,
                'costing_enabled' => true,
                'allow_negative_locations' => true,
            ]);
            if (!Locations::isActive($locationId)) {
                Locations::setActive($locationId, true);
            }
            foreach (array_unique([$originalDefault, $locationId]) as $id) {
                if ($id <= 0) {
                    continue;
                }
                $onHand = Balances::onHand($productId, $id);
                if (abs($onHand) > 1e-9) {
                    Ledger::adjust($productId, -$onHand, 'KSTEST PRD-03 final zero', null, 'kstest-prd03-final-' . $run . '-' . $id, $id);
                }
            }
            Sweeper::sweepAll();
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-03 product cleanup failed: ' . $e->getMessage());
        }
    }

    if ($locationId > 0) {
        try {
            if ($locationId === Balances::defaultLocationId() && $originalDefault > 0 && Locations::isActive($originalDefault)) {
                Locations::setDefault($originalDefault);
            }
            Locations::rename($locationId, 'KSTEST PRD-03 (inactive)');
            if (Locations::isActive($locationId)) {
                Locations::setActive($locationId, false);
            }
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-03 location cleanup failed: ' . $e->getMessage());
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
