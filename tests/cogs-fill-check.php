<?php
PHP_SAPI === 'cli' || exit;
/**
 * Order-COGS check: every channel gets WC-native COGS, none is understated.
 *
 *  (a) an order built the BjornTech Zettle way (bare wc_add_order_item() +
 *      _product_id/_qty meta afterwards, then completed + payment_complete)
 *      gets COGS from the product's WC value;
 *  (b) an order that already carries COGS is not recalculated when it turns
 *      paid, and a filled order is not recalculated again (idempotent);
 *  (c) a line whose consumptions are only partly costed is not understated:
 *      the uncosted qty is priced at the line's blended costed cost, and a
 *      NULL provisional settled by a costed backfill nets to zero uncosted.
 *
 *   sudo -u myrvann wp --path=/var/www/staging.myrvann.no/htdocs eval-file \
 *       wp-content/plugins/kaupang-stock/tests/cogs-fill-check.php
 *
 * WRITES (staging/dev only, refuses production): throwaway orders (deleted
 * again), a durable KSTEST-COGS-FILL product (not stock-managed), and one fresh
 * draft product per run for (c), whose ledger is zeroed and which is then
 * deleted (its zero balance row stays as an immutable out-of-scope audit row;
 * an uncosted layer needs a product with no cost history, so it can't be
 * reused). The WC COGS feature and the bridge flag are switched on through
 * option filters for this run only; nothing is written to either option.
 * Order status/payment callbacks are detached while it runs (no Fiken, mail,
 * CloudConnect or automations). Exits non-zero on failure.
 */

// No declare(strict_types=1): eval-file evals the source.

use Kaupang\Stock\Costing\Costing;
use Kaupang\Stock\Costing\WcCogsBridge;
use Kaupang\Stock\Ledger\Balances;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Settings;

if (wp_get_environment_type() === 'production') {
    fwrite(STDERR, "Refusing to run in production (creates orders and ledger movements).\n");
    exit(1);
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? (string) wp_parse_url(home_url(), PHP_URL_HOST); // wp-fail2ban under CLI

$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . " — $label" . ($ok || $detail === '' ? '' : " ($detail)") . "\n";
    $fail += $ok ? 0 : 1;
};

if (!Costing::enabled()) {
    echo "SKIP — needs kaupang-stock stock_enabled + costing_enabled on this site\n";
    exit(0);
}

// Scope: WC COGS feature + bridge flag on for this run only.
$cogsOn  = static fn () => 'yes';
$flagsOn = static fn ($v) => array_replace(is_array($v) ? $v : [], ['cogs_order_meta_enabled' => true]);
add_filter('pre_option_woocommerce_feature_cost_of_goods_sold_enabled', $cogsOn);
add_filter('option_' . Settings::OPTION_KEY, $flagsOn);
add_filter('pre_wp_mail', '__return_false');
Settings::flushCache();

// Detach order status/payment/new-order/stock-reduced callbacks (Woo's own
// caches stay), then register only the bridge.
$quiet = static function (): array {
    $removed = [];
    $hooks = ['woocommerce_pre_payment_complete', 'woocommerce_payment_complete', 'woocommerce_new_order', 'woocommerce_reduce_order_stock'];
    foreach ($GLOBALS['wp_filter'] as $h => $hook) {
        if (!str_starts_with((string) $h, 'woocommerce_order_status_') && !in_array($h, $hooks, true)) {
            continue;
        }
        foreach ($hook->callbacks as $prio => $cbs) {
            foreach ($cbs as $cb) {
                $f = $cb['function'];
                if (is_array($f) && is_object($f[0]) && str_starts_with(get_class($f[0]), 'Automattic\\WooCommerce\\Caches\\')) {
                    continue;
                }
                remove_filter((string) $h, $f, $prio);
                $removed[] = [(string) $h, $f, (int) $prio, (int) $cb['accepted_args']];
            }
        }
    }
    return $removed;
};
$removed = $quiet();
WcCogsBridge::register();

$orders   = [];
$freshId  = 0;
$run      = strtolower(wp_generate_password(8, false, false));
$fresh    = static fn (int $id) => wc_get_order($id); // a new instance, never the caller's object

try {
    $check('WC COGS feature is on in this run', WcCogsBridge::wcCogsFeatureEnabled());

    // Durable fill product: not stock-managed, WC COGS value 40.00 kr.
    $fillId = (int) wc_get_product_id_by_sku('KSTEST-COGS-FILL');
    if ($fillId <= 0) {
        $p = new WC_Product_Simple();
        $p->set_name('KSTEST COGS fill');
        $p->set_sku('KSTEST-COGS-FILL');
        $p->set_status('draft');
        $p->set_regular_price('100');
        $p->set_manage_stock(false);
        $fillId = (int) $p->save();
    }
    $setCost = static function (int $id, float $kr): void {
        $p = wc_get_product($id);
        $p->set_cogs_value($kr);
        $p->save();
    };
    $setCost($fillId, 40.0);

    /* (a) BjornTech-style order -------------------------------------------- */
    $order = wc_create_order(['status' => 'pending']);
    $order->set_created_via('kstest-zettle-like');
    $order->save();
    $oid = $order->get_id();
    $orders[] = $oid;
    $itemId = wc_add_order_item($oid, ['order_item_name' => 'KSTEST COGS fill', 'order_item_type' => 'line_item']);
    wc_add_order_item_meta($itemId, '_product_id', $fillId, true);
    wc_add_order_item_meta($itemId, '_variation_id', 0, true);
    wc_add_order_item_meta($itemId, '_qty', 3, true);
    wc_add_order_item_meta($itemId, '_line_subtotal', 300, true);
    wc_add_order_item_meta($itemId, '_line_total', 300, true);

    $order = $fresh($oid);
    $check('(a) precondition: the bare-item order has no COGS', (float) $order->get_cogs_total_value() === 0.0);
    $order->set_date_paid(time());
    $order->set_status('completed');
    $order->save();
    do_action('woocommerce_payment_complete', $oid);
    WcCogsBridge::stampTouched(); // what shutdown runs

    $order = $fresh($oid);
    $item  = $order->get_item($itemId);
    $check('(a) completed bare-item order got line COGS 3 × 40.00', $item && abs($item->get_cogs_value() - 120.0) < 0.001, $item ? (string) $item->get_cogs_value() : 'no item');
    $check('(a) and order COGS total 120.00', abs($order->get_cogs_total_value() - 120.0) < 0.001, (string) $order->get_cogs_total_value());

    /* (b) no recalculation of an order that has COGS ------------------------ */
    $setCost($fillId, 70.0); // "today's" cost differs from the sale-day cost
    $order->set_status('processing');
    $order->save();
    $order->set_status('completed');
    $order->save();
    do_action('woocommerce_payment_complete', $oid);
    WcCogsBridge::stampTouched();
    $order = $fresh($oid);
    $check('(b) filled order is not recalculated on later paid events', abs($order->get_cogs_total_value() - 120.0) < 0.001, (string) $order->get_cogs_total_value());

    // Checkout-style order: COGS calculated at creation (40.00), then paid after the cost changed.
    $setCost($fillId, 40.0);
    $order = wc_create_order(['status' => 'pending']);
    $order->add_product(wc_get_product($fillId), 2);
    $order->calculate_totals();
    $order->save();
    $cid = $order->get_id();
    $orders[] = $cid;
    $check('(b) precondition: checkout-style order has COGS 80.00', abs($fresh($cid)->get_cogs_total_value() - 80.0) < 0.001);
    $setCost($fillId, 70.0);
    $order = $fresh($cid);
    $order->payment_complete('kstest-' . $run);
    WcCogsBridge::stampTouched();
    $order = $fresh($cid);
    $line  = current($order->get_items('line_item'));
    $check('(b) paid order with existing COGS keeps 80.00 (not today\'s 140.00)', abs($order->get_cogs_total_value() - 80.0) < 0.001, (string) $order->get_cogs_total_value());
    $check('(b) and its line keeps 80.00', $line && abs($line->get_cogs_value() - 80.0) < 0.001, $line ? (string) $line->get_cogs_value() : 'no line');

    /* (c) partially-costed consumptions ------------------------------------- */
    $p = new WC_Product_Simple();
    $p->set_name('KSTEST COGS partial ' . $run);
    $p->set_sku('KSTEST-COGS-PART-' . $run);
    $p->set_status('draft');
    $p->set_manage_stock(true);
    $p->set_stock_quantity(0);
    $freshId = (int) $p->save();
    $loc = Balances::defaultLocationId();

    $saleOrder = static function (int $productId, int $qty) use (&$orders): WC_Order {
        $o = wc_create_order(['status' => 'pending']);
        $o->add_product(wc_get_product($productId), $qty);
        $o->save();
        $orders[] = $o->get_id();
        wc_reduce_stock_levels($o->get_id()); // Woo's sale path → observer → sale movement → fold
        return $o;
    };

    // 1. +1 with no cost and no cost history → NULL-cost layer.
    Ledger::adjust($freshId, 1, 'KSTEST COGS uncosted in', null, 'kstest:cogs:' . $run . ':in0', $loc);
    // 2. sell 2: fifo 1 from the NULL layer + provisional 1 at a NULL estimate.
    $oa = $saleOrder($freshId, 2);
    // 3. +1 at 50.00 kr → true-up: prov_reversal(-1, NULL) + backfill(+1, 5000).
    Costing::stash('kstest:cogs:' . $run . ':in1', 5000);
    Ledger::adjust($freshId, 1, 'KSTEST COGS costed in', null, 'kstest:cogs:' . $run . ':in1', $loc);
    // 4. +1 at 70.00 kr, sell 1 → fully costed line at 7000.
    Costing::stash('kstest:cogs:' . $run . ':in2', 7000);
    Ledger::adjust($freshId, 1, 'KSTEST COGS costed in 2', null, 'kstest:cogs:' . $run . ':in2', $loc);
    $ob = $saleOrder($freshId, 1);
    Costing::sweep();

    $aItem = (int) current($oa->get_items('line_item'))->get_id();
    $bItem = (int) current($ob->get_items('line_item'))->get_id();
    $kinds = $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare(
        'SELECT CONCAT(c.kind, ":", CAST(c.qty AS SIGNED), ":", COALESCE(c.cost_ore, "NULL")) FROM ' . \Kaupang\Stock\Schema::costConsumptions() . ' c
         JOIN ' . \Kaupang\Stock\Schema::movements() . " m ON m.id = c.movement_id
         WHERE m.ref_type = 'order' AND m.ref_id = %d ORDER BY c.id",
        $oa->get_id()
    ));
    $check('(c) fixture: line A has fifo NULL + provisional NULL + reversal NULL + costed backfill',
        $kinds === ['fifo:1:NULL', 'provisional:1:NULL', 'prov_reversal:-1:NULL', 'backfill:1:5000'], implode(', ', $kinds));
    $aOre = WcCogsBridge::ledgerCogsOreForItem($oa->get_id(), $aItem);
    $check('(c) partially-costed line is 2 × 50.00 = 10000 øre, not the NULL-skipping 5000', $aOre === 10000, var_export($aOre, true));
    $bOre = WcCogsBridge::ledgerCogsOreForItem($ob->get_id(), $bItem);
    $check('(c) fully-costed line is exact: 7000 øre', $bOre === 7000, var_export($bOre, true));

    WcCogsBridge::restampOrder($oa->get_id());
    $oaFresh = $fresh($oa->get_id());
    $aLine   = $oaFresh->get_item($aItem);
    $check('(c) øre meta stamped 10000', (string) $aLine->get_meta(WcCogsBridge::META_ORE, true) === '10000', (string) $aLine->get_meta(WcCogsBridge::META_ORE, true));
    $check('(c) WC-native line COGS 100.00 kr', abs($aLine->get_cogs_value() - 100.0) < 0.001, (string) $aLine->get_cogs_value());
} catch (Throwable $e) {
    $check('unexpected throw', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($orders as $id) {
        $o = wc_get_order($id);
        if ($o) {
            $o->delete(true);
        }
    }
    if ($freshId > 0) {
        try {
            $onHand = Balances::onHand($freshId, Balances::defaultLocationId());
            if (abs($onHand) > 1e-9) {
                Ledger::adjust($freshId, -$onHand, 'KSTEST COGS final zero', null, 'kstest:cogs:' . $run . ':fin', Balances::defaultLocationId());
            }
            Costing::sweep();
            wp_delete_post($freshId, true);
        } catch (Throwable $e) {
            echo 'WARN — partial-cost product cleanup: ' . $e->getMessage() . "\n";
        }
    }
    foreach ($removed as [$h, $f, $prio, $args]) {
        add_filter($h, $f, $prio, $args);
    }
    remove_filter('pre_option_woocommerce_feature_cost_of_goods_sold_enabled', $cogsOn);
    remove_filter('option_' . Settings::OPTION_KEY, $flagsOn);
    Settings::flushCache();
}

echo $fail === 0 ? "OK — cogs-fill-check passed\n" : "FAILED — $fail check(s)\n";
exit($fail === 0 ? 0 : 1);
