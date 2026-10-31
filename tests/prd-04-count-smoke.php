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
 *  - a counted line (and a whole location) can be set back to uncounted while
 *    open or in review, which also clears the deactivated-location refusal;
 *  - in review a clear writes a trail entry, and needs a note when the line
 *    (or one of the location's lines) is flagged for recount;
 *  - the apply claim: a scan that lands before the claim is posted, a scan
 *    after the claim is refused (REST 409), a fresh claim refuses a second
 *    apply and every write, a stale one is taken over, a ledger failure
 *    releases it;
 *  - recovery: a throwable after the batch committed leaves the count
 *    applying with its claim open, a died apply is taken over once stale, and
 *    either retry finishes the batch (pending write-throughs completed,
 *    `_stock` = ledger, nothing posted twice, no drift confirm); a throwable
 *    before the ledger goes back to review; a stale claim refused for a
 *    deactivated location goes back to review so its lines can be cleared;
 *  - "Teller i" in counting.js (under node, against a DOM stub): an automatic
 *    choice is never remembered, an explicit pick is;
 *  - an import row whose location name two of the count's locations share is
 *    rejected;
 *  - a one-location count keeps the v1 shape: no location UI, the v1 CSV header,
 *    a location-less import, per-line uncounted issues; only clearing a
 *    counted line is new there (review "Mark uncounted", a trail entry).
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
use Kaupang\Stock\Counting\NoteRequiredException;
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

/** POST to a kaupang-stock/v1 route as the current user. */
$rest = static function (string $path, array $body): WP_REST_Response {
    $req = new WP_REST_Request('POST', '/kaupang-stock/v1/' . ltrim($path, '/'));
    $req->set_header('Content-Type', 'application/json');
    $req->set_body((string) wp_json_encode($body));
    return rest_do_request($req);
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
    $assert((int) get_option(Schema::VERSION_OPTION) === Schema::VERSION, 'schema is at the current version (v7 or later)');
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
    $assert((int) get_option(Schema::VERSION_OPTION) === Schema::VERSION, 'the next upgrade run verifies and stamps the current version');

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
    $assert((bool) preg_match('~<select id="ks-count-in">\s*<option value="">~', $html), 'Teller i starts unchosen (no silent default location)');
    $assert(strpos($html, 'ks-clear-loc-btn') !== false, 'multi capture offers a per-location "Mark uncounted"');

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

    /* --- Clearing a counted line ---------------------------------------- */
    WP_CLI::log('Clear');
    $c7 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a, $b], 'blind' => true]);
    $countIds[] = $c7;
    $lAd = (int) $lineOf($c7, $a, $default)['id'];
    $lAs = (int) $lineOf($c7, $a, $store)['id'];
    $lBs = (int) $lineOf($c7, $b, $store)['id'];
    CountLines::setCounted($lAd, 2);
    $row = CountLines::clear($lAd);
    $assert($row !== null && $row['counted'] === null && $row['counted_by'] === null && $row['counted_at'] === null && (int) $row['recount'] === 0, 'a counted line goes back to uncounted while open');
    $r = $rest('count-lines/' . $lAs, ['increment' => 1]);
    $assert($r->get_status() === 200 && $near((float) $r->get_data()['line']['counted'], 1.0), 'REST: a scan increments the store line');
    $r = $rest('count-lines/' . $lAs, ['clear' => true]);
    $assert($r->get_status() === 200 && $r->get_data()['line']['counted'] === null, 'REST: {clear: true} sets it back to uncounted (a mis-scan undone)');
    CountLines::setCounted($lAs, 3);
    CountLines::setCounted($lBs, 1);
    CountLines::setCounted($lAd, 4);
    $r = $rest('counts/' . $c7 . '/clear', ['location_id' => $store]);
    $assert($r->get_status() === 200 && (int) $r->get_data()['cleared'] === 2, 'REST: clearing the store clears its 2 counted lines');
    $assert($lineOf($c7, $a, $store)['counted'] === null && $lineOf($c7, $b, $store)['counted'] === null && $lineOf($c7, $a, $default)['counted'] !== null, 'only that location is cleared; the other location keeps its counting');
    $assert($rest('counts/' . $c7 . '/clear', ['location_id' => 999999])->get_status() === 400, 'REST: a location outside the count is a 400');

    Counts::toReview($c7);
    CountLines::setCounted($lAs, 5); // writes are still allowed in review
    ob_start();
    CountSheet::review(Counts::find($c7));
    $html = (string) ob_get_clean();
    $assert(strpos($html, 'ks-clear-btn') !== false && strpos($html, 'ks-clear-loc-btn') !== false, 'multi review offers "Mark uncounted" per counted line and per location');
    $assert(trim((string) Counts::find($c7)['note']) === '', 'clearing while open leaves no trail entry');
    // In review a clear is recorded, and a line flagged for recount needs a note.
    $wpdb->update(Schema::countLines(), ['recount' => 1], ['id' => $lAs], ['%d'], ['%d']);
    $threw = false;
    try {
        CountLines::clear($lAs);
    } catch (NoteRequiredException $e) {
        $threw = true;
    }
    $assert($threw && $near((float) $lineOf($c7, $a, $store)['counted'], 5.0), 'review: clearing a flagged line without a note throws and writes nothing');
    $r = $rest('count-lines/' . $lAs, ['clear' => true]);
    $assert($r->get_status() === 400 && ($r->get_data()['code'] ?? '') === 'note_required', 'REST: the same without a note is a 400 note_required');
    $r = $rest('count-lines/' . $lAs, ['clear' => true, 'note' => 'KSTEST scanned into the wrong shelf']);
    $trail = (string) Counts::find($c7)['note'];
    $assert($r->get_status() === 200 && $r->get_data()['line']['counted'] === null, 'REST: with a note the flagged line is cleared');
    $assert(strpos($trail, '[uncounted] line #' . $lAs . ', ') !== false && strpos($trail, ' @ ' . Locations::name($store)) !== false && strpos($trail, 'was 5') !== false && strpos($trail, 'KSTEST scanned into the wrong shelf') !== false, 'and the trail records the line, its location, what it held and the note');
    CountLines::setCounted($lBs, 2);
    $row = CountLines::clear($lBs);
    $assert($row !== null && $row['counted'] === null && strpos((string) Counts::find($c7)['note'], '[uncounted] line #' . $lBs . ', ') !== false, 'an unflagged line clears in review without a note and still leaves a trail entry');
    CountLines::setCounted($lAs, 5);
    CountLines::setCounted($lBs, 1);
    $wpdb->update(Schema::countLines(), ['recount' => 1], ['id' => $lBs], ['%d'], ['%d']);
    $threw = false;
    try {
        CountLines::clearLocation($c7, $store);
    } catch (NoteRequiredException $e) {
        $threw = true;
    }
    $assert($threw && $lineOf($c7, $b, $store)['counted'] !== null, 'review: clearing a location with a flagged line needs a note');
    $r = $rest('counts/' . $c7 . '/clear', ['location_id' => $store]);
    $assert($r->get_status() === 400 && ($r->get_data()['code'] ?? '') === 'note_required', 'REST: location clear without a note is a 400 note_required');
    $r = $rest('counts/' . $c7 . '/clear', ['location_id' => $store, 'note' => 'KSTEST store recounted tomorrow']);
    $assert($r->get_status() === 200 && (int) $r->get_data()['cleared'] === 2 && strpos((string) Counts::find($c7)['note'], 'KSTEST store recounted tomorrow') !== false, 'with a note the location is cleared and the trail says so');

    // The way out of the deactivated-location refusal: clear, then apply.
    CountLines::setCounted($lAs, 5);
    CountLines::setCounted($lAd, (float) $lineOf($c7, $a, $default)['expected']); // zero delta
    Locations::setActive($store, false);
    $res = Apply::apply($c7, true);
    $assert($res['status'] === 'error', 'counted lines on the deactivated store refuse the apply');
    $cleared = CountLines::clearLocation($c7, $store);
    $res = Apply::apply($c7, true);
    Locations::setActive($store, true);
    $assert($cleared === 1 && $res['status'] === 'ok' && $res['movements'] === 0, 'after clearing the store the count applies (store untouched)');
    $assert(CountLines::clear($lAd) === null && CountLines::clearLocation($c7, $default) === null, 'an applied count refuses clearing');

    /* --- Apply claim: no write after apply has read the lines ------------ */
    WP_CLI::log('Apply claim');
    $isClaim = static fn (string $q): bool => stripos($q, 'UPDATE ' . Schema::counts()) !== false && stripos($q, "status = 'review' OR") !== false;
    $isLinesRead = static fn (string $q): bool => stripos($q, 'SELECT * FROM ' . Schema::countLines() . ' WHERE count_id') !== false;

    // (1) A scan that commits just before the claim is in the posted batch.
    $c8 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
    $countIds[] = $c8;
    $l8s = (int) $lineOf($c8, $a, $store)['id'];
    CountLines::setCounted($l8s, (float) $lineOf($c8, $a, $store)['expected']); // zero delta at first look
    CountLines::setCounted((int) $lineOf($c8, $a, $default)['id'], (float) $lineOf($c8, $a, $default)['expected']);
    Counts::toReview($c8);
    $early = null;
    $busy  = false;
    $addFilter(static function (string $q) use (&$early, &$busy, $isClaim, $l8s): string {
        if (!$busy && $early === null && $isClaim($q)) {
            $busy  = true;
            $early = CountLines::increment($l8s, 1);
            $busy  = false;
        }
        return $q;
    });
    $res = Apply::apply($c8, true);
    $dropFilters();
    $moves = $appliedMoves($c8);
    $assert($early !== null, 'a scan just before the claim is accepted');
    $assert($res['status'] === 'ok' && $res['movements'] === 1 && $near((float) ($moves[$l8s][0]['delta'] ?? 0), 1.0), 'and its +1 is posted: apply re-reads the lines under the claim');

    // (2) A scan once apply holds the claim and reads the lines is refused.
    $c9 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
    $countIds[] = $c9;
    $l9s = (int) $lineOf($c9, $a, $store)['id'];
    CountLines::setCounted($l9s, (float) $lineOf($c9, $a, $store)['expected'] + 2); // +2
    Counts::toReview($c9);
    $claimed = false;
    $late    = 'unset';
    $lateRest = 0;
    $busy    = false;
    $addFilter(static function (string $q) use (&$claimed, &$late, &$lateRest, &$busy, $isClaim, $isLinesRead, $l9s, $rest): string {
        if ($busy) {
            return $q;
        }
        if ($isClaim($q)) {
            $claimed = true;
        } elseif ($claimed && $late === 'unset' && $isLinesRead($q)) {
            $busy     = true;
            $late     = CountLines::increment($l9s, 1);
            $lateRest = $rest('count-lines/' . $l9s, ['increment' => 1])->get_status();
            $busy     = false;
        }
        return $q;
    });
    $res = Apply::apply($c9, true);
    $dropFilters();
    $moves = $appliedMoves($c9);
    $assert($claimed && $late === null && $lateRest === 409, 'a scan after the claim is refused (CountLines null, REST 409)');
    $assert($res['status'] === 'ok' && $near((float) ($moves[$l9s][0]['delta'] ?? 0), 2.0) && $near((float) $lineOf($c9, $a, $store)['counted'], (float) $lineOf($c9, $a, $store)['expected'] + 2), 'the posted delta is what the lines held at the claim (+2), and the line still says so');

    // (3) The lock, across two connections: a write in flight (shared lock on
    // the count row) makes the claim wait, so it cannot slip past it.
    $c10 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
    $countIds[] = $c10;
    $l10s = (int) $lineOf($c10, $a, $store)['id'];
    CountLines::setCounted($l10s, (float) $lineOf($c10, $a, $store)['expected'] + 1);
    Counts::toReview($c10);
    [$dbHost, $dbPort, $dbSocket] = array_slice(array_pad((array) $wpdb->parse_db_host(DB_HOST), 4, null), 0, 3);
    $m2 = @new mysqli((string) $dbHost, DB_USER, DB_PASSWORD, DB_NAME, $dbPort ? (int) $dbPort : 3306, $dbSocket ? (string) $dbSocket : null);
    if ($m2->connect_errno) {
        WP_CLI::warning('second connection unavailable; cross-connection lock check skipped: ' . $m2->connect_error);
    } else {
        $waitWas = (int) $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 1');
        $m2->query('START TRANSACTION');
        $m2->query('SELECT status FROM ' . Schema::counts() . ' WHERE id = ' . $c10 . ' LOCK IN SHARE MODE');
        $wpdb->suppress_errors(true);
        $res = Apply::apply($c10, true);
        $wpdb->suppress_errors(false);
        $m2->query('ROLLBACK');
        $assert($res['status'] === 'error' && Counts::find($c10)['status'] === Counts::STATUS_REVIEW && $appliedMoves($c10) === [], 'a write in flight on another connection blocks the claim: nothing posted, still in review');
        // And the other way: an apply holding the claim lock blocks the write.
        $m2->query('START TRANSACTION');
        $m2->query("UPDATE " . Schema::counts() . " SET status = 'applying' WHERE id = " . $c10);
        $wpdb->suppress_errors(true);
        $blocked = CountLines::increment($l10s, 1);
        $wpdb->suppress_errors(false);
        $m2->query('ROLLBACK');
        $m2->close();
        $wpdb->query($wpdb->prepare('SET SESSION innodb_lock_wait_timeout = %d', max(1, $waitWas)));
        $assert($blocked === null && $near((float) $lineOf($c10, $a, $store)['counted'], (float) $lineOf($c10, $a, $store)['expected'] + 1), 'a claim in progress on another connection blocks the write: refused, line unchanged');
    }

    // (4) A fresh claim refuses a second apply and every write; a stale one is taken over.
    $wpdb->update(Schema::counts(), ['status' => Counts::STATUS_APPLYING, 'applied_by' => get_current_user_id(), 'applied_at' => gmdate('Y-m-d H:i:s')], ['id' => $c10], ['%s', '%d', '%s'], ['%d']);
    $res = Apply::apply($c10, true);
    $assert($res['status'] === 'error' && $appliedMoves($c10) === [], 'a fresh applying claim refuses a second apply');
    $assert(CountLines::setCounted($l10s, 9) === null && CountLines::clear($l10s) === null && $rest('count-lines/' . $l10s, ['counted' => 9])->get_status() === 409, 'while applying every line write is refused (REST 409)');
    $assert(!Counts::reopen($c10) && !Counts::cancel($c10), 'while applying the count can be neither reopened nor cancelled');
    $h = $csvHandle(['sku', 'location_id', 'counted'], [['KSTEST-PRD04C-A', (string) $store, '9']]);
    $res = Import::fill($c10, $h);
    fclose($h);
    $assert($res['filled'] === 0 && count($res['errors']) === 1, 'an import into an applying count writes nothing and says so');
    ob_start();
    CountSheet::review(Counts::find($c10));
    $html = (string) ob_get_clean();
    $assert(strpos($html, 'ks-apply-btn') !== false && strpos($html, 'ks-clear-btn') === false, 'the applying review screen offers apply (to finish) and no clearing');
    $wpdb->update(Schema::counts(), ['applied_at' => gmdate('Y-m-d H:i:s', time() - Apply::CLAIM_TTL - 60)], ['id' => $c10], ['%s'], ['%d']);
    $res = Apply::apply($c10, true);
    $assert($res['status'] === 'ok' && $res['movements'] === 1 && Counts::find($c10)['status'] === Counts::STATUS_APPLIED, 'a stale claim (a died apply) is taken over and the count applies once');

    // (5) A ledger failure releases the claim; the next apply succeeds.
    $c11 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
    $countIds[] = $c11;
    $l11s = (int) $lineOf($c11, $a, $store)['id'];
    CountLines::setCounted($l11s, (float) $lineOf($c11, $a, $store)['expected'] + 1);
    Counts::toReview($c11);
    $addFilter(static fn (string $q): string => stripos($q, 'INSERT INTO `' . Schema::movements() . '`') === 0 ? 'INSERT INTO kstest_missing_table_prd04c (id) VALUES (1)' : $q);
    $wpdb->suppress_errors(true);
    $res = Apply::apply($c11, true);
    $wpdb->suppress_errors(false);
    $dropFilters();
    $c11row = Counts::find($c11);
    $assert($res['status'] === 'error' && $c11row['status'] === Counts::STATUS_REVIEW && $c11row['applied_at'] === null && $appliedMoves($c11) === [], 'a ledger failure: nothing posted, claim released back to review');
    $assert(CountLines::setCounted($l11s, (float) $lineOf($c11, $a, $store)['expected'] + 1) !== null, 'counting works again after the release');
    $res = Apply::apply($c11, true);
    $assert($res['status'] === 'ok' && $res['movements'] === 1, 'the retried apply posts once');

    // (6) A movement listener throws after the batch committed: the second
    // movement's write-through never runs, so `_stock` lags the ledger. The
    // count stays recoverable: still `applying`, its claim open at once, and
    // the retry (unconfirmed: no drift confirm for lines the batch itself
    // moved) finishes it, completing the write-through, posting nothing new.
    $movementTotal = static fn (): int => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::movements());
    $gap = static fn (int $pid): float => (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(on_hand), 0) FROM ' . Schema::balances() . ' WHERE product_id = %d', $pid)) - Ledger::readStockDirect($pid);
    $throwsLeft = 0;
    $thrower = static function () use (&$throwsLeft): void {
        if ($throwsLeft > 0) {
            $throwsLeft--;
            throw new RuntimeException('KSTEST listener failure');
        }
    };
    $twoLineCount = static function () use ($a, $default, $store, $lineOf, &$countIds): int {
        $c = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
        $countIds[] = $c;
        CountLines::setCounted((int) $lineOf($c, $a, $default)['id'], (float) $lineOf($c, $a, $default)['expected'] + 2);
        CountLines::setCounted((int) $lineOf($c, $a, $store)['id'], (float) $lineOf($c, $a, $store)['expected'] + 1);
        Counts::toReview($c);
        return $c;
    };
    $assert(abs($gap($a)) < 1e-9, '_stock matches the ledger before the write-through scenarios');
    $c13 = $twoLineCount();
    $throwsLeft = 1;
    add_action('kaupang/stock/movement_recorded', $thrower, 1);
    $res = Apply::apply($c13, true);
    remove_action('kaupang/stock/movement_recorded', $thrower, 1);
    $c13row = Counts::find($c13);
    $assert($res['status'] === 'error' && $c13row['status'] === Counts::STATUS_APPLYING && count($appliedMoves($c13)) === 2, 'a throwable after the commit: an error, the batch posted (2), the count still applying');
    $assert($c13row['applied_at'] === null && Apply::retryFrom($c13row) === '', 'its claim is open for an immediate retry');
    $assert(abs($gap($a)) > 0.5, 'a write-through is pending: _stock lags the ledger');
    $assert(CountLines::setCounted((int) $lineOf($c13, $a, $store)['id'], 1) === null, 'its lines stay locked (they are what was posted)');
    ob_start();
    CountSheet::review($c13row);
    $html = (string) ob_get_clean();
    $assert(strpos($html, __('An apply of this count was interrupted. Use Bruk telling to finish it now; nothing is posted twice. Counting stays locked until then.', 'kaupang-stock')) !== false, 'the review screen says it can be finished now');
    $before = $movementTotal();
    $res = Apply::apply($c13, false);
    $assert($res['status'] === 'ok' && $res['movements'] === 0 && ($res['finished'] ?? 0) === 2 && Counts::find($c13)['status'] === Counts::STATUS_APPLIED, 'the retry finishes it: ok without a confirm, 0 new movements, 2 finished, applied');
    $assert($movementTotal() === $before && abs($gap($a)) < 1e-9, 'nothing posted twice, and _stock matches the ledger again');
    $assert(strpos($res['message'], 'movements posted') === false && strpos($res['message'], 'Nothing was posted twice') !== false, 'the message says the movements were already posted, not that they were posted now');

    // (7) An apply that died after the commit (its claim fresh, write-through
    // pending): refused with the time it can be finished; taken over once
    // stale, which completes the write-through.
    $c14 = $twoLineCount();
    $throwsLeft = 1;
    add_action('kaupang/stock/movement_recorded', $thrower, 1);
    Apply::apply($c14, true);
    remove_action('kaupang/stock/movement_recorded', $thrower, 1);
    $wpdb->update(Schema::counts(), ['applied_at' => gmdate('Y-m-d H:i:s')], ['id' => $c14], ['%s'], ['%d']); // as a died request leaves it
    $assert(abs($gap($a)) > 0.5 && count($appliedMoves($c14)) === 2, 'died after the commit: batch posted, write-through pending');
    $res = Apply::apply($c14, false);
    $from = Apply::retryFrom(Counts::find($c14));
    $assert($res['status'] === 'error' && is_string($from) && $from !== '' && strpos($res['message'], $from) !== false, 'a fresh claim refuses, naming the time it can be finished (' . $from . ')');
    ob_start();
    CountSheet::review(Counts::find($c14));
    $html = (string) ob_get_clean();
    $assert(strpos($html, $from) !== false, 'the review screen names that time too');
    $wpdb->update(Schema::counts(), ['applied_at' => gmdate('Y-m-d H:i:s', time() - Apply::CLAIM_TTL - 60)], ['id' => $c14], ['%s'], ['%d']);
    $before = $movementTotal();
    $res = Apply::apply($c14, false);
    $assert($res['status'] === 'ok' && $res['movements'] === 0 && ($res['finished'] ?? 0) === 2 && Counts::find($c14)['status'] === Counts::STATUS_APPLIED, 'the takeover finishes it without a drift confirm');
    $assert($movementTotal() === $before && abs($gap($a)) < 1e-9, 'takeover: nothing posted twice, _stock matches the ledger');

    // (8) A throwable after the claim but before the ledger: back to review.
    $c15 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
    $countIds[] = $c15;
    CountLines::setCounted((int) $lineOf($c15, $a, $store)['id'], (float) $lineOf($c15, $a, $store)['expected'] + 1);
    Counts::toReview($c15);
    $claimed = false;
    $addFilter(static function (string $q) use (&$claimed, $isClaim, $isLinesRead): string {
        if ($isClaim($q)) {
            $claimed = true;
        } elseif ($claimed && $isLinesRead($q)) {
            $claimed = false;
            throw new RuntimeException('KSTEST failure under the claim');
        }
        return $q;
    });
    $res = Apply::apply($c15, true);
    $dropFilters();
    $c15row = Counts::find($c15);
    $assert($res['status'] === 'error' && $c15row['status'] === Counts::STATUS_REVIEW && $c15row['applied_at'] === null && $appliedMoves($c15) === [], 'a throwable before the ledger: nothing posted, back to review');
    $res = Apply::apply($c15, true);
    $assert($res['status'] === 'ok' && $res['movements'] === 1, 'and the next apply posts once');

    // (9) A stale claim whose lines sit on a location deactivated since: the
    // refusal hands the count back to review, where its advice (mark them
    // uncounted) can be followed.
    $c16 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => true]);
    $countIds[] = $c16;
    CountLines::setCounted((int) $lineOf($c16, $a, $store)['id'], 1);
    Counts::toReview($c16);
    $wpdb->update(Schema::counts(), ['status' => Counts::STATUS_APPLYING, 'applied_by' => get_current_user_id(), 'applied_at' => gmdate('Y-m-d H:i:s', time() - Apply::CLAIM_TTL - 60)], ['id' => $c16], ['%s', '%d', '%s'], ['%d']);
    Locations::setActive($store, false);
    $res = Apply::apply($c16, true);
    $cleared = Counts::find($c16)['status'] === Counts::STATUS_REVIEW ? CountLines::clearLocation($c16, $store) : null;
    Locations::setActive($store, true);
    $assert($res['status'] === 'error' && strpos($res['message'], Locations::name($store)) !== false, 'stale claim + deactivated location: refused, naming it');
    $assert($cleared === 1, 'the count is back in review, so its lines can be marked uncounted as the message says');
    Counts::cancel($c16);

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
    // Names are not unique: a name two of the count's locations share is
    // rejected, never resolved to one of them.
    $c12 = Counts::create(['scope' => Counts::SCOPE_MANUAL, 'products' => [$a], 'blind' => false]);
    $countIds[] = $c12;
    $defaultName = Locations::name($default);
    Locations::rename($store, $defaultName);
    try {
        $h = $csvHandle(['sku', 'location', 'location_id', 'counted'], [
            ['KSTEST-PRD04C-A', $defaultName, '', '6'],            // ambiguous name → rejected
            ['KSTEST-PRD04C-A', $defaultName, (string) $store, '8'], // the id decides
        ]);
        $res = Import::fill($c12, $h);
        fclose($h);
    } finally {
        Locations::rename($store, $storeName);
    }
    $ambiguous = sprintf(__('Row %1$d (%2$s): several locations in this count are called “%3$s”. Give the row its location_id.', 'kaupang-stock'), 2, 'KSTEST-PRD04C-A', $defaultName);
    $assert($res['filled'] === 1 && $res['errors'] === [$ambiguous], 'an ambiguous location name is a rejected row, never a guess');
    $assert($lineOf($c12, $a, $default)['counted'] === null && $near((float) $lineOf($c12, $a, $store)['counted'], 8.0), 'only the row with a location_id is filled');
    Counts::cancel($c12);

    Counts::cancel($c4);
    Counts::cancel($c5);

    /* --- "Teller i": only an explicit pick is remembered (counting.js) ---- */
    WP_CLI::log('Teller i (counting.js under node)');
    $nodeBin = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($nodeBin === '') {
        WP_CLI::warning('node not found; the counting.js "Teller i" checks are skipped.');
    } else {
        $plugin = dirname(__DIR__);
        $json   = (string) shell_exec(escapeshellarg($nodeBin) . ' ' . escapeshellarg($plugin . '/tests/js/count-in-harness.js') . ' ' . escapeshellarg($plugin . '/assets/counting.js') . ' 2>&1');
        $js     = json_decode($json, true);
        $assert(is_array($js) && $js !== [], 'the counting.js harness ran (' . substr($json, 0, 200) . ')');
        $labels = [
            'fresh_unchosen'            => 'several locations, a device that never picked: Teller i unchosen and marked',
            'auto_selects_single'       => 'one location visible: chosen automatically',
            'auto_not_persisted'        => 'the automatic choice is not remembered',
            'auto_dropped_when_several' => 'several visible again: the automatic choice is dropped, Teller i unchosen and marked',
            'still_not_persisted'       => 'still nothing remembered',
            'reload_unchosen'           => 'a later page load: still unchosen',
            'pick_persisted'            => 'an explicit pick is remembered',
            'auto_keeps_pick'           => 'an automatic choice afterwards does not overwrite the pick',
            'pick_restored'             => 'several visible again: the pick is back',
            'reload_keeps_pick'         => 'a later page load: the pick is restored',
        ];
        foreach ($labels as $key => $label) {
            $assert(($js[$key] ?? false) === true, 'counting.js: ' . $label);
        }
    }

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
    $assert(strpos($html, 'ks-loc-toggle') === false && strpos($html, 'id="ks-count-in"') === false && strpos($html, 'data-loc=') === false && strpos($html, 'ks-multi') === false && strpos($html, 'ks-clear') === false, 'one-location capture/review: no toggles, no Teller i, no location columns, nothing to clear yet');
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
    // Clearing a counted line works on a one-location count too (the one
    // intended difference from v1): a "Mark uncounted" per counted line in
    // review, no per-location control, a trail entry without a location.
    $l6a = (int) $lineOf($c6, $a, $default)['id'];
    ob_start();
    CountSheet::review(Counts::find($c6));
    $html = (string) ob_get_clean();
    $assert(substr_count($html, 'ks-clear-btn') === 1 && strpos($html, 'ks-clear-loc-btn') === false && (bool) preg_match('~data-line-id="' . $l6a . '"[^>]*>.*?ks-clear-btn~s', $html), 'one-location review: "Mark uncounted" on the one counted line, no location control');
    $r = $rest('count-lines/' . $l6a, ['clear' => true]);
    $trail = (string) Counts::find($c6)['note'];
    $assert($r->get_status() === 200 && $r->get_data()['line']['counted'] === null, 'one-location: REST clear sets the line back to uncounted');
    $assert(strpos($trail, '[uncounted] line #' . $l6a . ', ') !== false && strpos($trail, ' @ ') === false && strpos($trail, 'was 2') !== false, 'one-location: the review clear leaves a trail entry without a location');
    $assert(CountLines::setCounted($l6a, 2) !== null, 'and the line can be counted again');
    $res = Apply::apply($c6, false);
    $assert($res['status'] === 'confirm_required' && $res['multi'] === false, 'one-location count: confirm for the uncounted line');
    $assert(count($res['issues']) === 1 && $res['issues'][0]['kind'] === 'uncounted' && (int) $res['issues'][0]['product_id'] === $b, 'uncounted listed per line, as before');
    $storeBefore = Balances::onHand($a, $store);
    $res = Apply::apply($c6, true);
    $assert($res['status'] === 'ok' && $res['movements'] === 1, 'one-location apply posts one movement');
    $moves = $appliedMoves($c6);
    $assert((int) current($moves)[0]['location_id'] === $default && $near(Balances::onHand($a, $default), 2.0) && $near(Balances::onHand($a, $store), $storeBefore), 'posted at the default only (3 → 2); the store is outside this count');

    WP_CLI::success('PRD-04 §2 counting-per-location smoke test passed.');
} finally {
    if (isset($thrower)) {
        remove_action('kaupang/stock/movement_recorded', $thrower, 1);
    }
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
