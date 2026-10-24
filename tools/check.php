<?php
/**
 * Loading check for Kaupang Stock (conventions §5, docs/loading Tracks 3/4/6). Exits non-zero on failure.
 *
 *   sudo -u myrvann wp --path=/var/www/staging.myrvann.no/htdocs \
 *     eval-file /var/www/staging.myrvann.no/htdocs/wp-content/plugins/kaupang-stock/tools/check.php
 *
 * (or `gp wp <site> eval-file …`; never as root). eval-file resolves a relative path against the shell's cwd.
 *
 * Proves, in a non-admin (CLI) request:
 *  - boot loads Plugin only (+ the WP-CLI command class), hooks nothing on public init;
 *  - settings + schema version are not autoloaded;
 *  - every Woo stock/order event the plugin listens to is registered with a class-string handler and reaches it:
 *    rich hooks get as far as Ledger::record() and are stopped by the ledger's own fractional-delta guard (no row
 *    written), claims/dirty marks land in the request registry (then cleared, so the shutdown absorber trues up
 *    nothing), the absorber arms itself only on the first dirty product, the COGS bridge arms only with its flag;
 *  - handlers do nothing while stock_enabled is off;
 *  - the reconcile hook has its callback, the AS ensure hook and the settings-write hooks schedule it (unique) only
 *    when enabled; nothing is scheduled by this run (pre_as_schedule_recurring_action is short-circuited);
 *  - REST routes register on rest_api_init with lazy callbacks and refuse while disabled;
 *  - admin assets stay off a foreign screen (Pattern E).
 * Only this plugin's callbacks are called, never the whole Woo action (kaupang-fiken and others listen to the same
 * stock events and would push to external systems). Flags are switched through option filters; nothing is written.
 * The storefront side is proven by `wp kaupang-assets audit --plugins=kaupang-stock --budget`.
 *
 * No declare(strict_types=1): eval-file evals the source. $GLOBALS, not `global`: eval-file runs inside a method.
 */

PHP_SAPI === 'cli' || exit;
defined('ABSPATH') || exit;

use Kaupang\Stock\Admin\Menu;
use Kaupang\Stock\Costing\WcCogsBridge;
use Kaupang\Stock\Observe\Absorber;
use Kaupang\Stock\Observe\DirtyRegistry;
use Kaupang\Stock\Observe\Observer;
use Kaupang\Stock\Plugin;
use Kaupang\Stock\Reconcile\Reconciler;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\PageCache;

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? (string) wp_parse_url(home_url(), PHP_URL_HOST); // wp-fail2ban under CLI
$GLOBALS['ks_fails'] = 0;

function ks_check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : " -- {$detail}") . "\n";
    $GLOBALS['ks_fails'] += $ok ? 0 : 1;
}

/** This plugin's callbacks on a hook: [[callable, accepted_args, priority], …]. */
function ks_own(string $hook): array
{
    $own = [];
    foreach ($GLOBALS['wp_filter'][$hook]->callbacks ?? [] as $prio => $cbs) {
        foreach ($cbs as $cb) {
            $fn = $cb['function'];
            if (is_array($fn) && is_string($fn[0]) && strpos(ltrim($fn[0], '\\'), 'Kaupang\\Stock\\') === 0) {
                $own[] = [$fn, (int) $cb['accepted_args'], (int) $prio];
            }
        }
    }
    return $own;
}

/** Call only this plugin's callbacks on $hook, filter-style (first arg chained). */
function ks_fire(string $hook, ...$args)
{
    foreach (ks_own($hook) as [$fn, $accepted]) {
        $ret = call_user_func_array($fn, array_slice($args, 0, $accepted));
        if ($args !== []) {
            $args[0] = $ret ?? $args[0];
        }
    }
    return $args[0] ?? null;
}

/** Swap settings for the rest of a block (option filter; nothing written). */
function ks_settings(array $patch): callable
{
    $filter = static fn($v) => array_replace(is_array($v) ? $v : [], $patch);
    add_filter('option_' . Plugin::SETTINGS_OPTION, $filter);
    Settings::flushCache();
    return static function () use ($filter): void {
        remove_filter('option_' . Plugin::SETTINGS_OPTION, $filter);
        Settings::flushCache();
    };
}

echo "Kaupang Stock loading check\n";

// --- 5A: boot loads Plugin only (runs first: later checks load modules) ---------------------------------------
$loaded = array_values(array_filter(get_declared_classes(), static fn(string $c): bool => strpos($c, 'Kaupang\\Stock\\') === 0));
$extra  = array_diff($loaded, [Plugin::class, Kaupang\Stock\Cli\Command::class]);
ks_check('outside wp-admin, boot loads only Plugin (+ CLI command)', $extra === [], implode(', ', $extra));
ks_check('nothing hooked on public init', ks_own('init') === []);
ks_check('absorber not armed before any stock moved', has_action('shutdown', [Absorber::class, 'run']) === false);
ks_check('Plugin names match the classes', Plugin::SETTINGS_OPTION === Settings::OPTION_KEY && Plugin::RECONCILE_HOOK === Reconciler::HOOK);

// --- Track 4: autoload ---------------------------------------------------------------------------------------
$rows = $GLOBALS['wpdb']->get_results("SELECT option_name, autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name IN ('kaupang_stock_settings', 'kaupang_stock_schema_version')", OBJECT_K);
foreach (['kaupang_stock_settings', Schema::VERSION_OPTION] as $opt) {
    $al = isset($rows[$opt]) ? (string) $rows[$opt]->autoload : 'missing';
    ks_check("$opt not autoloaded", in_array($al, ['off', 'no', 'auto-off', 'missing'], true), "autoload=$al (load any wp-admin page to run the v6 migration)");
}
ks_check('autoload default for a core-created settings row is off', apply_filters('wp_default_autoload_value', null, Plugin::SETTINGS_OPTION, [], '') === false);

// --- Woo events: registered with lazy handlers --------------------------------------------------------------
$events = [
    'woocommerce_reduce_order_item_stock'                 => [Observer::class, 'onReduceOrderItemStock'],
    'woocommerce_restore_order_item_stock'                => [Observer::class, 'onRestoreOrderItemStock'],
    'woocommerce_restock_refunded_item'                   => [Observer::class, 'onRestockRefundedItem'],
    'woocommerce_prevent_adjust_line_item_product_stock'  => [Observer::class, 'claimOrderItemEdit'],
    'woocommerce_product_quick_edit_save'                 => [Observer::class, 'claimAdminEdit'],
    'woocommerce_product_bulk_edit_save'                  => [Observer::class, 'claimAdminEdit'],
    'woocommerce_admin_process_product_object'            => [Observer::class, 'claimAdminEdit'],
    'woocommerce_admin_process_variation_object'          => [Observer::class, 'claimAdminEdit'],
    'woocommerce_rest_insert_product_object'              => [Observer::class, 'claimRest'],
    'woocommerce_rest_insert_product_variation_object'    => [Observer::class, 'claimRest'],
    'woocommerce_product_import_inserted_product_object'  => [Observer::class, 'claimImport'],
    'woocommerce_product_set_stock'                       => [Observer::class, 'markDirtyProduct'],
    'woocommerce_variation_set_stock'                     => [Observer::class, 'markDirtyProduct'],
    'woocommerce_updated_product_stock'                   => [Observer::class, 'markDirtyId'],
    'kaupang/stock/claim'                                 => [Observer::class, 'claimExternal'],
    'kaupang/stock/movement_recorded'                     => [Kaupang\Stock\Costing\Costing::class, 'onMovement'],
    'woocommerce_calculated_order_item_cogs_value'        => [WcCogsBridge::class, 'serveItemCogs'],
    'woocommerce_payment_complete'                        => [WcCogsBridge::class, 'onPaid'],
    'woocommerce_order_status_changed'                    => [WcCogsBridge::class, 'onPaid'],
    'woocommerce_product_set_stock_status'                => [PageCache::class, 'onStatus'],
    'woocommerce_variation_set_stock_status'              => [PageCache::class, 'onStatus'],
    'woocommerce_product_before_set_stock'                => [PageCache::class, 'rememberStock'],
    'woocommerce_variation_before_set_stock'              => [PageCache::class, 'rememberStock'],
];
foreach ($events as $hook => $cb) {
    ks_check("$hook → {$cb[1]}", has_action($hook, $cb) !== false);
}
ks_check('COGS bridge runs after the costing fold', has_action('kaupang/stock/movement_recorded', [WcCogsBridge::class, 'onMovement']) === 20
    && has_action('kaupang/stock/movement_recorded', [Kaupang\Stock\Costing\Costing::class, 'onMovement']) === 10);

// --- Woo events reach their handlers (ledger on) --------------------------------------------------------------
$productId = (int) (wc_get_products(['status' => 'publish', 'type' => 'simple', 'limit' => 1, 'return' => 'ids'])[0] ?? 0);
ks_check('a published simple product to probe with (read only)', $productId > 0);
$fake = new WC_Product_Simple();
$fake->set_id(999999999); // never a real product: a slipped-through ledger write would be refused, not recorded

// Capture (and swallow) this plugin's log lines: the rich hooks' proof is the ledger refusing a fractional delta.
$GLOBALS['ks_log'] = [];
$logSpy = static function ($message, $level, $context) {
    if (($context['source'] ?? '') === 'kaupang-stock') {
        $GLOBALS['ks_log'][] = (string) $message;
        return null;
    }
    return $message;
};
add_filter('woocommerce_logger_log_message', $logSpy, 10, 3);
$reached = static function (string $context): bool {
    foreach ($GLOBALS['ks_log'] as $line) {
        if (strpos($line, 'observer_error') === 0 && strpos($line, '"context":"' . $context . '"') !== false && strpos($line, 'Fractional') !== false) {
            return true;
        }
    }
    return false;
};

$restore = ks_settings(['stock_enabled' => true]);
ks_check('precondition: integer stock amounts (the probe relies on the fractional guard)', wc_is_stock_amount_integer());

ks_fire('woocommerce_reduce_order_item_stock', new WC_Order_Item_Product(), ['product' => $fake, 'from' => 1, 'to' => 0.5], null);
ks_check('reduce_order_item_stock reaches Ledger::record (sale)', $reached('observe_reduce'), implode(' | ', $GLOBALS['ks_log']));

$item = new WC_Order_Item_Product();
$item->set_product_id($productId);
ks_fire('woocommerce_restore_order_item_stock', $item, 1.5, 1, null);
ks_check('restore_order_item_stock reaches Ledger::record (sale_restore)', $reached('observe_restore'));

ks_fire('woocommerce_restock_refunded_item', $fake->get_id(), 1, 1.5, new WC_Order(), $fake);
ks_check('restock_refunded_item reaches Ledger::record (refund_restock)', $reached('observe_refund_restock'));

$prevent = ks_fire('woocommerce_prevent_adjust_line_item_product_stock', 'sentinel', $item, 2);
$claim = DirtyRegistry::consumeClaim($productId);
ks_check('order-item edit files an order_edit claim, filter value unchanged', $prevent === 'sentinel' && ($claim['reason'] ?? '') === 'order_edit');

$real = wc_get_product($productId);
foreach ([
    'woocommerce_product_quick_edit_save'                => 'admin_edit',
    'woocommerce_admin_process_product_object'           => 'admin_edit',
    'woocommerce_rest_insert_product_object'             => 'rest',
    'woocommerce_product_import_inserted_product_object' => 'import',
] as $hook => $reason) {
    ks_fire($hook, $real);
    $claim = DirtyRegistry::consumeClaim($real->get_stock_managed_by_id());
    ks_check("$hook files a $reason claim", ($claim['reason'] ?? '') === $reason);
}
ks_fire('kaupang/stock/claim', $productId, ['via' => 'kscheck']);
$claim = DirtyRegistry::consumeClaim($productId);
ks_check('kaupang/stock/claim seam files an external claim', ($claim['reason'] ?? '') === 'external' && ($claim['via'] ?? '') === 'kscheck');

// Dirty marking: call only Observer's callback (PageCache shares the hook and would purge the page cache).
$own = array_filter(ks_own('woocommerce_product_set_stock'), static fn($c) => $c[0][0] === Observer::class);
foreach ($own as [$fn]) {
    call_user_func($fn, $fake);
}
call_user_func([Observer::class, 'markDirtyId'], 999999998);
ks_check('set_stock / updated_product_stock mark the products dirty', DirtyRegistry::takeDirty() == [999999999, 999999998]);
ks_check('the first dirty product arms the shutdown absorber (priority 20)', has_action('shutdown', [Absorber::class, 'run']) === 20);
ks_check('dirty set cleared again: the absorber trues up nothing', !DirtyRegistry::hasDirty());
$restore();

// Ledger off: every handler returns before recording anything.
$restore = ks_settings(['stock_enabled' => false]);
$GLOBALS['ks_log'] = [];
ks_fire('woocommerce_reduce_order_item_stock', new WC_Order_Item_Product(), ['product' => $fake, 'from' => 1, 'to' => 0.5], null);
ks_fire('woocommerce_product_set_stock', $fake);
ks_fire('kaupang/stock/claim', $productId, ['via' => 'kscheck']);
ks_check('ledger off: no record attempt, no dirty mark, no claim', $GLOBALS['ks_log'] === [] && !DirtyRegistry::hasDirty() && DirtyRegistry::consumeClaim($productId) === null);
$restore();

// COGS bridge: arms its shutdown restamp only with its flag (and WC's COGS feature) on. Order id never exists.
$cogsOn = static fn() => 'yes';
add_filter('pre_option_woocommerce_feature_cost_of_goods_sold_enabled', $cogsOn);
$restore = ks_settings(['stock_enabled' => true, 'costing_enabled' => true, 'cogs_order_meta_enabled' => false]);
ks_fire('woocommerce_order_status_changed', 999999997, 'pending', 'processing', null);
ks_check('COGS flag off: status change does not arm the restamp', has_action('shutdown', [WcCogsBridge::class, 'stampTouched']) === false);
ks_check('COGS flag off: item COGS filter returns core value', ks_fire('woocommerce_calculated_order_item_cogs_value', 12.5, new WC_Order_Item_Product()) === 12.5);
$restore();
$restore = ks_settings(['stock_enabled' => true, 'costing_enabled' => true, 'cogs_order_meta_enabled' => true]);
ks_fire('woocommerce_payment_complete', 999999997);
ks_check('COGS flag on: payment_complete arms the restamp (priority 30)', has_action('shutdown', [WcCogsBridge::class, 'stampTouched']) === 30);
$restore();
remove_filter('pre_option_woocommerce_feature_cost_of_goods_sold_enabled', $cogsOn);
remove_filter('woocommerce_logger_log_message', $logSpy, 10);

// --- Reconcile schedule (stock#13) -------------------------------------------------------------------------
ks_check('reconcile hook has its callback', has_action(Plugin::RECONCILE_HOOK, [Reconciler::class, 'runScheduled']) !== false);
ks_check('AS ensure hook → ensureScheduled', has_action('action_scheduler_ensure_recurring_actions', [Reconciler::class, 'ensureScheduled']) !== false);
ks_check('settings writes → ensureScheduled after the watcher', has_action('update_option_' . Plugin::SETTINGS_OPTION, [Reconciler::class, 'ensureScheduled']) === 20
    && has_action('add_option_' . Plugin::SETTINGS_OPTION, [Reconciler::class, 'ensureScheduled']) === 20
    && has_action('update_option_' . Plugin::SETTINGS_OPTION, [Kaupang\Stock\Admin\SettingsPage::class, 'optionUpdated']) === 10);

$GLOBALS['ks_sched'] = [];
$schedSpy = static function ($pre, $ts, $interval, $hook, $args, $group, $priority, $unique) {
    if ($hook !== Plugin::RECONCILE_HOOK) {
        return $pre;
    }
    $GLOBALS['ks_sched'][] = compact('interval', 'group', 'unique');
    return 0; // never schedule from the check
};
add_filter('pre_as_schedule_recurring_action', $schedSpy, 10, 8);

$restore = ks_settings(['stock_enabled' => false]);
ks_fire('action_scheduler_ensure_recurring_actions');
ks_fire('update_option_' . Plugin::SETTINGS_OPTION, [], []);
ks_check('ledger off: nothing scheduled', $GLOBALS['ks_sched'] === []);
$before = get_option(Reconciler::REPORT_OPTION);
ks_fire(Plugin::RECONCILE_HOOK);
ks_check('ledger off: a leftover reconcile action records no report', get_option(Reconciler::REPORT_OPTION) == $before);
$restore();

$restore = ks_settings(['stock_enabled' => true]);
$already = as_has_scheduled_action(Plugin::RECONCILE_HOOK, [], Reconciler::AS_GROUP);
ks_fire('action_scheduler_ensure_recurring_actions');
$s = $GLOBALS['ks_sched'][0] ?? null;
ks_check(
    'ledger on: the ensure hook schedules the daily run (unique) or finds it scheduled',
    $already ? $GLOBALS['ks_sched'] === [] : ($s !== null && $s['unique'] === true && (int) $s['interval'] === DAY_IN_SECONDS && $s['group'] === Reconciler::AS_GROUP),
    wp_json_encode(['already' => $already, 'calls' => $GLOBALS['ks_sched']])
);
echo '       (' . ($already ? 'already scheduled on this site' : 'not yet scheduled: the call was captured, not made') . ")\n";
$restore();
remove_filter('pre_as_schedule_recurring_action', $schedSpy, 10);

// --- REST ----------------------------------------------------------------------------------------------------
ks_check('REST routes registered from Plugin on rest_api_init', has_action('rest_api_init', [Plugin::class, 'restRoutes']) !== false);
$routes = rest_get_server()->get_routes(); // fires rest_api_init
foreach (['/movements', '/count-lines/(?P<id>\d+)', '/count-lines/(?P<id>\d+)/override', '/counts/(?P<id>\d+)/apply', '/products', '/supplier-search', '/receive', '/po/(?P<po>\d+)/lines', '/po/(?P<po>\d+)/lines/(?P<line>\d+)'] as $r) {
    ks_check("route kaupang-stock/v1$r", isset($routes['/' . Plugin::REST_NS . $r]));
}
ks_check('registering routes does not load the controller', !class_exists(Kaupang\Stock\Rest\Controller::class, false));
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 0));
$restore = ks_settings(['stock_enabled' => false]);
ks_check('ledger off: routes refuse even an administrator', Kaupang\Stock\Rest\Controller::can() === false);
$restore();
$restore = ks_settings(['stock_enabled' => true]);
ks_check('ledger on: an administrator may use the routes', Kaupang\Stock\Rest\Controller::can() === true);
$restore();

// --- Pattern E: admin assets stay off a foreign screen -----------------------------------------------------
$restore = ks_settings(['stock_enabled' => true, 'counting_enabled' => true, 'po_enabled' => true]);
Menu::register(); // CLI is not wp-admin: register the admin module by hand, as Plugin::boot() does behind its gate
$own = ks_own('admin_enqueue_scripts');
$classes = array_map(static fn($c) => $c[0][0], $own);
ks_check('Menu, CountsPage, PurchasingPage hook admin_enqueue_scripts', !array_diff([Menu::class, Kaupang\Stock\Admin\CountsPage::class, Kaupang\Stock\Admin\PurchasingPage::class], $classes), implode(', ', $classes));
foreach ($own as [$fn]) {
    call_user_func($fn, 'index.php');
}
$queued = array_merge(wp_scripts()->queue, wp_styles()->queue);
$leaked = array_values(array_filter($queued, static fn(string $h): bool => strpos($h, 'kaupang-stock') === 0));
ks_check('no kaupang-stock asset on a foreign screen (index.php)', $leaked === [], implode(', ', $leaked));
foreach ($own as [$fn]) {
    call_user_func($fn, 'toplevel_page_kaupang-stock');
}
ks_check('own screen gets the admin assets', in_array(Menu::SCRIPT_HANDLE, wp_scripts()->queue, true));
$restore();

echo $GLOBALS['ks_fails'] === 0 ? "All checks passed.\n" : "{$GLOBALS['ks_fails']} check(s) failed.\n";
exit($GLOBALS['ks_fails'] === 0 ? 0 : 1);
