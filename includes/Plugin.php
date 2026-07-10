<?php
declare(strict_types=1);

namespace Kaupang\Stock;

use Kaupang\Stock\Admin\Menu;
use Kaupang\Stock\Admin\OrderMetaBox;
use Kaupang\Stock\Admin\ProductPanel;
use Kaupang\Stock\Admin\SettingsPage;
use Kaupang\Stock\Observe\Observer;
use Kaupang\Stock\Reconcile\Reconciler;
use Kaupang\Stock\Rest\Controller as RestController;

/**
 * Boot wiring — the one file to edit when registering a new module (suite
 * convention). Everything gates on Settings: a fresh activation shows only
 * Lager → Innstillinger and records nothing until stock_enabled is on.
 *
 * Modes (§4): shadow (default) = observe + record only, write-through disabled —
 * run this first and prove reconciliation is clean; active = owned operations
 * (adjust/receipt/count apply/reversal) may write through. The observer runs
 * identically in both.
 */
final class Plugin {

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

        // Version-gated schema upgrades on a code-only deploy (no reactivation).
        \add_action('admin_init', [Schema::class, 'maybeUpgrade']);

        // Enable-transition watcher: seeds the catalog when stock_enabled flips
        // false→true, however the option is written (settings screen, WP-CLI,
        // Settings::update()). Must sit before the enabled() gate — the
        // transition happens while the plugin is otherwise dormant.
        SettingsPage::watch();

        // Admin menu + settings are always available; the feature screens gate
        // themselves on the flags inside Menu.
        if (\is_admin()) {
            Menu::register();
        }

        // CLI is available regardless of the flag: `wp kaupang-stock seed`
        // primes the ledger BEFORE shadow mode is switched on, and status/verify
        // must be able to report on a disabled install.
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('kaupang-stock', \Kaupang\Stock\Cli\Command::class);
        }

        if (!Settings::enabled()) {
            return;
        }

        // The shadow side: rich hooks + claims + dirty registry + shutdown
        // absorber. Runs identically in shadow and active mode.
        Observer::register();

        // Daily invariant check (report-only; healing is operator-initiated).
        Reconciler::register();

        // Embedded surfaces on the product/order edit screens.
        if (\is_admin()) {
            ProductPanel::register();
            OrderMetaBox::register();
        }

        // kaupang-stock/v1 REST: count-line save, receive, movements feed.
        RestController::register();
    }
}
