<?php
declare(strict_types=1);

namespace Kaupang\Stock;

use Kaupang\Stock\Admin\Menu;
use Kaupang\Stock\Admin\OrderMetaBox;
use Kaupang\Stock\Admin\ProductPanel;
use Kaupang\Stock\Admin\SettingsPage;
use Kaupang\Stock\Costing\Costing;
use Kaupang\Stock\Costing\WcCogsBridge;
use Kaupang\Stock\Observe\Observer;
use Kaupang\Stock\Reconcile\Reconciler;
use Kaupang\Stock\Support\PageCache;

/**
 * Boot wiring — the one file to edit when registering a new module (suite
 * convention, conventions §5A). boot() only adds hooks: every callback is a
 * [Class::class, 'method'] string pair, so a module's file loads when its
 * event fires and a guest page view includes this file and the main file only.
 *
 * Woo stock/order events can fire in any context (checkout, Store API, admin
 * order edits, REST, cron/Action Scheduler, CLI), so they are registered
 * everywhere; the handlers check the flags (Settings::enabled(), costing,
 * page_cache_purge) when they run instead of boot reading settings per request.
 *
 * Modes (§4): shadow (default) = observe + record only, write-through disabled —
 * run this first and prove reconciliation is clean; active = owned operations
 * (adjust/receipt/count apply/reversal) may write through. The observer runs
 * identically in both.
 */
final class Plugin {

    // Names the boot needs live here: reading Settings::OPTION_KEY or
    // Reconciler::HOOK would load those classes on every request. check.php
    // asserts they match.
    public const SETTINGS_OPTION = 'kaupang_stock_settings';
    public const RECONCILE_HOOK  = 'kaupang_stock_reconcile';
    public const REST_NS         = 'kaupang-stock/v1';

    public static function boot(): void {
        \load_plugin_textdomain('kaupang-stock', false, dirname(\plugin_basename(KAUPANG_STOCK_FILE)) . '/languages');

        if (!class_exists('WooCommerce')) {
            \add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>'
                    . \esc_html__('Kaupang Stock requires WooCommerce to be active.', 'kaupang-stock')
                    . '</p></div>';
            });
            return;
        }

        // Version-gated schema upgrades (and the autoload migration) on a
        // code-only deploy: takes effect on the next wp-admin page load.
        \add_action('admin_init', [Schema::class, 'maybeUpgrade']);

        // Settings are read where they are used, not on every request: a row
        // core creates (options.php on a fresh site) starts non-autoloaded too.
        \add_filter('wp_default_autoload_value', static function ($autoload, $option) {
            return $option === self::SETTINGS_OPTION ? false : $autoload;
        }, 10, 2);

        // Enable-transition watcher: seeds the catalog when stock_enabled flips
        // false→true, however the option is written (settings screen, WP-CLI,
        // Settings::update()). Not behind the enabled flag — the transition
        // happens while the plugin is otherwise dormant.
        \add_action('add_option_' . self::SETTINGS_OPTION, [SettingsPage::class, 'optionAdded'], 10, 2);
        \add_action('update_option_' . self::SETTINGS_OPTION, [SettingsPage::class, 'optionUpdated'], 10, 2);

        // Daily invariant check (report-only; healing is operator-initiated).
        // Scheduled from Action Scheduler's daily ensure hook, on every settings
        // write (after the watcher flushed the settings cache) and on
        // activation — never from a request path (stock#13, FE-03).
        \add_action(self::RECONCILE_HOOK, [Reconciler::class, 'runScheduled']);
        \add_action('action_scheduler_ensure_recurring_actions', [Reconciler::class, 'ensureScheduled']);
        \add_action('add_option_' . self::SETTINGS_OPTION, [Reconciler::class, 'ensureScheduled'], 20, 0);
        \add_action('update_option_' . self::SETTINGS_OPTION, [Reconciler::class, 'ensureScheduled'], 20, 0);

        // The shadow side: rich hooks + claims + dirty marking; the shutdown
        // absorber arms itself on the first dirty product. Runs identically in
        // shadow and active mode; every handler returns at once when
        // stock_enabled is off.
        \add_action('woocommerce_reduce_order_item_stock', [Observer::class, 'onReduceOrderItemStock'], 10, 3);
        \add_action('woocommerce_restore_order_item_stock', [Observer::class, 'onRestoreOrderItemStock'], 10, 4);
        \add_action('woocommerce_restock_refunded_item', [Observer::class, 'onRestockRefundedItem'], 10, 5);
        // Claims — observe-only: the prevent filter is returned unchanged.
        \add_filter('woocommerce_prevent_adjust_line_item_product_stock', [Observer::class, 'claimOrderItemEdit'], 10, 3);
        \add_action('woocommerce_product_quick_edit_save', [Observer::class, 'claimAdminEdit']);
        \add_action('woocommerce_product_bulk_edit_save', [Observer::class, 'claimAdminEdit']);
        \add_action('woocommerce_admin_process_product_object', [Observer::class, 'claimAdminEdit']);
        \add_action('woocommerce_admin_process_variation_object', [Observer::class, 'claimAdminEdit'], 10, 1);
        \add_action('woocommerce_rest_insert_product_object', [Observer::class, 'claimRest'], 10, 1);
        \add_action('woocommerce_rest_insert_product_variation_object', [Observer::class, 'claimRest'], 10, 1);
        \add_action('woocommerce_product_import_inserted_product_object', [Observer::class, 'claimImport'], 10, 1);
        // Dirty marking — the pair that fires on EVERY core write path, plus the
        // id-only safety net for direct data-store callers.
        \add_action('woocommerce_product_set_stock', [Observer::class, 'markDirtyProduct']);
        \add_action('woocommerce_variation_set_stock', [Observer::class, 'markDirtyProduct']);
        \add_action('woocommerce_updated_product_stock', [Observer::class, 'markDirtyId']);
        // Cross-plugin claim seam (kaupang-fiken's Fiken stock mirror).
        \add_action('kaupang/stock/claim', [Observer::class, 'claimExternal'], 10, 2);

        // FIFO cost layers — a pure projection over the movement stream; the
        // fold returns at once unless stock_enabled + costing_enabled.
        \add_action('kaupang/stock/movement_recorded', [Costing::class, 'onMovement']);
        // Order-COGS stamping (own cogs_order_meta_enabled flag, checked in each
        // handler). After the costing fold on the same action.
        \add_filter('woocommerce_calculated_order_item_cogs_value', [WcCogsBridge::class, 'serveItemCogs'], 10, 2);
        \add_action('kaupang/stock/movement_recorded', [WcCogsBridge::class, 'onMovement'], 20);
        \add_action('woocommerce_payment_complete', [WcCogsBridge::class, 'onPaid'], 10, 1);
        \add_action('woocommerce_order_status_changed', [WcCogsBridge::class, 'onPaid'], 10, 3);

        // Page-cache purge on stock changes: gated on its own page_cache_purge
        // setting inside the handlers, not on the ledger (runs with
        // stock_enabled off). Both stock write paths fire before_set_stock
        // while get_data() still holds the old quantity.
        \add_action('woocommerce_product_set_stock_status', [PageCache::class, 'onStatus'], 10, 3);
        \add_action('woocommerce_variation_set_stock_status', [PageCache::class, 'onStatus'], 10, 3);
        \add_action('woocommerce_product_before_set_stock', [PageCache::class, 'rememberStock']);
        \add_action('woocommerce_variation_before_set_stock', [PageCache::class, 'rememberStock']);
        \add_action('woocommerce_product_set_stock', [PageCache::class, 'onStock']);
        \add_action('woocommerce_variation_set_stock', [PageCache::class, 'onStock']);

        // kaupang-stock/v1 REST: registered from here so the controller loads
        // only on dispatch; Controller::can() enforces stock_enabled.
        \add_action('rest_api_init', [self::class, 'restRoutes']);

        // CLI is available regardless of the flag: `wp kaupang-stock seed`
        // primes the ledger BEFORE shadow mode is switched on, and status/verify
        // must be able to report on a disabled install.
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('kaupang-stock', \Kaupang\Stock\Cli\Command::class);
        }

        // wp-admin screens (not admin-ajax, which storefront scripts call too):
        // menu + settings always; the feature screens gate themselves on the
        // flags inside Menu. The product/order edit panels need the ledger on.
        if (\is_admin() && !\wp_doing_ajax()) {
            Menu::register();
            if (Settings::enabled()) {
                ProductPanel::register();
                OrderMetaBox::register();
            }
        }
    }

    /**
     * The kaupang-stock/v1 route table: count-line save, receive, movements
     * feed, typeaheads, draft-PO line editing. Every callback is a class-string
     * pair, so nothing loads until a route is dispatched.
     */
    public static function restRoutes(): void {
        $c = Rest\Controller::class;
        \register_rest_route(self::REST_NS, '/movements', [
            'methods'             => 'GET',
            'callback'            => [$c, 'movements'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'product_id' => ['type' => 'integer', 'required' => false],
                'location_id' => ['type' => 'integer', 'required' => false],
                'ref_type'   => ['type' => 'string', 'required' => false],
                'ref_id'     => ['type' => 'integer', 'required' => false],
                'batch'      => ['type' => 'string', 'required' => false],
                'reason'     => ['type' => 'string', 'required' => false],
                'since'      => ['type' => 'string', 'required' => false],
                'page'       => ['type' => 'integer', 'required' => false],
                'per_page'   => ['type' => 'integer', 'required' => false],
            ],
        ]);

        \register_rest_route(self::REST_NS, '/count-lines/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$c, 'countLine'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'id'        => ['type' => 'integer', 'required' => true],
                'counted'   => ['type' => 'number', 'required' => false],
                'increment' => ['type' => 'number', 'required' => false],
                'clear'     => ['type' => 'boolean', 'required' => false],
                'note'      => ['type' => 'string', 'required' => false],
            ],
        ]);

        \register_rest_route(self::REST_NS, '/count-lines/(?P<id>\d+)/override', [
            'methods'             => 'POST',
            'callback'            => [$c, 'countLineOverride'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'note' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::REST_NS, '/counts/(?P<id>\d+)/clear', [
            'methods'             => 'POST',
            'callback'            => [$c, 'countClearLocation'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'id'          => ['type' => 'integer', 'required' => true],
                'location_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'note'        => ['type' => 'string', 'required' => false],
            ],
        ]);

        \register_rest_route(self::REST_NS, '/counts/(?P<id>\d+)/apply', [
            'methods'             => 'POST',
            'callback'            => [$c, 'countApply'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'id'        => ['type' => 'integer', 'required' => true],
                'confirmed' => ['type' => 'boolean', 'required' => false],
            ],
        ]);

        \register_rest_route(self::REST_NS, '/products', [
            'methods'             => 'GET',
            'callback'            => [$c, 'products'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'term' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::REST_NS, '/supplier-search', [
            'methods'             => 'GET',
            'callback'            => [$c, 'supplierSearch'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'q' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::REST_NS, '/receive', [
            'methods'             => 'POST',
            'callback'            => [$c, 'receive'],
            'permission_callback' => [$c, 'can'],
            'args'                => [
                'po_id'       => ['type' => 'integer', 'required' => true],
                'token'       => ['type' => 'string', 'required' => true],
                'lines'       => ['type' => 'object', 'required' => true],
                'occurred_at' => ['type' => 'string', 'required' => false],
                'confirmed'   => ['type' => 'boolean', 'required' => false],
                'costs'       => ['type' => 'object', 'required' => false],
                'location_id' => ['type' => 'integer', 'required' => false],
            ],
        ]);

        // Async draft-PO line editing. Callbacks live in the admin PO editor view
        // (they share its line helpers + row rendering); registered here because
        // that page only boots under is_admin() and REST is not admin context.
        $page = '\\Kaupang\\Stock\\Admin\\Purchasing\\EditView';
        \register_rest_route(self::REST_NS, '/po/(?P<po>\d+)/lines', [
            'methods'             => 'POST',
            'callback'            => [$page, 'restAddLine'],
            'permission_callback' => [$c, 'can'],
        ]);
        \register_rest_route(self::REST_NS, '/po/(?P<po>\d+)/lines/(?P<line>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$page, 'restEditLine'],
                'permission_callback' => [$c, 'can'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$page, 'restDeleteLine'],
                'permission_callback' => [$c, 'can'],
            ],
        ]);
    }
}
