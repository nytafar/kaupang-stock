<?php
/**
 * Kaupang Stock uninstall.
 *
 * Bokføringsloven §13: the stock ledger is an accounting record — keep every
 * {prefix}kaupang_stock_* table (the append-only movements log and its documents
 * are retained for the statutory period). Only plugin options and this plugin's
 * scheduled action are removed here.
 *
 * To wipe the ledger tables manually (only after the retention period, or on a
 * throwaway dev DB), run the DROP snippet at the bottom of this file.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Plugin state options (all small; the settings blob is the only autoloaded one).
delete_option('kaupang_stock_settings');
delete_option('kaupang_stock_schema_version');
delete_option('kaupang_stock_last_reconcile');
delete_option('kaupang_stock_activated_without_wc');

// This plugin's only Action Scheduler hook — unschedule if AS is loaded. The
// hook/group are duplicated here as literals: uninstall.php runs standalone and
// must not depend on the plugin's classes being autoloadable.
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('kaupang_stock_reconcile', [], 'kaupang-stock');
}

/*
 * DELIBERATELY KEPT — the ledger tables (Bokføringsloven §13 retention posture,
 * the same stance as spis-fiken's uninstall.php). Deleting the plugin never
 * drops accounting data. To remove it by hand once retention has lapsed:
 *
 *   global $wpdb;
 *   $p = $wpdb->prefix;
 *   $wpdb->query("DROP TABLE IF EXISTS
 *       {$p}kaupang_stock_movements,
 *       {$p}kaupang_stock_balances,
 *       {$p}kaupang_stock_locations,
 *       {$p}kaupang_stock_suppliers,
 *       {$p}kaupang_stock_purchase_orders,
 *       {$p}kaupang_stock_purchase_order_lines,
 *       {$p}kaupang_stock_counts,
 *       {$p}kaupang_stock_count_lines");
 */
