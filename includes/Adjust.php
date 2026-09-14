<?php
declare(strict_types=1);

namespace Kaupang\Stock;

use Kaupang\Stock\Costing\CostInputs;
use Kaupang\Stock\Costing\Costing;
use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\Movement;

defined('ABSPATH') || exit;

/**
 * The one adjust-with-cost policy, shared by the admin pages and the CLI.
 *
 * Costing never touches the ledger write path and Ledger never imports Costing
 * (D5). This sibling knows only the order of operations: mint the movement key
 * once, stash the entered cost under it BEFORE the ledger posts (the fold runs
 * inside recordBatch's post-commit hook), then post. A dropped stash is never
 * swallowed — it would give wrong COGS forever — so both halves throw.
 */
final class Adjust {

    /** Throws LedgerException|CostingException. */
    public static function withCost(
        int $productId,
        float $delta,
        string $note,
        ?int $unitCostOre,
        int $locationId = 0,
        ?string $key = null,
        ?string $occurredAt = null
    ): Movement {
        $idem = 'adjust:' . ($key !== null && $key !== '' ? $key : \wp_generate_uuid4());

        // Removals are valued FIFO by the engine; an entered cost is only ever
        // the price of stock coming in.
        if ($delta > 0 && $unitCostOre !== null && Costing::enabled()) {
            CostInputs::stash($idem, $unitCostOre);
        }

        return Ledger::adjust($productId, $delta, $note, $occurredAt, $idem, $locationId);
    }
}
