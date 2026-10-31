<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\Assets;

/**
 * The stock admin pages — the single entry point Plugin.php calls (§6). They sit
 * under WooCommerce (conventions §4: no top-level menu; kaupang-admin groups them
 * by slug). Stock status plus Stock settings are always present; the feature
 * screens gate themselves on the flags, so a fresh activation shows only those
 * two and the status page points the operator at the settings until enabled.
 *
 * Assets are REGISTERED here (always, so the other pages can depend on the
 * `kaupang-stock-admin` handles) and only ENQUEUED on this plugin's own screens.
 * Per-user REST calls send the wp_rest nonce as X-WP-Nonce (suite gotcha #1).
 */
final class Menu {

    public const SLUG           = 'kaupang-stock';
    public const SLUG_MOVES     = 'kaupang-stock-movements';
    public const SLUG_VALUATION = 'kaupang-stock-valuation';
    public const SLUG_COUNTS    = 'kaupang-stock-counts';
    public const SLUG_PURCHASE  = 'kaupang-stock-purchasing';
    public const SLUG_SETTINGS  = 'kaupang-stock-settings';

    /** Screen id of the status page (its Screen Options columns key on it). */
    public const SCREEN_STATUS  = 'woocommerce_page_kaupang-stock';

    public const STYLE_HANDLE  = 'kaupang-stock-admin';
    public const SCRIPT_HANDLE = 'kaupang-stock-admin';

    public static function register(): void {
        \add_action('admin_menu', [self::class, 'menu']);
        \add_action('admin_enqueue_scripts', [self::class, 'assets']);

        // Page registration (admin_post handlers, settings registration). The
        // Counts/Purchasing pages are built by sibling agents — guard with
        // class_exists() so the shell works before their files land.
        StatusPage::register();
        MovementsPage::register();
        SettingsPage::register();

        if (Settings::enabled() && Settings::get('costing_enabled')) {
            ValuationPage::register();
        }

        if (Settings::enabled() && Settings::get('counting_enabled')
            && class_exists('\\Kaupang\\Stock\\Admin\\CountsPage')
        ) {
            \Kaupang\Stock\Admin\CountsPage::register();
        }
        if (Settings::enabled() && Settings::get('po_enabled')
            && class_exists('\\Kaupang\\Stock\\Admin\\PurchasingPage')
        ) {
            \Kaupang\Stock\Admin\PurchasingPage::register();
        }
    }

    public static function menu(): void {
        $cap    = Settings::capability();
        $parent = 'woocommerce'; // conventions §4: under the parent users expect; kaupang-admin regroups by slug.

        // Core's Screen Options column toggles: the panel renders for any screen
        // whose manage_{id}_columns filter returns columns; visibility is per
        // user (column-<id> classes + user meta), no WP_List_Table needed.
        \add_filter('manage_' . self::SCREEN_STATUS . '_columns', [StatusPage::class, 'columns']);

        // Stock status keeps the bare slug: disabled, it renders the "not enabled" pointer.
        \add_submenu_page(
            $parent,
            \__('Stock status', 'kaupang-stock'),
            \_x('Stock status', 'admin menu', 'kaupang-stock'),
            $cap,
            self::SLUG,
            [StatusPage::class, 'render']
        );

        if (!Settings::enabled()) {
            // Disabled: only the status pointer plus the settings.
            self::settingsItem($parent, $cap);
            return;
        }

        \add_submenu_page(
            $parent,
            \__('Movements', 'kaupang-stock'),
            \_x('Stock movements', 'admin menu', 'kaupang-stock'),
            $cap,
            self::SLUG_MOVES,
            [MovementsPage::class, 'render']
        );

        if (Settings::get('costing_enabled')) {
            \add_submenu_page(
                $parent,
                \__('Stock value', 'kaupang-stock'),
                \_x('Stock value', 'admin menu', 'kaupang-stock'),
                $cap,
                self::SLUG_VALUATION,
                [ValuationPage::class, 'render']
            );
        }

        if (Settings::get('counting_enabled') && class_exists('\\Kaupang\\Stock\\Admin\\CountsPage')) {
            \add_submenu_page(
                $parent,
                \__('Stock counts', 'kaupang-stock'),
                \_x('Stock counts', 'admin menu', 'kaupang-stock'),
                $cap,
                self::SLUG_COUNTS,
                ['\\Kaupang\\Stock\\Admin\\CountsPage', 'render']
            );
        }

        if (Settings::get('po_enabled') && class_exists('\\Kaupang\\Stock\\Admin\\PurchasingPage')) {
            \add_submenu_page(
                $parent,
                \__('Purchasing', 'kaupang-stock'),
                \_x('Purchasing', 'admin menu', 'kaupang-stock'),
                $cap,
                self::SLUG_PURCHASE,
                ['\\Kaupang\\Stock\\Admin\\PurchasingPage', 'render']
            );
        }

        self::settingsItem($parent, $cap);
    }

    private static function settingsItem(string $parent, string $cap): void {
        \add_submenu_page(
            $parent,
            \__('Settings', 'kaupang-stock'),
            \_x('Stock settings', 'admin menu', 'kaupang-stock'),
            $cap,
            self::SLUG_SETTINGS,
            [SettingsPage::class, 'render']
        );
    }

    /**
     * Register the shared handles ALWAYS (sibling pages depend on them); enqueue
     * only on this plugin's own screens.
     */
    public static function assets(string $hook): void {
        \wp_register_style(
            self::STYLE_HANDLE,
            KAUPANG_STOCK_URL . 'assets/admin.css',
            [],
            Assets::ver('assets/admin.css')
        );
        \wp_register_script(
            self::SCRIPT_HANDLE,
            KAUPANG_STOCK_URL . 'assets/admin.js',
            [],
            Assets::ver('assets/admin.js'),
            true
        );
        \wp_localize_script(self::SCRIPT_HANDLE, 'KaupangStock', [
            'restUrl'    => \esc_url_raw(\rest_url('kaupang-stock/v1/')),
            'nonce'      => \wp_create_nonce('wp_rest'),
            'activeMode' => Settings::activeMode(),
            'i18n'       => [
                'adjust'        => \__('Adjust', 'kaupang-stock'),
                'cancel'        => \__('Cancel', 'kaupang-stock'),
                'noteRequired'  => \__('A note is required.', 'kaupang-stock'),
                'noMatch'       => \__('No products match.', 'kaupang-stock'),
                'reversePrompt' => \__('Reverse this movement? Enter a note (required):', 'kaupang-stock'),
                'confirmReverse'=> \__('A note is required to reverse a movement.', 'kaupang-stock'),
                'searching'     => \__('Searching…', 'kaupang-stock'),
                'noResults'     => \__('No products found.', 'kaupang-stock'),
            ],
        ]);

        if (self::isOwnScreen($hook)) {
            \wp_enqueue_style(self::STYLE_HANDLE);
            \wp_enqueue_script(self::SCRIPT_HANDLE);
        }
    }

    /** True on any of this plugin's own admin pages (`page=kaupang-stock*`). */
    private static function isOwnScreen(string $hook): bool {
        // Our pages all carry ?page=kaupang-stock…; their hook suffixes are
        // "woocommerce_page_kaupang-stock" and "woocommerce_page_kaupang-stock-…".
        if (strpos($hook, 'kaupang-stock') !== false) {
            return true;
        }
        $page = isset($_GET['page']) ? \sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return strpos($page, 'kaupang-stock') === 0;
    }
}
