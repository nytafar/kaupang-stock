<?php
declare(strict_types=1);

namespace Kaupang\Stock;

use Kaupang\Stock\Reconcile\Reconciler;

/**
 * Complete list of this plugin's Action Scheduler hooks, maintained
 * deliberately (fiken's Deactivation staleness is the cautionary tale).
 * Data/tables are kept — see uninstall.php.
 */
final class Deactivation {

    public static function run(): void {
        if (function_exists('as_unschedule_all_actions')) {
            \as_unschedule_all_actions(Reconciler::HOOK, [], Reconciler::AS_GROUP);
        }
    }
}
