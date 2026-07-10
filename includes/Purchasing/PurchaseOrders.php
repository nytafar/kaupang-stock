<?php
declare(strict_types=1);

namespace Kaupang\Stock\Purchasing;

use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

/**
 * Purchase orders (innkjøp) — the commercial agreement (§1.3/§6.4). Our own
 * document rows, so direct $wpdb via Schema::purchaseOrders() is correct.
 *
 * THE load-bearing rule (§0 principle 5): the status column stores ONLY operator
 * states — draft | ordered | cancelled. partial and received are DERIVED from
 * receipt movements at read time (derivedStatus()); no received counter is ever
 * written anywhere. Lifecycle:
 *
 *   draft ──order()──▶ ordered ──(Σ received)──▶ partial ──▶ received
 *     │                   │
 *     └──cancel()─────────┴──────────────────────▶ cancelled
 *
 * Ordering locks the lines: after order(), line edits are refused and any change
 * appends to the PO note trail instead (appendNote()). Cancelling after a partial
 * receipt writes off the remainder only — received stock physically exists and
 * stays; returning goods is a negative adjustment on Lagerstatus (v1), not here.
 */
final class PurchaseOrders {

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ORDERED   = 'ordered';
    public const STATUS_CANCELLED = 'cancelled';

    // Derived-only states (never stored in the status column).
    public const STATUS_PARTIAL  = 'partial';
    public const STATUS_RECEIVED = 'received';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . Schema::purchaseOrders() . ' ORDER BY id DESC',
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
            'SELECT * FROM ' . Schema::purchaseOrders() . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Create a draft PO. Returns the new id.
     *
     * @param array<string,mixed> $data supplier_id, supplier_ref, eta, note
     */
    public static function create(array $data): int {
        global $wpdb;
        $row = [
            'supplier_id'  => self::nullableId($data['supplier_id'] ?? null),
            'status'       => self::STATUS_DRAFT,
            'supplier_ref' => self::cleanText($data['supplier_ref'] ?? null, 100),
            'eta'          => self::cleanDate($data['eta'] ?? null),
            'note'         => self::cleanText($data['note'] ?? null, 255),
            'created_by'   => \get_current_user_id(),
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ];
        [$insertRow, $formats] = self::insertShape($row);
        $ok = $wpdb->insert(Schema::purchaseOrders(), $insertRow, $formats);
        if ($ok === false || $wpdb->insert_id <= 0) {
            throw new \RuntimeException('Purchase order insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Edit the header of a DRAFT PO (supplier, ref, ETA, note). After ordering the
     * header stays editable for supplier_ref/eta/note but changes are also
     * expected to append to the note trail via appendNote() from the admin layer.
     *
     * @param array<string,mixed> $data
     */
    public static function updateHeader(int $id, array $data): void {
        global $wpdb;
        if ($id <= 0) {
            return;
        }
        $set = [
            'supplier_id'  => self::nullableId($data['supplier_id'] ?? null),
            'supplier_ref' => self::cleanText($data['supplier_ref'] ?? null, 100),
            'eta'          => self::cleanDate($data['eta'] ?? null),
            'note'         => self::cleanText($data['note'] ?? null, 255),
        ];
        $formats = ['%d', '%s', '%s', '%s'];
        // $wpdb->update() writes a NULL value in $data as SQL NULL (ignoring its
        // format), so a cleared supplier_ref/eta/note field is correctly nulled.
        $wpdb->update(Schema::purchaseOrders(), $set, ['id' => $id], $formats, ['%d']);
    }

    /**
     * draft → ordered. Stamps ordered_at and locks the lines (enforced by callers
     * checking isDraft()/isLocked()). Idempotent: re-ordering an ordered PO no-ops.
     */
    public static function order(int $id): void {
        global $wpdb;
        $po = self::find($id);
        if ($po === null || (string) $po['status'] !== self::STATUS_DRAFT) {
            return;
        }
        $wpdb->update(
            Schema::purchaseOrders(),
            ['status' => self::STATUS_ORDERED, 'ordered_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Cancel a PO (draft or ordered). Cancel-after-partial writes off the
     * remainder ONLY — received movements are immutable and the received stock
     * physically exists, so nothing is un-received here. Sets closed_at.
     */
    public static function cancel(int $id): void {
        global $wpdb;
        $po = self::find($id);
        if ($po === null) {
            return;
        }
        $status = (string) $po['status'];
        if ($status === self::STATUS_CANCELLED) {
            return;
        }
        $wpdb->update(
            Schema::purchaseOrders(),
            ['status' => self::STATUS_CANCELLED, 'closed_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Set closed_at when a PO first derives to fully received. Called by Receiving
     * after a posting brings every line to its ordered qty. Idempotent — only
     * stamps if not already closed; never changes the operator status column
     * (received is derived, not stored).
     */
    public static function markClosedIfReceived(int $id): void {
        global $wpdb;
        $po = self::find($id);
        if ($po === null || !empty($po['closed_at'])) {
            return;
        }
        if ((string) $po['status'] !== self::STATUS_ORDERED) {
            return;
        }
        $lines = Lines::forPoWithReceived($id);
        if (self::deriveFromLines($po, $lines) === self::STATUS_RECEIVED) {
            $wpdb->update(
                Schema::purchaseOrders(),
                ['closed_at' => gmdate('Y-m-d H:i:s')],
                ['id' => $id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * Append a line to the PO note trail (used when an ordered PO's "lines" would
     * otherwise be edited — §6.4: changes after ordering append to the note trail).
     * Prepends a UTC timestamp; the 255-char column keeps the most recent tail.
     */
    public static function appendNote(int $id, string $line): void {
        global $wpdb;
        $po = self::find($id);
        if ($po === null) {
            return;
        }
        $line = trim(\sanitize_text_field($line));
        if ($line === '') {
            return;
        }
        $stamp   = gmdate('Y-m-d H:i');
        $entry   = "[$stamp] $line";
        $current = (string) ($po['note'] ?? '');
        $merged  = $current === '' ? $entry : ($current . ' | ' . $entry);
        // Keep the newest tail within the column width.
        $merged  = mb_substr($merged, -255);
        $wpdb->update(Schema::purchaseOrders(), ['note' => $merged], ['id' => $id], ['%s'], ['%d']);
    }

    /* ----------------------------- Derived status ----------------------------- */

    public static function isDraft(int $id): bool {
        $po = self::find($id);
        return $po !== null && (string) $po['status'] === self::STATUS_DRAFT;
    }

    /** True once a PO is ordered (lines locked) — covers ordered/partial/received. */
    public static function isLocked(int $id): bool {
        $po = self::find($id);
        return $po !== null && (string) $po['status'] === self::STATUS_ORDERED;
    }

    /**
     * The full status shown to the operator: draft | ordered | partial | received
     * | cancelled. Operator states pass through; for an ordered PO the receipt
     * movements decide (any received but not all → partial; every line fully
     * received → received). §0 principle 5: this is a read-time derivation, the
     * status column never stores partial/received.
     *
     * @param array<string,mixed>      $po               a purchase_orders row
     * @param array<int,array<string,mixed>> $linesWithReceived  Lines::forPoWithReceived()
     */
    public static function derivedStatus(array $po, array $linesWithReceived): string {
        return self::deriveFromLines($po, $linesWithReceived);
    }

    /**
     * Convenience: derive status for a PO id (loads its lines). Prefer the array
     * form on screens that already have the lines to avoid a re-query.
     */
    public static function statusFor(int $id): string {
        $po = self::find($id);
        if ($po === null) {
            return self::STATUS_DRAFT;
        }
        return self::deriveFromLines($po, Lines::forPoWithReceived($id));
    }

    /**
     * @param array<string,mixed>      $po
     * @param array<int,array<string,mixed>> $lines
     */
    private static function deriveFromLines(array $po, array $lines): string {
        $stored = (string) ($po['status'] ?? self::STATUS_DRAFT);
        if ($stored === self::STATUS_DRAFT || $stored === self::STATUS_CANCELLED) {
            return $stored;
        }
        // ordered: fold in the received movements.
        if ($lines === []) {
            return self::STATUS_ORDERED;
        }
        $anyReceived = false;
        $allFull     = true;
        foreach ($lines as $line) {
            $ordered  = (float) $line['qty_ordered'];
            $received = (float) ($line['received'] ?? 0.0);
            if ($received > 1e-9) {
                $anyReceived = true;
            }
            // A line is "full" when received meets or exceeds ordered (over-receipt counts).
            if ($received + 1e-9 < $ordered) {
                $allFull = false;
            }
        }
        if ($allFull) {
            return self::STATUS_RECEIVED;
        }
        return $anyReceived ? self::STATUS_PARTIAL : self::STATUS_ORDERED;
    }

    /** Norwegian labels for the status chips. */
    public static function statusLabel(string $status): string {
        $labels = [
            self::STATUS_DRAFT     => \__('Kladd', 'kaupang-stock'),
            self::STATUS_ORDERED   => \__('Bestilt', 'kaupang-stock'),
            self::STATUS_PARTIAL   => \__('Delvis mottatt', 'kaupang-stock'),
            self::STATUS_RECEIVED  => \__('Mottatt', 'kaupang-stock'),
            self::STATUS_CANCELLED => \__('Kansellert', 'kaupang-stock'),
        ];
        return $labels[$status] ?? $status;
    }

    /* ------------------------- Incoming (Lagerstatus) ------------------------- */

    /**
     * Σ open PO-line remainders per product — the "incoming" column the Lagerstatus
     * screen shows on every render. Open = the PO's operator status is `ordered`
     * (an ordered PO's underived remainders; draft never counts, cancelled/received
     * never count). Cheap: one indexed query over lines joined to their PO plus one
     * SUM over receipt movements (Movements::receivedPerPoLine).
     *
     * Returns [] when po_enabled is off (the whole innkjøp feature is dormant).
     *
     * @return array<int,float> product_id → remaining incoming qty (only where > 0)
     */
    public static function incomingPerProduct(): array {
        if (!Settings::get('po_enabled')) {
            return [];
        }
        global $wpdb;

        $poTable   = Schema::purchaseOrders();
        $lineTable = Schema::purchaseOrderLines();

        // Every line on an ordered PO, with its ordered qty. received is derived
        // from movements below (never a stored counter).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id AS line_id, l.product_id AS product_id, l.qty_ordered AS ordered
             FROM $lineTable l
             INNER JOIN $poTable po ON po.id = l.po_id
             WHERE po.status = %s",
            self::STATUS_ORDERED
        ), ARRAY_A);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $lineIds = array_map(static fn (array $r): int => (int) $r['line_id'], $rows);
        $received = Movements::receivedPerPoLine($lineIds);

        $out = [];
        foreach ($rows as $row) {
            $lineId    = (int) $row['line_id'];
            $productId = (int) $row['product_id'];
            $remaining = (float) $row['ordered'] - (float) ($received[$lineId] ?? 0.0);
            if ($remaining > 1e-9) {
                $out[$productId] = ($out[$productId] ?? 0.0) + $remaining;
            }
        }
        return $out;
    }

    /**
     * Every open PO line across all ordered POs, enriched for the Inbound view
     * (product, ordered, received, remaining, ETA, supplier). One flat list — the
     * single "what's coming" answer.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function inboundLines(): array {
        if (!Settings::get('po_enabled')) {
            return [];
        }
        $out = [];
        foreach (self::all() as $po) {
            if ((string) $po['status'] !== self::STATUS_ORDERED) {
                continue;
            }
            $poId  = (int) $po['id'];
            $lines = Lines::forPoWithReceived($poId);
            foreach ($lines as $line) {
                if ((float) $line['remaining'] <= 1e-9) {
                    continue;
                }
                $out[] = [
                    'po_id'         => $poId,
                    'line_id'       => (int) $line['id'],
                    'product_id'    => (int) $line['product_id'],
                    'product_label' => (string) $line['product_label'],
                    'ordered'       => (float) $line['qty_ordered'],
                    'received'      => (float) $line['received'],
                    'remaining'     => (float) $line['remaining'],
                    'eta'           => (string) ($po['eta'] ?? ''),
                    'supplier_id'   => isset($po['supplier_id']) ? (int) $po['supplier_id'] : 0,
                ];
            }
        }
        return $out;
    }

    /* -------------------------------- helpers -------------------------------- */

    /** @param array<string,mixed> $row  @return array{0:array<string,mixed>,1:string[]} */
    private static function insertShape(array $row): array {
        $out = [];
        $fmt = [];
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue; // omit → column default / NULL
            }
            $out[$key] = $value;
            $fmt[]     = in_array($key, ['supplier_id', 'created_by'], true) ? '%d' : '%s';
        }
        return [$out, $fmt];
    }

    /** @param mixed $value */
    private static function nullableId($value): ?int {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    /** @param mixed $value */
    private static function cleanText($value, int $max): ?string {
        if ($value === null) {
            return null;
        }
        $text = mb_substr(\sanitize_text_field((string) $value), 0, $max);
        return $text !== '' ? $text : null;
    }

    /** @param mixed $value  Y-m-d or null. */
    private static function cleanDate($value): ?string {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        return ($dt !== false && $dt->format('Y-m-d') === $raw) ? $raw : null;
    }
}
