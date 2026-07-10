<?php
declare(strict_types=1);

namespace Kaupang\Stock\Logging;

/**
 * Thin wrapper over wc_get_logger() with source = kaupang-stock (fiken's Logger
 * pattern). Observer/absorber code logs failures here and never throws into
 * core's flow — the ledger must not block or break a checkout.
 */
final class Logger {

    private static ?\WC_Logger_Interface $logger = null;
    private const SOURCE = 'kaupang-stock';

    public static function debug(string $event, array $context = []): void {
        if (!defined('KAUPANG_STOCK_DEBUG') || \KAUPANG_STOCK_DEBUG !== true) {
            return;
        }
        self::emit('debug', $event, $context);
    }

    public static function info(string $event, array $context = []): void {
        self::emit('info', $event, $context);
    }

    public static function notice(string $event, array $context = []): void {
        self::emit('notice', $event, $context);
    }

    public static function warning(string $event, array $context = []): void {
        self::emit('warning', $event, $context);
    }

    public static function error(string $event, array $context = []): void {
        self::emit('error', $event, $context);
    }

    private static function emit(string $level, string $event, array $context): void {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        if (self::$logger === null) {
            self::$logger = \wc_get_logger();
        }
        $line = $event . ' ' . \wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::$logger->log($level, $line, ['source' => self::SOURCE]);
    }
}
