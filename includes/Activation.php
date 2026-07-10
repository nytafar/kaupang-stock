<?php
declare(strict_types=1);

namespace Kaupang\Stock;

/**
 * Activation: preconditions + schema + the seeded default location. Deliberately
 * does NOT sweep `initial` movements — every flag defaults false, so seeding
 * happens when stock_enabled is switched on (SettingsPage/CLI call
 * Observe\Seeder::sweep()), and lazily on first ledger touch regardless.
 */
final class Activation {

    public static function run(): void {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            \deactivate_plugins(\plugin_basename(KAUPANG_STOCK_FILE));
            \wp_die(
                \esc_html__('Kaupang Stock requires PHP 8.1 or newer.', 'kaupang-stock'),
                'Kaupang Stock',
                ['back_link' => true]
            );
        }

        Schema::install();

        // WooCommerce may activate after us — boot re-checks. Object cache is
        // recommended (absorber correctness never depends on it: fresh _stock
        // reads are direct SQL), not required.
        if (!class_exists('WooCommerce')) {
            \add_option('kaupang_stock_activated_without_wc', 1, '', false);
        }
    }
}
