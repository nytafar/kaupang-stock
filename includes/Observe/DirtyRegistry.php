<?php
declare(strict_types=1);

namespace Kaupang\Stock\Observe;

use Kaupang\Stock\Ledger\WriteThrough;

/**
 * Request-local registry of products whose _stock moved this request (dirty
 * set) plus attribution claims from the write paths that announce themselves
 * before/around the write (order edit, quick/bulk edit, meta box, REST, CSV
 * import). The shutdown Absorber trues each dirty product up and labels the
 * residual with the best claim.
 *
 * Keys are ALWAYS WC_Product::get_stock_managed_by_id() — an order-item claim
 * for a parent-managed variation must resolve to the parent or it never
 * matches the dirty entry.
 */
final class DirtyRegistry {

    /** @var array<int,bool> */
    private static array $dirty = [];
    /** @var array<int,array<string,mixed>> */
    private static array $claims = [];

    public static function markDirty(int $managedId): void {
        if ($managedId <= 0 || WriteThrough::guardActive()) {
            return; // our own write-through — the ledger already moved in step
        }
        self::$dirty[$managedId] = true;
    }

    /**
     * @param array<string,mixed> $claim {reason, ref_type?, ref_id?, ref_line?, via?, actor_id?}
     */
    public static function claim(int $managedId, array $claim): void {
        if ($managedId <= 0 || WriteThrough::guardActive()) {
            return;
        }
        $claim['actor_id'] = $claim['actor_id'] ?? \get_current_user_id();
        self::$claims[$managedId] = $claim; // last claim wins — it is the most specific
    }

    /** @return int[] managed product ids, dirty set is consumed */
    public static function takeDirty(): array {
        $ids = array_keys(self::$dirty);
        self::$dirty = [];
        return $ids;
    }

    /** @return array<string,mixed>|null claim is consumed */
    public static function consumeClaim(int $managedId): ?array {
        $claim = self::$claims[$managedId] ?? null;
        unset(self::$claims[$managedId]);
        return $claim;
    }

    public static function hasDirty(): bool {
        return !empty(self::$dirty);
    }
}
