<?php
declare(strict_types=1);

namespace Kaupang\Stock\Purchasing;

use Kaupang\Stock\Schema;

/**
 * Minimal supplier (leverandør) registry — CRUD over the suppliers table (§2/§6.4).
 *
 * Suppliers are our own document rows, so direct $wpdb via Schema::suppliers() is
 * the correct write path (the stock-movements-only-via-Ledger rule is about the
 * movements table, not these). Org-nr is validated/looked up via kaupang-brreg
 * when it is active — a class_exists-guarded soft dependency, the suite pattern:
 * the whole feature accepts-and-continues when brreg is absent.
 */
final class Suppliers {

    /**
     * All suppliers, active first then by name. Cheap: the table is a small
     * registry (a handful of rows at this store's scale).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(bool $activeOnly = false): array {
        global $wpdb;
        $where = $activeOnly ? 'WHERE active = 1' : '';
        $rows  = $wpdb->get_results(
            'SELECT * FROM ' . Schema::suppliers() . " $where ORDER BY active DESC, name ASC",
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
            'SELECT * FROM ' . Schema::suppliers() . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** Display name for a supplier id, or a dash when unset/unknown. */
    public static function name(?int $id): string {
        if ($id === null || $id <= 0) {
            return '—';
        }
        $row = self::find($id);
        return $row !== null ? (string) $row['name'] : ('#' . $id);
    }

    /**
     * Create a supplier. Returns the new id.
     *
     * @param array<string,mixed> $data name, org_nr, email, phone, note, active
     */
    public static function create(array $data): int {
        global $wpdb;
        $clean = self::sanitize($data);
        if ($clean['name'] === '') {
            throw new \InvalidArgumentException('Supplier name is required');
        }
        $ok = $wpdb->insert(Schema::suppliers(), $clean, self::formats($clean));
        if ($ok === false || $wpdb->insert_id <= 0) {
            throw new \RuntimeException('Supplier insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void {
        global $wpdb;
        if ($id <= 0) {
            return;
        }
        $clean = self::sanitize($data);
        if ($clean['name'] === '') {
            throw new \InvalidArgumentException('Supplier name is required');
        }
        $wpdb->update(Schema::suppliers(), $clean, ['id' => $id], self::formats($clean), ['%d']);
    }

    /**
     * Soft-toggle a supplier active/inactive. We never hard-delete — a supplier
     * may be referenced by historical purchase orders (audit).
     */
    public static function setActive(int $id, bool $active): void {
        global $wpdb;
        if ($id <= 0) {
            return;
        }
        $wpdb->update(Schema::suppliers(), ['active' => $active ? 1 : 0], ['id' => $id], ['%d'], ['%d']);
    }

    /**
     * Normalise and MOD11-validate an org-nr string, distinguishing outcomes so a
     * caller can surface a helpful message. Returns 9 digits (or '') plus a valid
     * flag; when brreg is absent we only strip to 9 digits and accept (soft dep).
     *
     * @return array{orgnr:string,valid:bool,checked:bool}
     */
    public static function normalizeOrgNr(string $raw): array {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return ['orgnr' => '', 'valid' => true, 'checked' => false];
        }
        if (strlen($digits) !== 9) {
            return ['orgnr' => $digits, 'valid' => false, 'checked' => false];
        }
        if (class_exists('\\Kaupang\\Brreg\\OrgNumber')) {
            return [
                'orgnr'   => $digits,
                'valid'   => \Kaupang\Brreg\OrgNumber::isValidMod11($digits),
                'checked' => true,
            ];
        }
        // brreg absent: accept-and-continue (9 digits is all we can assert).
        return ['orgnr' => $digits, 'valid' => true, 'checked' => false];
    }

    /**
     * BRREG lookup for the "Slå opp" button. Returns the normalised entity (name,
     * address, flags) plus the NLOD attribution source, or null when brreg is
     * absent / the number is unknown / BRREG is unavailable (fail soft).
     *
     * @return array{name:string,entity:array<string,mixed>,source:string,source_url:string}|null
     */
    public static function brregLookup(string $orgnr): ?array {
        if (!class_exists('\\Kaupang\\Brreg\\Client')) {
            return null;
        }
        $entity = \Kaupang\Brreg\Client::lookup($orgnr);
        if (!is_array($entity)) {
            return null;
        }
        return [
            'name'       => (string) ($entity['name'] ?? ''),
            'entity'     => $entity,
            'source'     => \Kaupang\Brreg\Client::SOURCE,
            'source_url' => \Kaupang\Brreg\Client::SOURCE_URL,
        ];
    }

    /** True when kaupang-brreg is available for org-nr validation/lookup. */
    public static function brregAvailable(): bool {
        return class_exists('\\Kaupang\\Brreg\\Client') && class_exists('\\Kaupang\\Brreg\\OrgNumber');
    }

    /** @param array<string,mixed> $data  @return array<string,mixed> */
    private static function sanitize(array $data): array {
        $org = self::normalizeOrgNr((string) ($data['org_nr'] ?? ''));
        return [
            'name'   => \sanitize_text_field((string) ($data['name'] ?? '')),
            'org_nr' => $org['orgnr'] !== '' ? $org['orgnr'] : null,
            'email'  => self::cleanEmail((string) ($data['email'] ?? '')),
            'phone'  => self::nullable(\sanitize_text_field((string) ($data['phone'] ?? '')), 50),
            'note'   => self::nullable(\sanitize_text_field((string) ($data['note'] ?? '')), 255),
            'active' => empty($data['active']) ? 0 : 1,
        ];
    }

    private static function cleanEmail(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $email = \sanitize_email($raw);
        return $email !== '' ? $email : null;
    }

    private static function nullable(string $value, int $max): ?string {
        $value = mb_substr($value, 0, $max);
        return $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $row  @return string[] */
    private static function formats(array $row): array {
        $formats = [];
        foreach ($row as $value) {
            $formats[] = is_int($value) ? '%d' : '%s';
        }
        return $formats;
    }
}
