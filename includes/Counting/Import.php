<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Support\ProductSearch;

/**
 * Count-sheet CSV import (§6.7) — the parse and the match, owned by the counting
 * module so both are testable without an upload. Reads the exported sheet back: a
 * `sku` column (SKU → product via ProductSearch) with a `product_id` fallback, and
 * a `counted` column (blank leaves the line uncounted). Robust on the round trip:
 * UTF-8 BOM strip and a ; / , delimiter sniff.
 *
 * Per location (PRD-04 §2): a row is (product, location). `location_id` wins,
 * else the `location` name (case-insensitive) among the count's own locations.
 * Location names are not unique: a name two of the count's locations share is
 * rejected, never resolved to one of them (the export always carries the id). In
 * a one-location count the location columns are optional — a row without them is
 * that location, as before. In a multi-location count a row without a location is
 * an error, never guessed.
 */
final class Import {

    /**
     * Product → counted map, location ignored (the v1 shape; kept for callers
     * that only need the SKU match).
     *
     * @param resource $handle readable CSV stream, positioned anywhere (rewound here)
     * @return array{lines:array<int,float>,errors:array<int,string>} product id → counted qty
     */
    public static function parse($handle): array {
        $read  = self::rows($handle);
        $lines = [];
        foreach ($read['rows'] as $row) {
            $lines[$row['product_id']] = $row['counted'];
        }
        return ['lines' => $lines, 'errors' => $read['errors']];
    }

    /**
     * Every row with a counted value, product resolved, location columns as given.
     *
     * @param resource $handle
     * @return array{rows:array<int,array{row:int,sku:string,product_id:int,location_id:int,location:string,counted:float}>,errors:array<int,string>}
     */
    public static function rows($handle): array {
        rewind($handle);
        $first = fgets($handle);
        if ($first === false) {
            return ['rows' => [], 'errors' => [\__('The CSV is empty.', 'kaupang-stock')]];
        }
        $first     = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        rewind($handle);
        $header = null;
        $rows   = [];
        $errors = [];
        $n      = 0;
        while (($cols = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $n++;
            if ($cols === [null]) {
                continue;
            }
            if ($header === null) {
                $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($cols[0] ?? '')) ?? '';
                $header  = array_map(static fn ($h): string => strtolower(trim((string) $h)), $cols);
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $row[$name] = isset($cols[$i]) ? (string) $cols[$i] : '';
                }
            }
            $counted = trim((string) ($row['counted'] ?? ''));
            if ($counted === '') {
                continue; // blank counted → leave the line uncounted
            }
            $sku       = trim((string) ($row['sku'] ?? ''));
            $productId = $sku !== '' ? (ProductSearch::bySku($sku) ?? 0) : 0;
            if ($productId <= 0) {
                $productId = (int) ($row['product_id'] ?? 0);
            }
            if ($productId <= 0) {
                /* translators: %s: the SKU column value that matched no product */
                $errors[] = sprintf(\__('No product matched SKU “%s”.', 'kaupang-stock'), $sku);
                continue;
            }
            $rows[] = [
                'row'         => $n,
                'sku'         => $sku,
                'product_id'  => $productId,
                'location_id' => (int) trim((string) ($row['location_id'] ?? '')),
                'location'    => trim((string) ($row['location'] ?? '')),
                // Tolerate comma decimals from a Norwegian locale export.
                'counted'     => (float) str_replace(',', '.', $counted),
            ];
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Fill a count's lines from a sheet: each row is matched to the line for its
     * (product, location). Rows that resolve to no location of this count are
     * rejected with a message; a product that is not in the count is skipped, as
     * before. Writes through CountLines::setCounted (the open/review gate lives
     * there).
     *
     * @param resource $handle
     * @return array{filled:int,errors:array<int,string>}
     */
    public static function fill(int $countId, $handle): array {
        $read      = self::rows($handle);
        $errors    = $read['errors'];
        $locations = Counts::locations($countId);
        $multi     = count($locations) > 1;
        $byName    = []; // lower-cased name → every location id carrying it
        foreach ($locations as $id => $name) {
            $byName[mb_strtolower(trim($name))][] = $id;
        }

        $lineFor = [];
        foreach (Counts::lines($countId) as $line) {
            $lineFor[(int) $line['product_id'] . ':' . Counts::lineLocation($line)] = (int) $line['id'];
        }

        $who = static fn (array $r): string => $r['sku'] !== '' ? $r['sku'] : ('#' . $r['product_id']);

        $targets = [];
        foreach ($read['rows'] as $r) {
            if ($r['location_id'] > 0) {
                if (!isset($locations[$r['location_id']])) {
                    $errors[] = sprintf(
                        /* translators: 1: CSV row number, 2: SKU or product id, 3: location id */
                        \__('Row %1$d (%2$s): location id %3$d is not part of this count.', 'kaupang-stock'),
                        $r['row'], $who($r), $r['location_id']
                    );
                    continue;
                }
                $loc = $r['location_id'];
            } elseif ($r['location'] !== '') {
                $ids = $byName[mb_strtolower($r['location'])] ?? [];
                if (count($ids) > 1) {
                    $errors[] = sprintf(
                        /* translators: 1: CSV row number, 2: SKU or product id, 3: location name */
                        \__('Row %1$d (%2$s): several locations in this count are called “%3$s”. Give the row its location_id.', 'kaupang-stock'),
                        $r['row'], $who($r), $r['location']
                    );
                    continue;
                }
                $loc = (int) ($ids[0] ?? 0);
                if ($loc <= 0) {
                    $errors[] = sprintf(
                        /* translators: 1: CSV row number, 2: SKU or product id, 3: location name */
                        \__('Row %1$d (%2$s): location “%3$s” is not part of this count.', 'kaupang-stock'),
                        $r['row'], $who($r), $r['location']
                    );
                    continue;
                }
            } elseif ($multi) {
                $errors[] = sprintf(
                    /* translators: 1: CSV row number, 2: SKU or product id */
                    \__('Row %1$d (%2$s) has no location. This count covers several locations, so every row needs location_id or location.', 'kaupang-stock'),
                    $r['row'], $who($r)
                );
                continue;
            } else {
                $loc = (int) array_key_first($locations);
            }
            $key = $r['product_id'] . ':' . $loc;
            if (isset($lineFor[$key])) {
                $targets[$lineFor[$key]] = $r['counted']; // a repeated row: last wins
            }
        }

        $filled  = 0;
        $refused = 0;
        foreach ($targets as $lineId => $counted) {
            if (CountLines::setCounted($lineId, $counted) !== null) {
                $filled++;
            } else {
                $refused++;
            }
        }
        if ($refused > 0) {
            // The count left open/review mid-import (an apply claimed it).
            $errors[] = sprintf(
                /* translators: %d: number of rows not written */
                \_n('%d row was not written: the count is no longer open for counting.', '%d rows were not written: the count is no longer open for counting.', $refused, 'kaupang-stock'),
                $refused
            );
        }
        return ['filled' => $filled, 'errors' => $errors];
    }
}
