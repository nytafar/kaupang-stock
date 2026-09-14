<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

use Kaupang\Stock\Support\ProductSearch;

/**
 * Count-sheet CSV import (§6.7) — the parse half, owned by the counting module
 * so it is testable without an upload. Reads the exported sheet back: a `sku`
 * column (SKU → product via ProductSearch) with a `product_id` fallback, and a
 * `counted` column (blank leaves the line uncounted). Robust on the round trip:
 * UTF-8 BOM strip and a ; / , delimiter sniff.
 */
final class Import {

    /**
     * @param resource $handle readable CSV stream, positioned anywhere (rewound here)
     * @return array{lines:array<int,float>,errors:array<int,string>} product id → counted qty
     */
    public static function parse($handle): array {
        rewind($handle);
        $first = fgets($handle);
        if ($first === false) {
            return ['lines' => [], 'errors' => [\__('The CSV is empty.', 'kaupang-stock')]];
        }
        $first     = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        rewind($handle);
        $header = null;
        $lines  = [];
        $errors = [];
        while (($cols = fgetcsv($handle, 0, $delimiter)) !== false) {
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
            // Tolerate comma decimals from a Norwegian locale export.
            $lines[$productId] = (float) str_replace(',', '.', $counted);
        }
        return ['lines' => $lines, 'errors' => $errors];
    }
}
