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
$checks['valuation: autosubmit bars']        = substr_count($h, 'data-ks-autosubmit') === 2;

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

foreach ($checks as $k => $ok) { echo ($ok ? 'PASS' : 'FAIL') . " $k\n"; }
exit(in_array(false, $checks, true) ? 1 : 0);
