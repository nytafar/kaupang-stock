<?php
/**
 * Admin UI regression check — renders every screen as an admin and asserts the
 * shell the redesign depends on. Run: gp wp <site> eval-file .../tests/admin-ui-check.php
 */
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? parse_url(home_url(), PHP_URL_HOST);
wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1])[0]->ID);
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

function ks_render(string $class, array $get = []): string {
    $_GET = $get; $_REQUEST = $get;
    ob_start(); $class::render(); return (string) ob_get_clean();
}
function ks_balanced(string $html): bool {
    return substr_count($html, '<div') === substr_count($html, '</div>')
        && substr_count($html, '<form') === substr_count($html, '</form>')
        && substr_count($html, '<table') === substr_count($html, '</table>');
}
$checks = [];

$h = ks_render(\Kaupang\Stock\Admin\StatusPage::class, ['page' => 'kaupang-stock']);
preg_match_all('/<th scope="col" id="([a-z_0-9]+)"/', $h, $m);
$checks['status: balanced markup']           = ks_balanced($h);
$checks['status: no search-box float']       = strpos($h, 'search-box') === false;
$checks['status: shared filter bar']         = strpos($h, 'ks-filterbar" data-ks-rowfilter="ks-status-table"') !== false;
$checks['status: no pagination']             = strpos($h, 'paged=') === false;
$checks['status: column order']              = array_slice($m[1], 0, 3) === ['product', 'available', 'on_hand'] && end($m[1]) === 'last_move';
$checks['status: column classes for toggles']= preg_match('/<td class="column-product ks-col-product/', $h) === 1;
$checks['status: rows carry data attrs']     = preg_match('/<tr data-search="[^"]+"\s+data-cat=",[^"]*,"\s+data-low=",[^"]*,">/', $h) === 1;
$checks['status: preset select in row form'] = strpos($h, 'name="preset"') !== false;
$checks['status: old required note gone']    = strpos($h, 'Note (required)') === false;

$h = ks_render(\Kaupang\Stock\Admin\MovementsPage::class, ['page' => 'kaupang-stock-movements']);
$checks['moves: balanced markup']            = ks_balanced($h);
$checks['moves: title action toggles panel'] = strpos($h, 'class="page-title-action ks-panel-toggle" aria-expanded="false" data-ks-target="ks-new-adjust"') !== false;
$checks['moves: fetch-swap bar + region']    = strpos($h, 'data-ks-fetchswap="ks-moves-list"') !== false && strpos($h, 'id="ks-moves-list"') !== false;
$checks['moves: export inside region']       = strpos($h, 'id="ks-moves-list"') < strpos($h, 'ks-export-row');
$checks['moves: filter button only noscript']= preg_match('/<noscript>.*value="Filter".*<\/noscript>/s', $h) === 1;
$checks['moves: preset in new-adjust form']  = substr_count($h, 'name="preset"') === 1;

$h = ks_render(\Kaupang\Stock\Admin\SettingsPage::class, ['page' => 'kaupang-stock-settings']);
$checks['settings: balanced markup']         = ks_balanced($h);
$checks['settings: cards']                   = substr_count($h, 'class="ks-card"') >= 4;
$checks['settings: one sticky save']         = substr_count($h, 'ks-save-bar') === 1;
$checks['settings: no inline styles']        = strpos($h, 'style="') === false;

$h = ks_render(\Kaupang\Stock\Admin\ValuationPage::class, ['page' => 'kaupang-stock-valuation']);
$checks['valuation: balanced markup']        = ks_balanced($h);
if (\Kaupang\Stock\Costing\Costing::enabled()) {
    $checks['valuation: autosubmit bars']    = substr_count($h, 'data-ks-autosubmit') === 2;
}

// Movements product filter: a title fragment must narrow the ledger, a miss must return nothing.
$all = (int) \Kaupang\Stock\Ledger\Movements::query([], 1, 1)['total'];
$_GET = ['product' => 'zzz-no-such-product-zzz'];
$f = \Kaupang\Stock\Admin\MovementsPage::readFilters();
$checks['moves filter: miss returns nothing'] = isset($f['product_ids']) && (int) \Kaupang\Stock\Ledger\Movements::query($f, 1, 1)['total'] === 0;
$first = \Kaupang\Stock\Ledger\Movements::query([], 1, 1)['rows'][0] ?? null;
if ($first) {
    $title = get_the_title((int) $first['product_id']);
    $_GET = ['product' => mb_substr($title, 0, max(3, (int) (mb_strlen($title) / 2)))];
    $f = \Kaupang\Stock\Admin\MovementsPage::readFilters();
    $hit = (int) \Kaupang\Stock\Ledger\Movements::query($f, 1, 1)['total'];
    $checks['moves filter: title fragment narrows'] = $hit > 0 && $hit <= $all;
}
$checks['moves: actor is a select'] = strpos(ks_render(\Kaupang\Stock\Admin\MovementsPage::class, ['page' => 'kaupang-stock-movements']), 'name="actor_id"') !== false
    && strpos(ks_render(\Kaupang\Stock\Admin\MovementsPage::class, ['page' => 'kaupang-stock-movements']), 'type="number" name="actor_id"') === false;

// Actor filter lists staff only; a checkout must not become an actor.
$cust = get_users(['role' => 'customer', 'number' => 1, 'fields' => ['ID', 'display_name']]);
if ($cust) {
    $h = ks_render(\Kaupang\Stock\Admin\MovementsPage::class, ['page' => 'kaupang-stock-movements']);
    $checks['moves: no customers in actor select'] = strpos($h, '<option value="' . (int) $cust[0]->ID . '"') === false;
    wp_set_current_user((int) $cust[0]->ID);
    $checks['actor: customer records as system'] = \Kaupang\Stock\Settings::actorId() === 0;
    wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1])[0]->ID);
}
$checks['moves: date presets present'] = strpos(ks_render(\Kaupang\Stock\Admin\MovementsPage::class, ['page' => 'kaupang-stock-movements']), 'data-ks-daterange') !== false;

// Status: chips precede the figure so numbers right-align.
$h = ks_render(\Kaupang\Stock\Admin\StatusPage::class, ['page' => 'kaupang-stock']);
$checks['status: chip before number'] = preg_match('/<td class="column-available[^"]*">\s*<span class="ks-chip[^"]*">[^<]*<\/span> -?\d/', $h) === 1
    || strpos($h, 'ks-chip') === false;

// Note composer: preset label is the note; Other needs text; text alone still works.
$_POST = ['preset' => 'damaged', 'note' => ''];
$checks['note: preset alone']                = \Kaupang\Stock\Admin\Screen::adjustNote() === __('Damaged', 'kaupang-stock');
$_POST = ['preset' => 'damaged', 'note' => 'lid cracked'];
$checks['note: preset + text']               = \Kaupang\Stock\Admin\Screen::adjustNote() === __('Damaged', 'kaupang-stock') . ': lid cracked';
$_POST = ['preset' => 'other', 'note' => ''];
$checks['note: other without text = empty']  = \Kaupang\Stock\Admin\Screen::adjustNote() === '';
$_POST = ['note' => 'plain'];
$checks['note: plain text (no preset)']      = \Kaupang\Stock\Admin\Screen::adjustNote() === 'plain';

// Purchasing: the router reaches every per-view class; status labels are English sources with nb_NO translations.
$poOn = static fn($v) => array_merge((array) $v, ['stock_enabled' => true, 'po_enabled' => true]);
add_filter('option_kaupang_stock_settings', $poOn);
\Kaupang\Stock\Settings::flushCache();
foreach (['list' => 'page-title-action', 'inbound' => 'Nothing incoming|<td', 'suppliers' => 'kaupang_stock_save_supplier', 'edit' => 'kaupang_stock_save_po'] as $view => $marker) {
    $h = ks_render(\Kaupang\Stock\Admin\PurchasingPage::class, ['page' => 'kaupang-stock-purchasing', 'view' => $view, 'po' => 'new']);
    $checks["purchasing $view: balanced markup + rendered"] = ks_balanced($h) && preg_match('/' . $marker . '/', $h) === 1;
}
remove_filter('option_kaupang_stock_settings', $poOn);
\Kaupang\Stock\Settings::flushCache();
$switched = switch_to_locale('en_US'); // false when already en_US (WP-CLI on GridPane)
$checks['purchasing: status label English source'] = \Kaupang\Stock\Purchasing\PurchaseOrders::statusLabel('partial') === 'Partially received';
$switched && restore_previous_locale();
switch_to_locale('nb_NO');
$checks['purchasing: status label nb_NO']  = \Kaupang\Stock\Purchasing\PurchaseOrders::statusLabel('partial') === 'Delvis mottatt';
restore_previous_locale();

// Menu (stock#11): every page under WooCommerce, no top-level menu; translated labels; disabled = status + settings.
$menuRun = static function (bool $enabled, string $locale): array {
    $f = static fn($v) => array_merge((array) $v, ['stock_enabled' => $enabled, 'po_enabled' => true, 'counting_enabled' => true, 'costing_enabled' => true]);
    add_filter('option_kaupang_stock_settings', $f);
    \Kaupang\Stock\Settings::flushCache();
    $switched = switch_to_locale($locale);
    [$keep, $GLOBALS['menu'], $GLOBALS['submenu']] = [[$GLOBALS['menu'] ?? [], $GLOBALS['submenu'] ?? []], [], []];
    \Kaupang\Stock\Admin\Menu::menu();
    $out = [array_column($GLOBALS['submenu']['woocommerce'] ?? [], 0, 2), array_keys($GLOBALS['submenu']), $GLOBALS['menu']];
    [$GLOBALS['menu'], $GLOBALS['submenu']] = $keep;
    $switched && restore_previous_locale();
    remove_filter('option_kaupang_stock_settings', $f);
    \Kaupang\Stock\Settings::flushCache();
    return $out;
};
$slugs = ['kaupang-stock', 'kaupang-stock-movements', 'kaupang-stock-valuation', 'kaupang-stock-counts', 'kaupang-stock-purchasing', 'kaupang-stock-settings'];
[$en, $parents, $top] = $menuRun(true, 'en_US');
$checks['menu: every page under woocommerce, slugs kept'] = array_keys($en) === $slugs && $parents === ['woocommerce'] && $top === [];
$checks['menu: English labels say stock'] = $en['kaupang-stock'] === 'Stock status' && $en['kaupang-stock-settings'] === 'Stock settings';
[$nb] = $menuRun(true, 'nb_NO');
$checks['menu: nb_NO labels'] = array_values($nb) === ['Lagerstatus', 'Lagerbevegelser', 'Lagerverdi', 'Varetelling', 'Innkjøp', 'Lagerinnstillinger'];
[$off] = $menuRun(false, 'en_US');
$checks['menu: disabled keeps status pointer + settings'] = array_keys($off) === ['kaupang-stock', 'kaupang-stock-settings'];

// Screen Options (stock#11): hidden status columns follow the screen from toplevel_page_ to its WooCommerce id.
$uid    = get_current_user_id();
$oldKey = 'managetoplevel_page_kaupang-stockcolumnshidden';
$newKey = 'manage' . \Kaupang\Stock\Admin\Menu::SCREEN_STATUS . 'columnshidden';
$had    = [metadata_exists('user', $uid, $oldKey) ? get_user_meta($uid, $oldKey, true) : null, metadata_exists('user', $uid, $newKey) ? get_user_meta($uid, $newKey, true) : null];
$col    = array_keys(\Kaupang\Stock\Admin\StatusPage::columns())[2];
try {
    delete_user_meta($uid, $newKey);
    update_user_meta($uid, $oldKey, [$col]);
    \Kaupang\Stock\Schema::migrateScreenOptions();
    \Kaupang\Stock\Schema::migrateScreenOptions(); // idempotent
    $h = ks_render(\Kaupang\Stock\Admin\StatusPage::class, ['page' => 'kaupang-stock']);
    $checks['screen options: hidden columns migrated'] = get_user_meta($uid, $newKey, true) === [$col] && !metadata_exists('user', $uid, $oldKey);
    $checks['screen options: status page honours them'] = preg_match('/<th scope="col" id="' . $col . '" class="[^"]* hidden"/', $h) === 1;
    update_user_meta($uid, $oldKey, ['product']);
    \Kaupang\Stock\Schema::migrateScreenOptions();
    $checks['screen options: a value on the new id wins'] = get_user_meta($uid, $newKey, true) === [$col] && !metadata_exists('user', $uid, $oldKey);
} finally {
    delete_user_meta($uid, $oldKey);
    delete_user_meta($uid, $newKey);
    $had[0] !== null && update_user_meta($uid, $oldKey, $had[0]);
    $had[1] !== null && update_user_meta($uid, $newKey, $had[1]);
}

foreach ($checks as $k => $ok) { echo ($ok ? 'PASS' : 'FAIL') . " $k\n"; }
exit(in_array(false, $checks, true) ? 1 : 0);
