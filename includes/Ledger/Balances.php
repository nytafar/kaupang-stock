<?php
declare(strict_types=1);

namespace Kaupang\Stock\Ledger;

use Kaupang\Stock\Schema;

/**
 * Reads (and lock-scoped writes) on the balances projection — the ERPNext "Bin".
 * on_hand is always SUM(movements.delta); Ledger keeps the two in one
 * transaction so they can never diverge from each other.
 *
 * Watermark columns:
 *  - last_movement_id: id of the newest movement for the row.
 *  - last_written_id:  highwater of movements already reflected in Woo _stock.
 *    Owned movements advance it only after a successful write-through; observed
 *    movements advance it only when there is no unwritten owned tail beneath
 *    them (else the tail would become undetectable — see WriteThrough::heal()).
 */
final class Balances {

    public const DEFAULT_LOCATION = 1;

    private static ?int $defaultLocation = null;

    public static function defaultLocationId(): int {
        if (self::$defaultLocation !== null) {
            return self::$defaultLocation;
        }
        global $wpdb;
        $id = $wpdb->get_var('SELECT id FROM ' . Schema::locations() . ' WHERE is_default = 1 AND active = 1 ORDER BY id ASC LIMIT 1');
        return self::$defaultLocation = ($id !== null ? (int) $id : self::DEFAULT_LOCATION);
    }

    public static function flushDefaultLocationCache(): void {
        self::$defaultLocation = null;
    }

    /** Resolve a 0/unspecified location to the default. */
    public static function resolveLocation(int $locationId): int {
        return $locationId > 0 ? $locationId : self::defaultLocationId();
    }

    public static function onHand(int $productId, int $locationId = 0): float {
        $row = self::row($productId, $locationId);
        return $row !== null ? (float) $row['on_hand'] : 0.0;
    }

    /** @return array<string,mixed>|null */
    public static function row(int $productId, int $locationId = 0): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::balances() . ' WHERE product_id = %d AND location_id = %d',
            $productId,
            self::resolveLocation($locationId)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * All balance rows keyed by product_id, then location_id.
     *
     * @param int[] $productIds optional filter
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function rows(array $productIds = []): array {
        global $wpdb;
        $sql = 'SELECT * FROM ' . Schema::balances();
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (!empty($ids)) {
            $sql .= ' WHERE product_id IN (' . implode(',', $ids) . ')';
        }
        $out = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $out[(int) $row['product_id']][(int) $row['location_id']] = $row;
        }
        return $out;
    }

    /** Aggregate balance rows for Woo-facing and other product-level readers. */
    public static function aggregateRows(array $productIds = []): array {
        global $wpdb;
        $sql = 'SELECT product_id, COALESCE(SUM(on_hand), 0) AS on_hand, MAX(last_movement_id) AS last_movement_id FROM ' . Schema::balances();
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if ($ids !== []) {
            $sql .= ' WHERE product_id IN (' . implode(',', $ids) . ')';
        }
        $sql .= ' GROUP BY product_id';
        $out = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $out[(int) $row['product_id']] = $row;
        }
        return $out;
    }

    public static function totalOnHand(int $productId): float {
        global $wpdb;
        return (float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(on_hand), 0) FROM ' . Schema::balances() . ' WHERE product_id = %d',
            $productId
        ));
    }

    /** Ensure the target exists, then lock every location row for one product. */
    public static function lockProductRows(int $productId, int $targetLocationId = 0): array {
        global $wpdb;
        $targetLocationId = self::resolveLocation($targetLocationId);
        $table = Schema::balances();
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (product_id, location_id, on_hand, last_movement_id, last_written_id, updated_at) VALUES (%d, %d, 0, 0, 0, %s)",
            $productId,
            $targetLocationId,
            gmdate('Y-m-d H:i:s')
        ));
        if ($inserted === false) {
            throw new LedgerException("Balance row ensure failed for product $productId: " . $wpdb->last_error);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d ORDER BY location_id ASC FOR UPDATE",
            $productId
        ), ARRAY_A);
        if (!is_array($rows)) {
            throw new LedgerException("Could not lock balance rows for product $productId: " . $wpdb->last_error);
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['location_id']] = $row;
        }
        return $out;
    }

    /**
     * Ensure a row exists, then lock it. MUST be called inside an open
     * transaction — the FOR UPDATE row lock is what serialises all concurrent
     * writers per product (the same pessimistic shape core's ReserveStock uses).
     *
     * @return array<string,mixed> the locked row
     */
    public static function lockRow(int $productId, int $locationId): array {
        global $wpdb;
        $locationId = self::resolveLocation($locationId);
        $table      = Schema::balances();

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (product_id, location_id, on_hand, last_movement_id, last_written_id, updated_at)
             VALUES (%d, %d, 0, 0, 0, %s)",
            $productId,
            $locationId,
            gmdate('Y-m-d H:i:s')
        ));
        if ($inserted === false) {
            throw new LedgerException("Balance row ensure failed for product $productId: " . $wpdb->last_error);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND location_id = %d FOR UPDATE",
            $productId,
            $locationId
        ), ARRAY_A);

        if (!is_array($row)) {
            // Carry the MySQL error (deadlock / lock wait timeout) so the
            // Ledger retry loop can recognise it.
            throw new LedgerException("Could not lock balance row for product $productId: " . $wpdb->last_error);
        }
        return $row;
    }

    /**
     * Write the projection for a locked row. Caller holds the FOR UPDATE lock
     * and computed the watermark decision (see class docblock).
     */
    public static function updateLocked(int $productId, int $locationId, float $onHand, int $lastMovementId, ?int $lastWrittenId): void {
        global $wpdb;
        $set  = 'on_hand = %s, last_movement_id = %d, updated_at = %s';
        $args = [
            number_format($onHand, 3, '.', ''),
            $lastMovementId,
            gmdate('Y-m-d H:i:s'),
        ];
        if ($lastWrittenId !== null) {
            $set   .= ', last_written_id = %d';
            $args[] = $lastWrittenId;
        }
        $args[] = $productId;
        $args[] = self::resolveLocation($locationId);

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::balances() . " SET $set WHERE product_id = %d AND location_id = %d",
            ...$args
        ));
        if ($updated === false) {
            throw new LedgerException("Balance update failed for product $productId: " . $wpdb->last_error);
        }
    }

    /**
     * Advance the write-through watermark (post-commit path, own statement).
     * GREATEST() keeps it monotonic under concurrent completions.
     */
    public static function advanceWritten(int $productId, int $locationId, int $movementId): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::balances()
            . ' SET last_written_id = GREATEST(last_written_id, %d) WHERE product_id = %d AND location_id = %d',
            $movementId,
            $productId,
            self::resolveLocation($locationId)
        ));
    }
}
