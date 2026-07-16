<?php
declare(strict_types=1);

namespace Kaupang\Stock\Observe;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Logging\Logger;

/**
 * Shutdown true-up (the same pattern Back In Stock Notifications uses): every
 * product whose _stock moved this request is reconciled against the ledger
 * inside a balance-row lock (lock-then-read, so concurrent true-ups of the
 * same product serialize instead of double-recording one residual).
 *
 * Rich-hook paths arrive here with residual 0 and no-op. A fatal before
 * shutdown loses at most the tail attribution; the scheduled reconciler
 * records it as `external` later.
 */
final class Absorber {

    private static bool $registered = false;

    public static function register(): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        \add_action('shutdown', [self::class, 'run'], 20);
    }

    public static function run(): void {
        if (!DirtyRegistry::hasDirty()) {
            return;
        }
        foreach (DirtyRegistry::takeDirty() as $managedId) {
            try {
                $claim = DirtyRegistry::consumeClaim($managedId);
                // Ledger reads the optional claim.location_id and computes the
                // residual against the aggregate across every location.
                Ledger::absorbResidual($managedId, 0, $claim);
            } catch (\Throwable $e) {
                Logger::error('absorber_failed', ['product' => $managedId, 'error' => $e->getMessage()]);
            }
        }
    }
}
