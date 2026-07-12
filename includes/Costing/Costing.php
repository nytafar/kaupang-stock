<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Ledger\Movement;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Settings;

/**
 * Costing module bootstrap — FIFO cost layers as a pure projection over the
 * movement stream (docs/kaupang-stock-costing-research.md §2 Option A).
 *
 * The module NEVER touches the ledger write path: it subscribes to
 * `kaupang/stock/movement_recorded` (fired post-commit for new movements only)
 * and folds each product's movements — in movement-id order, the deterministic
 * key — into cost_layers + cost_consumptions + the product_cost cache. A
 * catch-up sweep (Sweeper) replays anything the live hook missed (crash,
 * costing toggled off/on), so the hook is an optimisation, not a correctness
 * requirement.
 *
 * The ANCHOR is the movement id where costing began: movements at or below it
 * never fold; the state they produced is represented by operator-entered
 * opening layers instead (Opening). Stamped once, never moved.
 */
final class Costing {

    public const OPTION_ANCHOR = 'kaupang_stock_cost_anchor';

    /** @var array{id:int,time:string}|null */
    private static ?array $anchor = null;

    /** True when both the ledger and the costing flag are on. */
    public static function enabled(): bool {
        return Settings::enabled() && (bool) Settings::get('costing_enabled');
    }

    public static function register(): void {
        if (!self::enabled()) {
            return;
        }
        \add_action('kaupang/stock/movement_recorded', [self::class, 'onMovement']);
    }

    /**
     * Live fold: runs in-request after the ledger commit. MUST never throw into
     * the sale path — any failure is logged and left for the sweep to replay.
     */
    public static function onMovement(Movement $movement): void {
        if (!self::enabled() || self::anchorId() === null) {
            return;
        }
        if ($movement->id <= self::anchorId()) {
            return; // pre-anchor history is opening-layer territory
        }
        try {
            Engine::processProduct($movement->productId, $movement->locationId);
        } catch (\Throwable $e) {
            Logger::error('cost_fold_failed', [
                'movement' => $movement->id,
                'product'  => $movement->productId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------ Anchor -------------------------------- */

    /** Movement id costing starts after; null until costing was first enabled. */
    public static function anchorId(): ?int {
        $anchor = self::anchor();
        return $anchor !== null ? (int) $anchor['id'] : null;
    }

    /** UTC 'Y-m-d H:i:s' the anchor was stamped (opening layers' occurred_at). */
    public static function anchorTime(): ?string {
        $anchor = self::anchor();
        return $anchor !== null ? (string) $anchor['time'] : null;
    }

    /** @return array{id:int,time:string}|null */
    public static function anchor(): ?array {
        if (self::$anchor !== null) {
            return self::$anchor;
        }
        $stored = \get_option(self::OPTION_ANCHOR, null);
        if (is_array($stored) && isset($stored['id'], $stored['time'])) {
            return self::$anchor = ['id' => (int) $stored['id'], 'time' => (string) $stored['time']];
        }
        return null;
    }

    /**
     * Stamp the anchor at the current ledger head. Called from the settings
     * option-transition watcher when costing_enabled flips false→true (after
     * the option write — never from sanitize; the 2026-07-10 recursion lesson).
     * Write-once: a later disable/re-enable keeps the original anchor so the
     * opening layers stay valid.
     */
    public static function stampAnchorIfMissing(): void {
        if (self::anchor() !== null) {
            return;
        }
        global $wpdb;
        $maxId  = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . \Kaupang\Stock\Schema::movements());
        $anchor = ['id' => $maxId, 'time' => gmdate('Y-m-d H:i:s')];
        \add_option(self::OPTION_ANCHOR, $anchor, '', false);
        self::$anchor = $anchor;
        Logger::info('cost_anchor_stamped', $anchor);
    }

    public static function flushCache(): void {
        self::$anchor = null;
    }
}
