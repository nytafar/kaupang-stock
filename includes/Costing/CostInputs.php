<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

use Kaupang\Stock\Schema;

/**
 * Durable operator-entered unit costs, keyed by the MOVEMENT idempotency key
 * the operation will carry. Stashed BEFORE the Ledger call (the fold runs
 * inside recordBatch's post-commit hook, before the caller regains control),
 * so the engine — and any later rebuild — resolves the actual entered cost
 * from a durable input, never from a request-scoped value.
 *
 * One mechanism serves both flows:
 *  - receive-line overrides:  key `po_line:{lineId}:receive:{token}`
 *  - costed quick-adjust:     key `adjust:{uuid}` (minted by the caller)
 *
 * @internal Implementation of the Costing module — reach it through Costing.
 */
final class CostInputs {

    /** Stash (or overwrite) one entered cost. Idempotent per key. */
    public static function stash(string $idempotencyKey, int $unitCostOre): void {
        global $wpdb;
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100 || $unitCostOre < 0) {
            throw new CostingException('Invalid cost input (key/øre)');
        }
        $ok = $wpdb->query($wpdb->prepare(
            'REPLACE INTO ' . Schema::costInputs() . ' (idempotency_key, unit_cost_ore, created_at) VALUES (%s, %d, %s)',
            $idempotencyKey,
            $unitCostOre,
            gmdate('Y-m-d H:i:s')
        ));
        if ($ok === false) {
            throw new CostingException('Cost input stash failed: ' . $wpdb->last_error);
        }
    }

    /** @param array<string,int> $byKey [idempotency_key => øre] */
    public static function stashMany(array $byKey): void {
        foreach ($byKey as $key => $ore) {
            self::stash((string) $key, (int) $ore);
        }
    }

    public static function forKey(?string $idempotencyKey): ?int {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }
        global $wpdb;
        $ore = $wpdb->get_var($wpdb->prepare(
            'SELECT unit_cost_ore FROM ' . Schema::costInputs() . ' WHERE idempotency_key = %s',
            $idempotencyKey
        ));
        return $ore !== null ? (int) $ore : null;
    }
}
