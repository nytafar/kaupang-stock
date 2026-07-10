<?php
declare(strict_types=1);

namespace Kaupang\Stock\Purchasing;

use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Support\ProductSearch;

/**
 * Purchase-order line CRUD (§2/§6.4) — our own document rows, so direct $wpdb via
 * Schema::purchaseOrderLines() is the correct write path.
 *
 * The load-bearing rule (§0 principle 5): a line stores only qty_ordered and the
 * optional unit cost. Received quantity is NEVER stored — it is DERIVED from
 * receipt movements (Movements::receivedPerPoLine()) at read time, so there is no
 * counter to drift. Adding the same product to a PO twice merges into the existing
 * line (UNIQUE(po_id, product_id)) by bumping qty_ordered.
 */
final class Lines {

    /**
     * Raw line rows for a PO, in insertion order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forPo(int $poId): array {
        global $wpdb;
        if ($poId <= 0) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::purchaseOrderLines() . ' WHERE po_id = %d ORDER BY id ASC',
            $poId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $lineId): ?array {
        global $wpdb;
        if ($lineId <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::purchaseOrderLines() . ' WHERE id = %d',
            $lineId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Lines for a PO enriched with the DERIVED received/remaining figures and a
     * product label. This is the shape every PO surface (editor, receive grid,
     * inbound view) reads. `remaining = ordered − received`, floored at 0 for
     * display (over-receipt shows received > ordered but never a negative remainder).
     *
     * @return array<int,array<string,mixed>> each row: line columns + product_label,
     *         received (float), remaining (float)
     */
    public static function forPoWithReceived(int $poId): array {
        $lines = self::forPo($poId);
        if ($lines === []) {
            return [];
        }
        $lineIds  = array_map(static fn (array $l): int => (int) $l['id'], $lines);
        $received = Movements::receivedPerPoLine($lineIds);

        $out = [];
        foreach ($lines as $line) {
            $lineId   = (int) $line['id'];
            $ordered  = (float) $line['qty_ordered'];
            $got      = (float) ($received[$lineId] ?? 0.0);
            $remain   = $ordered - $got;
            $line['product_label'] = ProductSearch::label((int) $line['product_id']);
            $line['received']      = $got;
            $line['remaining']     = $remain > 0 ? $remain : 0.0;
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Add a product to a PO, or merge into the existing line for that product
     * (UNIQUE(po_id, product_id)). Returns [line_id, merged] — merged=true when an
     * existing line's qty was bumped rather than a new line inserted.
     *
     * @return array{0:int,1:bool}
     */
    public static function add(int $poId, int $productId, float $qty, ?int $unitCostOre = null, ?string $note = null): array {
        global $wpdb;
        if ($poId <= 0 || $productId <= 0) {
            throw new \InvalidArgumentException('A purchase order and product are required');
        }
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero');
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::purchaseOrderLines() . ' WHERE po_id = %d AND product_id = %d',
            $poId,
            $productId
        ), ARRAY_A);

        if (is_array($existing)) {
            // Merge: bump the ordered qty; keep the newest non-null unit cost.
            $lineId  = (int) $existing['id'];
            $newQty  = (float) $existing['qty_ordered'] + $qty;
            $newCost = $unitCostOre !== null ? $unitCostOre : (isset($existing['unit_cost_ore']) ? (int) $existing['unit_cost_ore'] : null);
            self::persistFields($lineId, [
                'qty_ordered'   => $newQty,
                'unit_cost_ore' => $newCost,
                'note'          => $note !== null ? $note : (isset($existing['note']) ? (string) $existing['note'] : null),
            ]);
            return [$lineId, true];
        }

        $data = [
            'po_id'         => $poId,
            'product_id'    => $productId,
            'qty_ordered'   => number_format($qty, 3, '.', ''),
            'unit_cost_ore' => $unitCostOre,
            'note'          => self::cleanNote($note),
        ];
        $formats = ['%d', '%d', '%s', $unitCostOre === null ? '%s' : '%d', '%s'];
        $row = [];
        $fmt = [];
        $i   = 0;
        foreach ($data as $k => $v) {
            if ($v === null) {
                $i++;
                continue;
            }
            $row[$k] = $v;
            $fmt[]   = $formats[$i];
            $i++;
        }
        $ok = $wpdb->insert(Schema::purchaseOrderLines(), $row, $fmt);
        if ($ok === false || $wpdb->insert_id <= 0) {
            throw new \RuntimeException('Purchase order line insert failed: ' . $wpdb->last_error);
        }
        return [(int) $wpdb->insert_id, false];
    }

    /**
     * Edit a draft line's ordered qty / unit cost / note. Callers gate this on the
     * PO still being draft (after ordering, lines are locked — see PurchaseOrders).
     */
    public static function edit(int $lineId, float $qty, ?int $unitCostOre, ?string $note): void {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero');
        }
        self::persistFields($lineId, [
            'qty_ordered'   => $qty,
            'unit_cost_ore' => $unitCostOre,
            'note'          => $note,
        ]);
    }

    /** Remove a draft line. Callers gate on draft status. */
    public static function delete(int $lineId): void {
        global $wpdb;
        if ($lineId <= 0) {
            return;
        }
        $wpdb->delete(Schema::purchaseOrderLines(), ['id' => $lineId], ['%d']);
    }

    /**
     * @param array<string,mixed> $fields qty_ordered (float), unit_cost_ore (?int),
     *        note (?string) — any subset
     */
    private static function persistFields(int $lineId, array $fields): void {
        global $wpdb;
        $set  = [];
        $fmt  = [];
        if (array_key_exists('qty_ordered', $fields)) {
            $set['qty_ordered'] = number_format((float) $fields['qty_ordered'], 3, '.', '');
            $fmt[]              = '%s';
        }
        if (array_key_exists('unit_cost_ore', $fields)) {
            if ($fields['unit_cost_ore'] === null) {
                $set['unit_cost_ore'] = null;
                $fmt[]                = '%s';
            } else {
                $set['unit_cost_ore'] = (int) $fields['unit_cost_ore'];
                $fmt[]                = '%d';
            }
        }
        if (array_key_exists('note', $fields)) {
            $set['note'] = self::cleanNote($fields['note'] !== null ? (string) $fields['note'] : null);
            $fmt[]       = '%s';
        }
        if ($set === []) {
            return;
        }
        $wpdb->update(Schema::purchaseOrderLines(), $set, ['id' => $lineId], $fmt, ['%d']);
    }

    private static function cleanNote(?string $note): ?string {
        if ($note === null) {
            return null;
        }
        $note = mb_substr(\sanitize_text_field($note), 0, 255);
        return $note !== '' ? $note : null;
    }
}
