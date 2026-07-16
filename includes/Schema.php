<?php
declare(strict_types=1);

namespace Kaupang\Stock;

/**
 * Custom tables — first in the suite, justified: postmeta/options are the wrong
 * shape for an append-only log (autoload bloat, no usable indexes).
 *
 * Quantity columns are DECIMAL(15,3) as headroom (the Woo storage layer is
 * decimal), but v1 is integer-only at the Ledger boundary because core's default
 * `woocommerce_stock_amount → intval` filter truncates every core-mediated value
 * (see Ledger::validate()).
 *
 * dbDelta discipline: one field per line, two spaces after PRIMARY KEY, KEY not
 * INDEX, no backticks, charset from $wpdb->get_charset_collate().
 */
final class Schema {

    // v2: suppliers.country (dbDelta adds the column; existing rows default to NO).
    // v3: costing — immutable FIFO cost layers + consumptions + derived cache +
    //     durable operator-entered cost inputs. Pure projection tables: only the
    //     Costing engine writes them, and all of them (except opening layers /
    //     cost inputs, which are operator input) re-derive from movements.
    // v4: supplier_products — supplier-specific product identity/catalog used
    //     by the purchase-order editor and printable ordering list.
    // v5: locations.active — soft-deactivation for multi-location routing.
    public const VERSION        = 5;
    public const VERSION_OPTION = 'kaupang_stock_schema_version';

    public static function movements(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_movements';
    }

    public static function balances(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_balances';
    }

    public static function locations(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_locations';
    }

    public static function suppliers(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_suppliers';
    }

    public static function purchaseOrders(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_purchase_orders';
    }

    public static function purchaseOrderLines(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_purchase_order_lines';
    }

    public static function supplierProducts(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_supplier_products';
    }

    public static function counts(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_counts';
    }

    public static function countLines(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_count_lines';
    }

    public static function costLayers(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_cost_layers';
    }

    public static function costConsumptions(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_cost_consumptions';
    }

    public static function productCost(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_product_cost';
    }

    public static function costInputs(): string {
        global $wpdb;
        return $wpdb->prefix . 'kaupang_stock_cost_inputs';
    }

    /** Run dbDelta for all tables and stamp the schema version. Idempotent. */
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();

        \dbDelta("CREATE TABLE " . self::movements() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  delta DECIMAL(15,3) NOT NULL,
  balance_after DECIMAL(15,3) NOT NULL,
  reason VARCHAR(20) NOT NULL,
  ref_type VARCHAR(20) NULL,
  ref_id BIGINT UNSIGNED NULL,
  ref_line BIGINT UNSIGNED NULL,
  batch CHAR(36) NULL,
  actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  via VARCHAR(40) NOT NULL DEFAULT '',
  note VARCHAR(255) NULL,
  idempotency_key VARCHAR(100) NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY product_loc (product_id, location_id, id),
  KEY ref (ref_type, ref_id),
  KEY occurred (occurred_at),
  UNIQUE KEY idem (idempotency_key)
) $collate;");

        \dbDelta("CREATE TABLE " . self::balances() . " (
  product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  on_hand DECIMAL(15,3) NOT NULL DEFAULT 0,
  last_movement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_written_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (product_id, location_id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::locations() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::suppliers() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL,
  org_nr VARCHAR(9) NULL,
  country CHAR(2) NOT NULL DEFAULT 'NO',
  email VARCHAR(200) NULL,
  phone VARCHAR(50) NULL,
  note VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::purchaseOrders() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  supplier_ref VARCHAR(100) NULL,
  eta DATE NULL,
  note VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  ordered_at DATETIME NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $collate;");

        \dbDelta("CREATE TABLE " . self::purchaseOrderLines() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  qty_ordered DECIMAL(15,3) NOT NULL,
  unit_cost_ore BIGINT NULL,
  note VARCHAR(255) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY po_product (po_id, product_id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::supplierProducts() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  supplier_sku VARCHAR(100) NULL,
  supplier_name VARCHAR(200) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY supplier_product (supplier_id, product_id),
  KEY product (product_id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::counts() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  blind TINYINT(1) NOT NULL DEFAULT 1,
  scope VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  applied_by BIGINT UNSIGNED NULL,
  applied_at DATETIME NULL,
  PRIMARY KEY  (id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::countLines() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  expected DECIMAL(15,3) NOT NULL,
  counted DECIMAL(15,3) NULL,
  counted_by BIGINT UNSIGNED NULL,
  counted_at DATETIME NULL,
  recount TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY count_product (count_id, product_id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::costLayers() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  source_movement_id BIGINT UNSIGNED NULL,
  origin VARCHAR(20) NOT NULL,
  ref_type VARCHAR(20) NULL,
  ref_id BIGINT UNSIGNED NULL,
  ref_line BIGINT UNSIGNED NULL,
  batch CHAR(36) NULL,
  unit_cost_ore BIGINT NULL,
  qty_original DECIMAL(15,3) NOT NULL,
  is_estimate TINYINT(1) NOT NULL DEFAULT 0,
  actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_movement (source_movement_id),
  KEY fifo (product_id, location_id, occurred_at, id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::costConsumptions() . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  layer_id BIGINT UNSIGNED NULL,
  movement_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'fifo',
  qty DECIMAL(15,3) NOT NULL,
  unit_cost_ore BIGINT NULL,
  cost_ore BIGINT NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY movement (movement_id),
  KEY layer (layer_id),
  KEY product_time (product_id, location_id, occurred_at, id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::productCost() . " (
  product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  open_qty DECIMAL(15,3) NOT NULL DEFAULT 0,
  value_ore BIGINT NOT NULL DEFAULT 0,
  provisional_qty DECIMAL(15,3) NOT NULL DEFAULT 0,
  provisional_cost_ore BIGINT NOT NULL DEFAULT 0,
  last_movement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (product_id, location_id)
) $collate;");

        \dbDelta("CREATE TABLE " . self::costInputs() . " (
  idempotency_key VARCHAR(100) NOT NULL,
  unit_cost_ore BIGINT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (idempotency_key)
) $collate;");

        // Seed the single v1 location. Multi-location is schema-ready, UI-deferred.
        $existing = $wpdb->get_var('SELECT COUNT(*) FROM ' . self::locations());
        if ((int) $existing === 0) {
            $wpdb->insert(self::locations(), [
                'id'         => 1,
                'name'       => 'Hovedlager',
                'is_default' => 1,
                'active'     => 1,
            ], ['%d', '%s', '%d', '%d']);
        }

        \update_option(self::VERSION_OPTION, self::VERSION, true);
    }

    /**
     * Version-gated upgrade runner, hooked on admin_init (mirrors fiken's
     * maybeUpgradeStorage throttle — the option compare is one autoloaded read).
     */
    public static function maybeUpgrade(): void {
        if ((int) \get_option(self::VERSION_OPTION, 0) < self::VERSION) {
            self::install();
        }
    }
}
