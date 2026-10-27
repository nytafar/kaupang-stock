<?php

/**
 * PRD-04 §2 smoke test: stock counting per location.
 *
 * Run (as the site user, never root; exits non-zero on the first failed assertion):
 *   sudo -u myrvann /usr/bin/php8.5 /usr/local/bin/wp --path=/var/www/staging.myrvann.no/htdocs \
 *     eval-file /var/www/staging.myrvann.no/htdocs/wp-content/plugins/kaupang-stock/tests/prd-04-count-smoke.php
 *
 * Covers the §2 acceptance criteria that code can prove:
 *  - creation writes one line per product × active location, `expected` = that
 *    location's on_hand (0 where the product has never been);
 *  - apply posts each delta to its own location in ONE batch, and a web sale on
 *    the default location mid-count does not change the variance;
 *  - a second apply posts nothing (status gate and per-line idempotency keys);
 *  - only the store counted → only the store moves; every uncounted location is
 *    ONE "not counted" issue row;
 *  - counted lines on a location deactivated since creation refuse the apply;
 *  - CSV export/import round trip per location; a row without a location in a
 *    multi-location count is rejected;
 *  - the v7 migration backfills v6 lines to the default location and drops the
 *    old count_product key (on a scratch copy), idempotently; a failed ALTER
 *    reports the table not ready, and install() then leaves the version
 *    unstamped so the next load retries;
 *  - a count whose line insert fails throws and leaves no partial count;
 *  - a one-location count keeps the v1 shape: no location UI, the v1 CSV header,
 *    a location-less import, per-line uncounted issues.
 *
 * Writes on purpose (staging only): a durable KSTEST product pair and a KSTEST
 * location (reactivated for the run, deactivated again in finally{}). Every
 * quantity is zeroed back through the public ledger; the run's count documents
 * are deleted like prd-02's (their ledger movements stay — the ledger is
 * append-only).
 */

PHP_SAPI === 'cli' || exit;
defined('ABSPATH') || exit(1);

use Kaupang\Stock\Admin\CountSheet;
use Kaupang\Stock\Admin\CountsPage;
use Kaupang\Stock\Counting\Apply;
use Kaupang\Stock\Counting\CountLines;
use Kaupang\Stock\Counting\Counts;
use Kaupang\Stock\Counting\Import;
use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

$host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($host, 'staging.') !== 0 && strpos($host, 'dev.') !== 0) {
    WP_CLI::error('prd-04-count-smoke writes stock movements; run it on a staging site only (this is ' . $host . ').');
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? $host;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('PRD-04 §2 assertion failed: ' . $message);
    }
    WP_CLI::log('  ok   ' . $message);
};
$near = static fn (float $a, float $b): bool => abs($a - $b) < 1e-9;

$wpdb            = $GLOBALS['wpdb'];
$storedSettings  = get_option(Settings::OPTION_KEY, null);
$hadSettings     = is_array($storedSettings);
$originalUserId  = get_current_user_id();
$run             = strtolower(wp_generate_password(8, false, false));
$products        = [];
$store           = 0;
$countIds        = [];
$scratch         = $wpdb->prefix . 'kaupang_stock_count_lines_kstest';
$filters         = []; // query filters this run added; removed in finally{}

/** Add a `query` filter for this run (removed in finally{}). */
$addFilter = static function (callable $cb) use (&$filters): void {
    add_filter('query', $cb);
    $filters[] = $cb;
};
$dropFilters = static function () use (&$filters): void {
    foreach ($filters as $cb) {
        remove_filter('query', $cb);
    }
    $filters = [];
};

/** The scratch count-lines table in the exact v6 shape, with three lines. */
$createV6 = static function () use ($wpdb, $scratch): void {
    $wpdb->query("DROP TABLE IF EXISTS $scratch");
    $wpdb->query("CREATE TABLE $scratch (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        count_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        expected DECIMAL(15,3) NOT NULL,
        counted DECIMAL(15,3) NULL,
        counted_by BIGINT UNSIGNED NULL,
        counted_at DATETIME NULL,
        recount TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY count_product (count_id, product_id)
    ) " . $wpdb->get_charset_collate());
    $wpdb->query("INSERT INTO $scratch (count_id, product_id, expected, counted) VALUES (1, 10, 5, 4), (1, 11, 2, NULL), (2, 10, 0, 1)");
};
$keysOf = static function (string $table) use ($wpdb): array {
    $keys = [];
    foreach ((array) $wpdb->get_results("SHOW INDEX FROM $table") as $r) {
        $keys[$r->Key_name] = ($keys[$r->Key_name] ?? 0) + 1;
    }
    return $keys;
};

/** Movements a count's apply posted, keyed by line id. */
$appliedMoves = static function (int $countId) use ($wpdb): array {
    $ids = array_map(static fn (array $l): int => (int) $l['id'], Counts::lines($countId));
    if ($ids === []) {
        return [];
    }
    $rows = $wpdb->get_results(
        'SELECT * FROM ' . Schema::movements() . " WHERE ref_type = 'count_line' AND ref_id IN (" . implode(',', $ids) . ')',
        ARRAY_A
    );
    $out = [];
    foreach ((array) $rows as $row) {
        $out[(int) $row['ref_id']][] = $row;
    }
    return $out;
};

/** The line of a count for (product, location). */
$lineOf = static function (int $countId, int $productId, int $locationId): array {
    foreach (Counts::lines($countId) as $line) {
        if ((int) $line['product_id'] === $productId && Counts::lineLocation($line) === $locationId) {
            return $line;
        }
    }
    throw new RuntimeException("no line for product $productId at location $locationId in count $countId");
};

$csvHandle = static function (array $header, array $rows) {
    $h = fopen('php://temp', 'r+');
    fwrite($h, "\xEF\xBB\xBF");
    fputcsv($h, $header, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($h, $row, ',', '"', '\\');
    }
    rewind($h);
    return $h;
};

$zeroAll = static function (array $productIds, string $tag) use ($run): void {
    foreach ($productIds as $pid) {
        foreach (Locations::all(true) as $location) {
            $id     = (int) $location['id'];
            $onHand = Balances::onHand($pid, $id);
            if (abs($onHand) > 1e-9) {
                Ledger::adjust($pid, -$onHand, 'KSTEST PRD-04 count ' . $tag, null, 'kstest-prd04c-' . $tag . '-' . $run . '-' . $pid . '-' . $id, $id);
            }
        }
    }
};

try {
    Schema::maybeUpgrade();

    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
    $assert($admins !== [], 'an administrator exists for capability checks');
    wp_set_current_user((int) $admins[0]);

    Settings::update([
        'stock_enabled'            => true,
        'mode'                     => Settings::MODE_ACTIVE,
        'counting_enabled'         => true,
        'allow_negative_locations' => true,
        'variance_threshold_pct'   => 1000, // no recount flags: this test is about locations
    ]);

    /* --- Migration ------------------------------------------------------- */
    WP_CLI::log('Migration (v6 → v7)');
    $assert((int) get_option(Schema::VERSION_OPTION) === 7, 'schema version is 7');
    $realKeys = array_unique(array_map(static fn ($r) => $r->Key_name, (array) $wpdb->get_results('SHOW INDEX FROM ' . Schema::countLines())));
    $assert(!in_array('count_product', $realKeys, true) && in_array('count_product_location', $realKeys, true), 'live count_lines: count_product dropped, (count, product, location) key present');
    $assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::countLines() . ' WHERE location_id = 0') === 0, 'live count_lines: no line left without a location');

    // A failing ALTER (every ALTER on the scratch table errors): not ready, the
    // v6 key stays, and a later run without the failure completes.
    $createV6();
    $addFilter(static fn (string $q): string => stripos($q, 'ALTER TABLE ' . $scratch) !== false ? 'SELECT kstest_forced_alter_failure FROM DUAL' : $q);
    $wpdb->suppress_errors(true);
    $migrated = Schema::migrateCountLines($scratch);
    $wpdb->suppress_errors(false);
    $dropFilters();
    $assert($migrated === false && !Schema::countLinesReady($scratch), 'a failed ALTER: migrateCountLines() reports the table not ready');
    $assert(isset($keysOf($scratch)['count_product']), 'a failed ALTER leaves the v6 count_product key in place');
    // Column and new key added, only the drop of the old key fails: still not ready.
    $addFilter(static fn (string $q): string => stripos($q, 'DROP INDEX count_product') !== false ? 'SELECT kstest_forced_drop_failure FROM DUAL' : $q);
    $wpdb->suppress_errors(true);
    $migrated = Schema::migrateCountLines($scratch);
    $wpdb->suppress_errors(false);
    $dropFilters();
    $assert($migrated === false && isset($keysOf($scratch)['count_product_location']), 'new key added but the old one not dropped: still not ready');
    $assert(Schema::migrateCountLines($scratch) === true && Schema::countLinesReady($scratch), 'the retry without the failure completes the migration');

    // install(): the version is stamped only when the live table verifies. Hide
    // the live table's key from the readiness check → stays at v6; then retry.
    update_option(Schema::VERSION_OPTION, 6, false);
    $addFilter(static fn (string $q): string => (stripos($q, 'SHOW INDEX FROM ' . Schema::countLines()) !== false && stripos($q, 'Key_name') !== false) ? 'SELECT 1 FROM DUAL WHERE 0' : $q);
    $wpdb->suppress_errors(true);
    Schema::maybeUpgrade();
    $wpdb->suppress_errors(false);
    $dropFilters();
    $assert((int) get_option(Schema::VERSION_OPTION) === 6, 'install() does not stamp v7 while the table does not verify');
    Schema::maybeUpgrade();
    $assert((int) get_option(Schema::VERSION_OPTION) === 7, 'the next upgrade run verifies and stamps v7');

    // A scratch table in the exact v6 shape, with data, migrated twice.
    $createV6();
    $assert(Schema::migrateCountLines($scratch), 'a v6 table migrates and verifies');
    $assert(Schema::migrateCountLines($scratch), 'a second run is a verified no-op (idempotent)');
    $default = Balances::defaultLocationId();
    $cols    = array_map(static fn ($r) => $r->Field, (array) $wpdb->get_results("SHOW COLUMNS FROM $scratch"));
    $assert(in_array('location_id', $cols, true), 'scratch v6 table gains location_id');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $scratch WHERE location_id = %d", $default)) === 3, 'every v6 line backfills to the default location');
    $keys = $keysOf($scratch);
    $assert(!isset($keys['count_product']), 'old count_product key dropped (dbDelta alone would keep it)');
    $assert(($keys['count_product_location'] ?? 0) === 3, 'new (count, product, location) unique key present');
    $assert($wpdb->query("INSERT INTO $scratch (count_id, product_id, location_id, expected) VALUES (1, 10, 999999, 0)") === 1, 'a second location line for the same (count, product) is accepted');
    $wpdb->suppress_errors(true);
    $dup = $wpdb->query($wpdb->prepare("INSERT INTO $scratch (count_id, product_id, location_id, expected) VALUES (1, 10, %d, 0)", $default));
    $wpdb->suppress_errors(false);
    $assert($dup === false, 'a duplicate (count, product, location) is still refused');
    $wpdb->query("DROP TABLE IF EXISTS $scratch");

    /* --- Fixtures -------------------------------------------------------- */
    foreach (['KSTEST-PRD04C-A' => 'KSTEST PRD-04 count A', 'KSTEST-PRD04C-B' => 'KSTEST PRD-04 count B'] as $sku => $name) {
        $pid = (int) wc_get_product_id_by_sku($sku);
        if ($pid <= 0) {
            $p = new WC_Product_Simple();
            $p->set_name($name);
            $p->set_sku($sku);
            $p->set_status('draft');
            $p->set_manage_stock(true);
            $p->set_stock_quantity(0);
            $pid = (int) $p->save();
        }
        $products[$sku] = $pid;
    }
    [$a, $b] = [$products['KSTEST-PRD04C-A'], $products['KSTEST-PRD04C-B']];
    $assert($a > 0 && $b > 0, 'durable test products exist');

    foreach (Locations::all() as $location) {
        if (str_starts_with((string) $location['name'], 'KSTEST PRD-04C')) {
            $store = (int) $location['id'];
            break;
        }
    }
    if ($store <= 0) {
        $store = Locations::create('KSTEST PRD-04C store ' . $run);
    } else {
        Locations::rename($store, 'KSTEST PRD-04C store ' . $run);
        if (!Locations::isActive($store)) {
            Locations::setActive($store, true);
        }
    }
    $default = Balances::defaultLocationId();
    $assert($store !== $default && Locations::isMulti(), 'a KSTEST store location is active next to the default');
    $active = array_map(static fn (array $l): int => (int) $l['id'], Locations::all(true));

    $zeroAll([$a, $b], 'pre');
    Ledger::adjust($a, 5, 'KSTEST PRD-04 count seed', null, 'kstest-prd04c-seed-d-' . $run, $default);
    Ledger::adjust($a, 3, 'KSTEST PRD-04 count seed', null, 'kstest-prd04c-seed-s-' . $run, $store);

    /* --- Create: lines per location -------------------------------------- */
    WP_CLI::log('Create');
    $c1 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a, $b], 'blind' => true, 'note' => 'KSTEST PRD-04 count']);
    $countIds[] = $c1;
    $assert(count(Counts::lines($c1)) === 2 * count($active), 'one line per product × active location');
    $assert(array_keys(Counts::locations($c1)) === $active && Counts::isMulti($c1), 'the count covers every active location, default first');
    $assert($near((float) $lineOf($c1, $a, $default)['expected'], 5.0), 'expected at the default = its on_hand (5)');
    $assert($near((float) $lineOf($c1, $a, $store)['expected'], 3.0), 'expected at the store = its on_hand (3)');
    $assert($near((float) $lineOf($c1, $b, $store)['expected'], 0.0), 'expected is 0 where the product has never been');

    ob_start();
    CountSheet::capture(Counts::find($c1));
    $html = (string) ob_get_clean();
    $assert(strpos($html, 'ks-loc-toggle') !== false && strpos($html, 'id="ks-count-in"') !== false && strpos($html, 'data-loc="' . $store . '"') !== false, 'multi capture renders location toggles, Teller i and per-location columns');

    // All or nothing: the second line insert fails → exception, nothing left.
    $maxCount = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . Schema::counts());
    $nth      = 0;
    $addFilter(static function (string $q) use (&$nth): string {
        if (stripos($q, 'INSERT INTO `' . Schema::countLines() . '`') === 0 && ++$nth === 2) {
            return 'INSERT INTO kstest_missing_table_prd04c (id) VALUES (1)';
        }
        return $q;
    });
    $threw = false;
    $wpdb->suppress_errors(true);
    try {
        Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a, $b], 'note' => 'KSTEST PRD-04 rollback ' . $run]);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    $wpdb->suppress_errors(false);
    $dropFilters();
    $assert($threw, 'a failed line insert makes Counts::create() throw');
    $assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::counts() . ' WHERE id > %d AND note = %s', $maxCount, 'KSTEST PRD-04 rollback ' . $run)) === 0
        && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::countLines() . ' WHERE count_id > %d', $maxCount)) === 0, 'no partial count: the document and its first line are rolled back');

    /* --- Apply per location, sale mid-count ------------------------------ */
    WP_CLI::log('Apply');
    CountLines::setCounted((int) $lineOf($c1, $a, $default)['id'], 4); // −1 vs expected 5
    CountLines::increment((int) $lineOf($c1, $a, $store)['id'], 1);   // scanner at the store: 1, −2 vs 3
    $assert($near((float) $lineOf($c1, $a, $store)['counted'], 1.0) && $lineOf($c1, $a, $default)['counted'] !== null, 'a scan increments only its own location line');
    // A web sale (default location) after the shelf was counted.
    Ledger::adjust($a, -1, 'KSTEST PRD-04 count web sale', null, 'kstest-prd04c-sale-' . $run, $default);
    $assert(Counts::toReview($c1), 'count reaches review');
    $res = Apply::apply($c1, false);
    $assert($res['status'] === 'confirm_required' && $res['multi'] === true, 'drift + uncounted ask for the combined confirm');
    $kinds = array_count_values(array_map(static fn (array $i): string => $i['kind'] . '@' . $i['location_id'], $res['issues']));
    $assert(($kinds['drift@' . $default] ?? 0) === 1 && !isset($kinds['drift@' . $store]), 'drift is checked per location (only the default moved)');
    $res = Apply::apply($c1, true);
    $assert($res['status'] === 'ok' && $res['movements'] === 2, 'confirmed apply posts two movements');
    $moves = $appliedMoves($c1);
    $mD = $moves[(int) $lineOf($c1, $a, $default)['id']] ?? [];
    $mS = $moves[(int) $lineOf($c1, $a, $store)['id']] ?? [];
    $assert(count($mD) === 1 && (int) $mD[0]['location_id'] === $default && $near((float) $mD[0]['delta'], -1.0), 'default delta −1 posted at the default');
    $assert(count($mS) === 1 && (int) $mS[0]['location_id'] === $store && $near((float) $mS[0]['delta'], -2.0), 'store delta −2 posted at the store');
    $assert((string) $mD[0]['reason'] === Reasons::COUNT && (string) $mD[0]['idempotency_key'] === 'count_line:' . (int) $mD[0]['ref_id'] . ':apply', 'reason count, key count_line:{id}:apply');
    $assert($mD[0]['batch'] !== null && $mD[0]['batch'] === $mS[0]['batch'], 'both locations post in ONE batch');
    $assert($near(Balances::onHand($a, $default), 3.0), 'default ends at counted 4 minus the later sale = 3 (the sale survives)');
    $assert($near(Balances::onHand($a, $store), 1.0), 'store ends at its counted 1');

    /* --- Second apply ---------------------------------------------------- */
    $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::movements());
    $again  = Apply::apply($c1, true);
    $assert($again['status'] === 'ok' && $again['movements'] === 0, 'second apply is an ok no-op');
    // Bypass the status gate: the ledger keys still dedupe.
    $wpdb->update(Schema::counts(), ['status' => Counts::STATUS_REVIEW], ['id' => $c1], ['%s'], ['%d']);
    Apply::apply($c1, true);
    $assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::movements()) === $before, 'a re-apply past the status gate posts nothing (idempotency keys)');
    $assert($near(Balances::onHand($a, $default), 3.0) && $near(Balances::onHand($a, $store), 1.0), 'balances unchanged by the second apply');

    /* --- Only the store counted ------------------------------------------ */
    WP_CLI::log('Only the store counted');
    $c2 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a, $b], 'blind' => true]);
    $countIds[] = $c2;
    CountLines::setCounted((int) $lineOf($c2, $a, $store)['id'], 4); // +3 vs 1
    CountLines::setCounted((int) $lineOf($c2, $b, $store)['id'], 0); // 0 vs 0
    Counts::toReview($c2);
    $res = Apply::apply($c2, false);
    $assert($res['status'] === 'confirm_required', 'uncounted locations still need the confirm');
    $others = array_values(array_diff($active, [$store]));
    foreach ($others as $loc) {
        $rows = array_values(array_filter($res['issues'], static fn (array $i): bool => (int) $i['location_id'] === $loc));
        $assert(count($rows) === 1 && $rows[0]['kind'] === 'location_uncounted' && (int) $rows[0]['lines'] === 2, 'location ' . $loc . ' is ONE "not counted" row');
    }
    $sum = array_column($res['locations'], null, 'location_id');
    $assert((int) $sum[$store]['counted'] === 2 && (int) $sum[$store]['movements'] === 1 && (int) $sum[$default]['counted'] === 0, 'per-location summary: store 2 counted / 1 change, default 0 counted');
    $res = Apply::apply($c2, true);
    $assert($res['status'] === 'ok' && $res['movements'] === 1, 'apply posts one movement');
    $moves = $appliedMoves($c2);
    $assert(count($moves) === 1 && (int) current($moves)[0]['location_id'] === $store, 'it posts only at the store');
    $assert($near(Balances::onHand($a, $store), 4.0) && $near(Balances::onHand($a, $default), 3.0), 'store 4, default untouched at 3');

    /* --- Deactivated since creation -------------------------------------- */
    WP_CLI::log('Deactivated location');
    $c3 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
    $countIds[] = $c3;
    CountLines::setCounted((int) $lineOf($c3, $a, $store)['id'], 9);
    Counts::toReview($c3);
    Locations::setActive($store, false);
    $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::movements());
    $res = Apply::apply($c3, true);
    Locations::setActive($store, true);
    $assert($res['status'] === 'error' && strpos($res['message'], Locations::name($store)) !== false, 'counted lines on a deactivated location refuse the apply, naming it');
    $assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::movements()) === $before && Counts::find($c3)['status'] === Counts::STATUS_REVIEW, 'nothing posted, count stays in review');
    Counts::cancel($c3);

    /* --- CSV per location ------------------------------------------------ */
    WP_CLI::log('CSV');
    $c4 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a, $b], 'blind' => false]);
    $countIds[] = $c4;
    $storeName = Locations::name($store);
    $h = $csvHandle(['sku', 'product', 'location', 'location_id', 'counted', 'product_id'], [
        ['KSTEST-PRD04C-A', 'A', '', (string) $default, '7', $a],                  // by id
        ['KSTEST-PRD04C-A', 'A', strtoupper($storeName), '', '2', $a],              // by name, any case
        ['KSTEST-PRD04C-B', 'B', '', '', '5', $b],                                  // no location → rejected
        ['KSTEST-PRD04C-B', 'B', '', '999999', '6', $b],                            // not in this count → rejected
    ]);
    $res = Import::fill($c4, $h);
    fclose($h);
    $assert($res['filled'] === 2 && count($res['errors']) === 2, 'import fills 2 lines and rejects 2 rows');
    $noLocation = sprintf(__('Row %1$d (%2$s) has no location. This count covers several locations, so every row needs location_id or location.', 'kaupang-stock'), 4, 'KSTEST-PRD04C-B');
    $assert(in_array($noLocation, $res['errors'], true), 'a location-less row in a multi-location count is rejected, not guessed');
    $assert($near((float) $lineOf($c4, $a, $default)['counted'], 7.0) && $near((float) $lineOf($c4, $a, $store)['counted'], 2.0), 'rows land on their own location lines');
    $assert($lineOf($c4, $b, $default)['counted'] === null && $lineOf($c4, $b, $store)['counted'] === null, 'rejected rows fill nothing');

    [$header, $rows] = CountsPage::exportSheet(Counts::find($c4));
    $assert($header === ['sku', 'product', 'location', 'location_id', 'counted', 'expected', 'product_id'], 'multi export header: sku, product, location, location_id, counted, expected, product_id');
    $assert(count($rows) === 2 * count($active), 'multi export: one row per (product, location)');
    [, $storeRows] = CountsPage::exportSheet(Counts::find($c4), [$store]);
    $assert(count($storeRows) === 2 && count(array_filter($storeRows, static fn ($r): bool => (int) $r[3] === $store)) === 2, 'export limited to the visible locations');

    $c5 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a, $b], 'blind' => false]);
    $countIds[] = $c5;
    $h = $csvHandle($header, $rows);
    $res = Import::fill($c5, $h);
    fclose($h);
    $assert($res['errors'] === [] && $res['filled'] === 2, 'round trip: the export imports cleanly into a fresh count');
    $assert($near((float) $lineOf($c5, $a, $default)['counted'], 7.0) && $near((float) $lineOf($c5, $a, $store)['counted'], 2.0) && $lineOf($c5, $b, $store)['counted'] === null, 'round trip keeps counted per location (blank stays uncounted)');
    Counts::cancel($c4);
    Counts::cancel($c5);

    /* --- One-location count: the v1 behaviour ---------------------------- */
    WP_CLI::log('One-location count');
    $c6 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a, $b], 'blind' => true, 'locations' => [$default]]);
    $countIds[] = $c6;
    $assert(count(Counts::lines($c6)) === 2 && !Counts::isMulti($c6), 'one-location count: one line per product');
    ob_start();
    CountSheet::capture(Counts::find($c6));
    $html = (string) ob_get_clean();
    ob_start();
    CountSheet::review(Counts::find($c6));
    $html .= (string) ob_get_clean();
    $assert(strpos($html, 'ks-loc-toggle') === false && strpos($html, 'id="ks-count-in"') === false && strpos($html, 'data-loc=') === false && strpos($html, 'ks-multi') === false, 'one-location capture/review: no toggles, no Teller i, no location columns');
    [$header] = CountsPage::exportSheet(Counts::find($c6));
    $assert($header === ['sku', 'product', 'counted', 'product_id'], 'one-location blind export keeps the v1 header');
    $h = $csvHandle(['sku', 'counted'], [['KSTEST-PRD04C-A', '2']]);
    $res = Import::fill($c6, $h);
    fclose($h);
    $assert($res['filled'] === 1 && $res['errors'] === [], 'one-location import needs no location column');
    $h = $csvHandle(['sku', 'counted'], [['KSTEST-PRD04C-A', '2']]);
    $assert(Import::parse($h)['lines'] === [$a => 2.0], 'Import::parse keeps its v1 product → counted shape');
    fclose($h);
    Counts::toReview($c6);
    $res = Apply::apply($c6, false);
    $assert($res['status'] === 'confirm_required' && $res['multi'] === false, 'one-location count: confirm for the uncounted line');
    $assert(count($res['issues']) === 1 && $res['issues'][0]['kind'] === 'uncounted' && (int) $res['issues'][0]['product_id'] === $b, 'uncounted listed per line, as before');
    $res = Apply::apply($c6, true);
    $assert($res['status'] === 'ok' && $res['movements'] === 1, 'one-location apply posts one movement');
    $moves = $appliedMoves($c6);
    $assert((int) current($moves)[0]['location_id'] === $default && $near(Balances::onHand($a, $default), 2.0) && $near(Balances::onHand($a, $store), 4.0), 'posted at the default only (3 → 2); the store is outside this count');

    WP_CLI::success('PRD-04 §2 counting-per-location smoke test passed.');
} finally {
    $dropFilters();
    $wpdb->suppress_errors(false);
    $wpdb->query("DROP TABLE IF EXISTS $scratch");
    if ((int) get_option(Schema::VERSION_OPTION) < Schema::VERSION) {
        Schema::maybeUpgrade(); // the stamp test left it at 6
    }
    try {
        Settings::update(['stock_enabled' => true, 'mode' => Settings::MODE_ACTIVE, 'allow_negative_locations' => true]);
        if ($store > 0 && !Locations::isActive($store)) {
            Locations::setActive($store, true);
        }
        if ($products !== []) {
            $zeroAll(array_values($products), 'fin');
        }
    } catch (Throwable $e) {
        WP_CLI::warning('PRD-04 count cleanup (stock) failed: ' . $e->getMessage());
    }
    foreach ($countIds as $countId) {
        $wpdb->delete(Schema::countLines(), ['count_id' => (int) $countId], ['%d']);
        $wpdb->delete(Schema::counts(), ['id' => (int) $countId], ['%d']);
    }
    if ($store > 0) {
        try {
            Locations::rename($store, 'KSTEST PRD-04C (inactive)');
            if (Locations::isActive($store)) {
                Locations::setActive($store, false);
            }
        } catch (Throwable $e) {
            WP_CLI::warning('PRD-04 count cleanup (location) failed: ' . $e->getMessage());
        }
    }
    wp_set_current_user($originalUserId);
    if ($hadSettings) {
        update_option(Settings::OPTION_KEY, $storedSettings, false);
    } else {
        delete_option(Settings::OPTION_KEY);
    }
    Settings::flushCache();
}
