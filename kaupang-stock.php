<?php
/**
 * Plugin Name: Kaupang Stock
 * Plugin URI:  https://jellum.net/kaupang-stock
 * Description: Ledger-backed lagerføring for WooCommerce — append-only stock movement ledger with full attribution, audit UI, varetelling (counting) and innkjøp/receiving. Mirrors WooCommerce on the sale path (never fights it); every quantity is re-derivable from the ledger. Part of the Kaupang suite.
 * Version:     0.8.0
 * Author:      Lasse Jellum
 * Author URI:  https://jellum.net
 * Text Domain: kaupang-stock
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.7
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 10.9
 * License: GPL-2.0-or-later
 *
 * @package KaupangStock
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('KAUPANG_STOCK_VERSION', '0.8.0');
define('KAUPANG_STOCK_FILE', __FILE__);
define('KAUPANG_STOCK_DIR', plugin_dir_path(__FILE__));
define('KAUPANG_STOCK_URL', plugin_dir_url(__FILE__));

// PSR-4 autoloader (Composer-less), suite convention.
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Kaupang\\Stock\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('Kaupang\\Stock\\'));
    $path = KAUPANG_STOCK_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// HPOS + Cart/Checkout Blocks compatibility. We never touch order storage
// directly — order context flows in via hook payloads and the WC_Order CRUD.
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', KAUPANG_STOCK_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', KAUPANG_STOCK_FILE, true);
    }
});

add_action('plugins_loaded', [\Kaupang\Stock\Plugin::class, 'boot'], 10);

register_activation_hook(__FILE__, [\Kaupang\Stock\Activation::class, 'run']);
register_deactivation_hook(__FILE__, [\Kaupang\Stock\Deactivation::class, 'run']);
