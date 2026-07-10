<?php
declare(strict_types=1);

namespace Kaupang\Stock\Admin;

use Kaupang\Stock\Settings;

/**
 * The "Lager" admin menu — the single entry point Plugin.php calls (§6). The
 * top level plus Innstillinger are always present; the feature screens gate
 * themselves on the flags, so a fresh activation shows only Lager → Innstillinger
 * and the top-level page points the operator at the settings until enabled.
 *
 * Assets are REGISTERED here (always, so the other pages can depend on the
 * `kaupang-stock-admin` handles) and only ENQUEUED on this plugin's own screens.
 * Per-user REST calls send the wp_rest nonce as X-WP-Nonce (suite gotcha #1).
 */
final class Menu {

    public const SLUG          = 'kaupang-stock';
    public const SLUG_MOVES    = 'kaupang-stock-movements';
    public const SLUG_COUNTS   = 'kaupang-stock-counts';
    public const SLUG_PURCHASE = 'kaupang-stock-purchasing';
    public const SLUG_SETTINGS = 'kaupang-stock-settings';

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
        $cap = Settings::capability();

        \add_menu_page(
            'Lager',
            'Lager',
            $cap,
            self::SLUG,
            [StatusPage::class, 'render'],
            'dashicons-archive',
            56
        );

        if (!Settings::enabled()) {
            // Disabled: only the top level (which renders a "not enabled"
            // pointer) plus Innstillinger.
            \add_submenu_page(
                self::SLUG,
                \__('Stock status', 'kaupang-stock'),
                'Lagerstatus',
                $cap,
                self::SLUG,
                [StatusPage::class, 'render']
            );
            \add_submenu_page(
                self::SLUG,
                \__('Settings', 'kaupang-stock'),
                'Innstillinger',
                $cap,
                self::SLUG_SETTINGS,
                [SettingsPage::class, 'render']
            );
            return;
        }

        // Lagerstatus reuses the top-level slug so the parent row is labelled.
        \add_submenu_page(
            self::SLUG,
            \__('Stock status', 'kaupang-stock'),
            'Lagerstatus',
            $cap,
            self::SLUG,
            [StatusPage::class, 'render']
        );

        \add_submenu_page(
            self::SLUG,
            \__('Movements', 'kaupang-stock'),
            'Bevegelser',
            $cap,
            self::SLUG_MOVES,
            [MovementsPage::class, 'render']
        );

        if (Settings::get('counting_enabled') && class_exists('\\Kaupang\\Stock\\Admin\\CountsPage')) {
            \add_submenu_page(
                self::SLUG,
                \__('Stock counts', 'kaupang-stock'),
                'Varetelling',
                $cap,
                self::SLUG_COUNTS,
                ['\\Kaupang\\Stock\\Admin\\CountsPage', 'render']
            );
        }

        if (Settings::get('po_enabled') && class_exists('\\Kaupang\\Stock\\Admin\\PurchasingPage')) {
            \add_submenu_page(
                self::SLUG,
                \__('Purchasing', 'kaupang-stock'),
                'Innkjøp',
                $cap,
                self::SLUG_PURCHASE,
                ['\\Kaupang\\Stock\\Admin\\PurchasingPage', 'render']
            );
        }

        \add_submenu_page(
            self::SLUG,
            \__('Settings', 'kaupang-stock'),
            'Innstillinger',
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
            KAUPANG_STOCK_VERSION
        );
        \wp_register_script(
            self::SCRIPT_HANDLE,
            KAUPANG_STOCK_URL . 'assets/admin.js',
            [],
            KAUPANG_STOCK_VERSION,
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
        // Our pages all carry ?page=kaupang-stock…; the top-level hook suffix is
        // "toplevel_page_kaupang-stock", submenus "lager_page_kaupang-stock-…".
        if (strpos($hook, 'kaupang-stock') !== false) {
            return true;
        }
        $page = isset($_GET['page']) ? \sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return strpos($page, 'kaupang-stock') === 0;
    }
}
