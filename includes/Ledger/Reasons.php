<?php
declare(strict_types=1);

namespace Kaupang\Stock\Ledger;

/**
 * Movement reason registry.
 *
 * Two families with different write-through semantics, derived from the reason
 * (there is no separate flag to get out of sync):
 *
 *  - OWNED   (adjust/receipt/transfer/count/reversal): the operation originates
 *    in this plugin. The ledger records first, then writes through to
 *    WooCommerce (active mode only) and advances the
 *    balances.last_written_id watermark.
 *  - observed (everything else): the quantity change already happened inside
 *    WooCommerce — the ledger records it after the fact and must NEVER write
 *    through (that would double-apply it).
 */
final class Reasons {

    public const SALE           = 'sale';
    public const SALE_RESTORE   = 'sale_restore';
    public const REFUND_RESTOCK = 'refund_restock';
    public const RECEIPT        = 'receipt';
    public const TRANSFER_IN    = 'transfer_in';
    public const TRANSFER_OUT   = 'transfer_out';
    public const COUNT          = 'count';
    public const ADJUST         = 'adjust';
    public const REVERSAL       = 'reversal';
    public const ORDER_EDIT     = 'order_edit';
    public const ADMIN_EDIT     = 'admin_edit';
    public const REST           = 'rest';
    public const IMPORT         = 'import';
    public const INITIAL        = 'initial';
    public const EXTERNAL       = 'external';

    /** @return string[] every valid reason (site-extendable) */
    public static function all(): array {
        $core = [
            self::SALE,
            self::SALE_RESTORE,
            self::REFUND_RESTOCK,
            self::RECEIPT,
            self::TRANSFER_IN,
            self::TRANSFER_OUT,
            self::COUNT,
            self::ADJUST,
            self::REVERSAL,
            self::ORDER_EDIT,
            self::ADMIN_EDIT,
            self::REST,
            self::IMPORT,
            self::INITIAL,
            self::EXTERNAL,
        ];
        $extended = \apply_filters('kaupang/stock/reasons', $core);
        return is_array($extended) ? array_values(array_unique(array_map('strval', $extended))) : $core;
    }

    /** @return string[] reasons whose movements write through to WooCommerce */
    public static function owned(): array {
        return [
            self::RECEIPT,
            self::TRANSFER_IN,
            self::TRANSFER_OUT,
            self::COUNT,
            self::ADJUST,
            self::REVERSAL,
        ];
    }

    public static function isOwned(string $reason): bool {
        return in_array($reason, self::owned(), true);
    }

    public static function isValid(string $reason): bool {
        return in_array($reason, self::all(), true);
    }

    /**
     * Quick-adjust note presets (key => label). The chosen label IS the note,
     * so the ledger's requiresNote() rule and the audit trail keep working
     * without asking the operator to write prose for every count fix.
     *
     * @return array<string,string>
     */
    public static function adjustPresets(): array {
        return [
            'damaged'    => \__('Damaged', 'kaupang-stock'),
            'own_use'    => \__('Own use', 'kaupang-stock'),
            'sample'     => \__('Sample', 'kaupang-stock'),
            'count_fix'  => \__('Count correction', 'kaupang-stock'),
            'found'      => \__('Found', 'kaupang-stock'),
            'other'      => \__('Other', 'kaupang-stock'),
        ];
    }

    /** Free-text note is mandatory for operator judgement calls. */
    public static function requiresNote(string $reason): bool {
        return $reason === self::ADJUST || $reason === self::REVERSAL;
    }

    /** Norwegian labels for admin chips. */
    public static function label(string $reason): string {
        $labels = [
            self::SALE           => \__('Salg', 'kaupang-stock'),
            self::SALE_RESTORE   => \__('Salg tilbakeført', 'kaupang-stock'),
            self::REFUND_RESTOCK => \__('Refusjon (tilbake på lager)', 'kaupang-stock'),
            self::RECEIPT        => \__('Varemottak', 'kaupang-stock'),
            self::TRANSFER_IN    => \__('Flytting inn', 'kaupang-stock'),
            self::TRANSFER_OUT   => \__('Flytting ut', 'kaupang-stock'),
            self::COUNT          => \__('Varetelling', 'kaupang-stock'),
            self::ADJUST         => \__('Justering', 'kaupang-stock'),
            self::REVERSAL       => \__('Reversering', 'kaupang-stock'),
            self::ORDER_EDIT     => \__('Ordrelinje-endring', 'kaupang-stock'),
            self::ADMIN_EDIT     => \__('Admin-endring', 'kaupang-stock'),
            self::REST           => \__('REST API', 'kaupang-stock'),
            self::IMPORT         => \__('Import', 'kaupang-stock'),
            self::INITIAL        => \__('Inngående saldo', 'kaupang-stock'),
            self::EXTERNAL       => \__('Ekstern endring', 'kaupang-stock'),
        ];
        return $labels[$reason] ?? $reason;
    }
}
