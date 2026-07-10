<?php
declare(strict_types=1);

namespace Kaupang\Stock\Ledger;

use Kaupang\Stock\Schema;

/**
 * Read-only query helpers over the movements table — the one query surface the
 * admin screens, REST feed, CSV export and CLI share. Writes go through Ledger
 * only; these are index-covered reads (product_loc / ref / occurred), never
 * SUM() on a hot path.
 */
final class Movements {

    /**
     * @param array<string,mixed> $filters product_id, reason (string|string[]),
     *        ref_type, ref_id, batch, actor_id, via, occurred_from, occurred_to
     *        (UTC 'Y-m-d H:i:s'), id_after
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public static function query(array $filters = [], int $page = 1, int $perPage = 50, string $order = 'DESC'): array {
        global $wpdb;

        [$where, $args] = self::buildWhere($filters);
        $table = Schema::movements();
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $countSql = "SELECT COUNT(*) FROM $table $where";
        $total    = (int) (empty($args) ? $wpdb->get_var($countSql) : $wpdb->get_var($wpdb->prepare($countSql, ...$args)));

        $page    = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset  = ($page - 1) * $perPage;

        $sql  = "SELECT * FROM $table $where ORDER BY id $order LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($args, [$perPage, $offset])), ARRAY_A);

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /** Latest movements for one product (product panel, Lagerstatus hover). */
    public static function forProduct(int $productId, int $limit = 10): array {
        return self::query(['product_id' => $productId], 1, $limit)['rows'];
    }

    /** Every movement of one document (order meta box, PO view, count view). */
    public static function forRef(string $refType, int $refId): array {
        return self::query(['ref_type' => $refType, 'ref_id' => $refId], 1, 500, 'ASC')['rows'];
    }

    /** One operator action (a receiving session, a count apply) as a document. */
    public static function forBatch(string $batch): array {
        return self::query(['batch' => $batch], 1, 500, 'ASC')['rows'];
    }

    /**
     * Σ received per PO line (PO status/remainders are DERIVED from movements,
     * never stored counters — §0 principle 5).
     *
     * @param int[] $lineIds
     * @return array<int,float> line id → received qty
     */
    public static function receivedPerPoLine(array $lineIds): array {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $lineIds)));
        if (empty($ids)) {
            return [];
        }
        $in   = implode(',', $ids);
        $rows = $wpdb->get_results(
            "SELECT ref_id, COALESCE(SUM(delta), 0) AS received FROM " . Schema::movements()
            . " WHERE ref_type = 'po_line' AND ref_id IN ($in) GROUP BY ref_id",
            ARRAY_A
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(int) $row['ref_id']] = (float) $row['received'];
        }
        return $out;
    }

    /**
     * Movements per product since a UTC datetime (the count-review "movements
     * since count started" drill-down).
     *
     * @param int[] $productIds
     * @return array<int,array<int,array<string,mixed>>> product id → rows
     */
    public static function sinceForProducts(array $productIds, string $sinceUtc): array {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($ids)) {
            return [];
        }
        $in   = implode(',', $ids);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::movements()
            . " WHERE product_id IN ($in) AND created_at >= %s ORDER BY id ASC",
            $sinceUtc
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(int) $row['product_id']][] = $row;
        }
        return $out;
    }

    /** SUM(delta) per product — reconciler/audit only, never a hot path. */
    public static function sumPerProduct(int $locationId = 0): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, COALESCE(SUM(delta), 0) AS total FROM ' . Schema::movements()
            . ' WHERE location_id = %d GROUP BY product_id',
            Balances::resolveLocation($locationId)
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(int) $row['product_id']] = (float) $row['total'];
        }
        return $out;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function buildWhere(array $filters): array {
        $clauses = [];
        $args    = [];

        if (!empty($filters['product_id'])) {
            $clauses[] = 'product_id = %d';
            $args[]    = (int) $filters['product_id'];
        }
        if (!empty($filters['reason'])) {
            $reasons = array_values(array_filter(array_map('strval', (array) $filters['reason'])));
            if (!empty($reasons)) {
                $clauses[] = 'reason IN (' . implode(',', array_fill(0, count($reasons), '%s')) . ')';
                $args      = array_merge($args, $reasons);
            }
        }
        if (!empty($filters['ref_type'])) {
            $clauses[] = 'ref_type = %s';
            $args[]    = (string) $filters['ref_type'];
        }
        if (!empty($filters['ref_id'])) {
            $clauses[] = 'ref_id = %d';
            $args[]    = (int) $filters['ref_id'];
        }
        if (!empty($filters['batch'])) {
            $clauses[] = 'batch = %s';
            $args[]    = (string) $filters['batch'];
        }
        if (isset($filters['actor_id']) && $filters['actor_id'] !== '' && $filters['actor_id'] !== null) {
            $clauses[] = 'actor_id = %d';
            $args[]    = (int) $filters['actor_id'];
        }
        if (!empty($filters['via'])) {
            $clauses[] = 'via = %s';
            $args[]    = (string) $filters['via'];
        }
        if (!empty($filters['occurred_from'])) {
            $clauses[] = 'occurred_at >= %s';
            $args[]    = (string) $filters['occurred_from'];
        }
        if (!empty($filters['occurred_to'])) {
            $clauses[] = 'occurred_at <= %s';
            $args[]    = (string) $filters['occurred_to'];
        }
        if (!empty($filters['id_after'])) {
            $clauses[] = 'id > %d';
            $args[]    = (int) $filters['id_after'];
        }

        return [empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses), $args];
    }
}
