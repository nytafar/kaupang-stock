<?php
declare(strict_types=1);

namespace Kaupang\Stock;

use Kaupang\Stock\Ledger\Balances;

/** CRUD registry for stock locations. Historical references are never deleted. */
final class Locations {

    /** @return array<int,array<string,mixed>> */
    public static function all(bool $activeOnly = false): array {
        global $wpdb;
        $where = $activeOnly ? 'WHERE active = 1' : '';
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . Schema::locations() . " $where ORDER BY is_default DESC, active DESC, name ASC, id ASC",
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::locations() . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function activeCount(): int {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::locations() . ' WHERE active = 1');
    }

    public static function isMulti(): bool {
        return self::activeCount() > 1;
    }

    public static function isActive(int $id): bool {
        $row = self::find($id);
        return $row !== null && (int) $row['active'] === 1;
    }

    public static function exists(int $id): bool {
        return self::find($id) !== null;
    }

    public static function name(int $id): string {
        $row = self::find($id);
        return $row !== null ? (string) $row['name'] : ('#' . $id);
    }

    public static function create(string $name, bool $makeDefault = false): int {
        global $wpdb;
        $name = mb_substr(\sanitize_text_field($name), 0, 100);
        if ($name === '') {
            throw new \InvalidArgumentException('Location name is required');
        }
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->get_results('SELECT id FROM ' . Schema::locations() . ' ORDER BY id FOR UPDATE');
            $hasDefault = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::locations() . ' WHERE is_default = 1') > 0;
            $makeDefault = $makeDefault || !$hasDefault;
            if ($makeDefault) {
                $wpdb->query('UPDATE ' . Schema::locations() . ' SET is_default = 0');
            }
            $ok = $wpdb->insert(Schema::locations(), [
                'name' => $name,
                'is_default' => $makeDefault ? 1 : 0,
                'active' => 1,
            ], ['%s', '%d', '%d']);
            if ($ok === false || $wpdb->insert_id <= 0) {
                throw new \RuntimeException('Location insert failed: ' . $wpdb->last_error);
            }
            $id = (int) $wpdb->insert_id;
            $wpdb->query('COMMIT');
            Balances::flushDefaultLocationCache();
            return $id;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function rename(int $id, string $name): void {
        global $wpdb;
        $name = mb_substr(\sanitize_text_field($name), 0, 100);
        if ($id <= 0 || $name === '' || self::find($id) === null) {
            throw new \InvalidArgumentException('A valid location and name are required');
        }
        $updated = $wpdb->update(Schema::locations(), ['name' => $name], ['id' => $id], ['%s'], ['%d']);
        if ($updated === false) {
            throw new \RuntimeException('Location update failed: ' . $wpdb->last_error);
        }
    }

    public static function setDefault(int $id): void {
        global $wpdb;
        if (!self::isActive($id)) {
            throw new \InvalidArgumentException('Default location must be active');
        }
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->get_results('SELECT id FROM ' . Schema::locations() . ' ORDER BY id FOR UPDATE');
            $wpdb->query('UPDATE ' . Schema::locations() . ' SET is_default = 0');
            $updated = $wpdb->update(Schema::locations(), ['is_default' => 1], ['id' => $id], ['%d'], ['%d']);
            if ($updated === false) {
                throw new \RuntimeException('Default location update failed: ' . $wpdb->last_error);
            }
            $wpdb->query('COMMIT');
            Balances::flushDefaultLocationCache();
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function setActive(int $id, bool $active): void {
        global $wpdb;
        $row = self::find($id);
        if ($row === null) {
            throw new \InvalidArgumentException('Location not found');
        }
        if ($active) {
            $wpdb->update(Schema::locations(), ['active' => 1], ['id' => $id], ['%d'], ['%d']);
            return;
        }
        if (self::activeCount() <= 1) {
            throw new \RuntimeException('At least one location must remain active');
        }
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->get_results('SELECT id FROM ' . Schema::locations() . ' ORDER BY id FOR UPDATE');
            if ((int) $row['is_default'] === 1) {
                $replacement = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM ' . Schema::locations() . ' WHERE active = 1 AND id <> %d ORDER BY id ASC LIMIT 1',
                    $id
                ));
                if ($replacement <= 0) {
                    throw new \RuntimeException('At least one location must remain active');
                }
                $wpdb->query('UPDATE ' . Schema::locations() . ' SET is_default = 0');
                $wpdb->update(Schema::locations(), ['is_default' => 1], ['id' => $replacement], ['%d'], ['%d']);
            }
            $updated = $wpdb->update(Schema::locations(), ['active' => 0, 'is_default' => 0], ['id' => $id], ['%d', '%d'], ['%d']);
            if ($updated === false) {
                throw new \RuntimeException('Location update failed: ' . $wpdb->last_error);
            }
            $wpdb->query('COMMIT');
            Balances::flushDefaultLocationCache();
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
