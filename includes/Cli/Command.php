<?php
declare(strict_types=1);

namespace Kaupang\Stock\Cli;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Observe\Seeder;
use Kaupang\Stock\Reconcile\Reconciler;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;
use Kaupang\Stock\Support\ProductSearch;

/**
 * `wp kaupang-stock <subcommand>` — the verification & operations CLI (§10, §12).
 *
 * The CLI is the reconciliation proof surface: `status`/`verify` report on any
 * install (enabled or not — Plugin::boot registers the command unconditionally),
 * `verify --heal` drives the direction-aware repair, `adjust`/`export`/`seed`
 * exercise the owned write path and audit read path. Every write goes through
 * Ledger/Reconciler public APIs; the only direct SQL here is the read-only
 * COUNT(*) in `status`, keyed on Schema::*() table names.
 *
 * All operator strings are English source (nb_NO catalog); the reason chips that
 * bleed through Reasons::label() are Norwegian by design.
 */
final class Command {

    /**
     * Show plugin state, managed-product/ledger counts and the last reconcile.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render as human-readable lines or JSON.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp kaupang-stock status
     *     wp kaupang-stock status --format=json
     *
     * @param string[] $args
     * @param array<string,string> $assoc
     */
    public function status(array $args, array $assoc): void {
        global $wpdb;

        $movementsTable = Schema::movements();
        $balancesTable  = Schema::balances();

        $balanceRows  = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $balancesTable);
        $movementRows = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $movementsTable);
        $lastMovedAt  = $wpdb->get_var('SELECT MAX(created_at) FROM ' . $movementsTable);

        $report      = \get_option(Reconciler::REPORT_OPTION);
        $managedIds  = Seeder::stockManagedProductIds();

        $reconcileSummary = 'never run';
        if (is_array($report)) {
            $issues     = is_array($report['issues'] ?? null) ? count($report['issues']) : 0;
            $scope      = is_array($report['out_of_scope'] ?? null) ? count($report['out_of_scope']) : 0;
            $checked    = (int) ($report['checked'] ?? 0);
            $time       = (string) ($report['time'] ?? '');
            $reconcileSummary = sprintf(
                '%s — %d checked, %d issue(s), %d out of scope',
                $time !== '' ? $time : 'unknown time',
                $checked,
                $issues,
                $scope
            );
        }

        $data = [
            'enabled'          => Settings::enabled(),
            'mode'             => (string) Settings::get('mode'),
            'active_mode'      => Settings::activeMode(),
            'po_enabled'       => (bool) Settings::get('po_enabled'),
            'counting_enabled' => (bool) Settings::get('counting_enabled'),
            'managed_products' => count($managedIds),
            'balance_rows'     => $balanceRows,
            'movements'        => $movementRows,
            'last_movement'    => $lastMovedAt !== null ? (string) $lastMovedAt : null,
            'last_reconcile'   => $reconcileSummary,
        ];

        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) \wp_json_encode($data));
            return;
        }

        \WP_CLI::log(sprintf('Enabled:            %s', $data['enabled'] ? 'yes' : 'no'));
        \WP_CLI::log(sprintf('Mode:               %s%s', $data['mode'], $data['active_mode'] ? ' (writes through)' : ' (shadow / read-only)'));
        \WP_CLI::log(sprintf('Innkjøp (PO):       %s', $data['po_enabled'] ? 'on' : 'off'));
        \WP_CLI::log(sprintf('Varetelling:        %s', $data['counting_enabled'] ? 'on' : 'off'));
        \WP_CLI::log(sprintf('Managed products:   %d', $data['managed_products']));
        \WP_CLI::log(sprintf('Balance rows:       %d', $data['balance_rows']));
        \WP_CLI::log(sprintf('Movements:          %d', $data['movements']));
        \WP_CLI::log(sprintf('Last movement:      %s', $data['last_movement'] ?? '—'));
        \WP_CLI::log(sprintf('Last reconcile:     %s', $data['last_reconcile']));
    }

    /**
     * Run the reconciliation invariant check and optionally heal drift.
     *
     * Per stock-managed product the invariant is
     * SUM(movements.delta) == balances.on_hand == _stock == lookup.stock_quantity.
     * Without --heal this reports only. With --heal each issue is repaired
     * direction-aware (an unwritten owned tail is replayed into Woo; a mismatch
     * with no tail becomes a compensating `external` movement), then re-verified.
     * Exits 1 while any issue remains unhealed.
     *
     * ## OPTIONS
     *
     * [--heal]
     * : Repair each reported issue, then re-run and report what is left.
     *
     * ## EXAMPLES
     *
     *     wp kaupang-stock verify
     *     wp kaupang-stock verify --heal
     *
     * @param string[] $args
     * @param array<string,string> $assoc
     */
    public function verify(array $args, array $assoc): void {
        $heal   = isset($assoc['heal']);
        $report = Reconciler::run();

        $this->renderReport($report);

        if (empty($report['issues'])) {
            \WP_CLI::success('Ledger reconciled — every stock-managed product matches.');
            return;
        }

        if (!$heal) {
            \WP_CLI::warning(sprintf('%d product(s) have drift. Re-run with --heal to repair.', count($report['issues'])));
            \WP_CLI::halt(1);
        }

        \WP_CLI::log('');
        \WP_CLI::log('Healing…');
        foreach ($report['issues'] as $issue) {
            $productId = (int) $issue['product_id'];
            $outcome   = Reconciler::healProduct($productId);
            \WP_CLI::log(sprintf('  #%d %s → %s', $productId, ProductSearch::label($productId), $outcome));
        }

        \WP_CLI::log('');
        \WP_CLI::log('Re-running verification…');
        $after = Reconciler::run();
        $this->renderReport($after);

        if (empty($after['issues'])) {
            \WP_CLI::success('All issues healed — ledger reconciled.');
            return;
        }

        \WP_CLI::warning(sprintf('%d product(s) still drifting after heal.', count($after['issues'])));
        \WP_CLI::halt(1);
    }

    /**
     * Record an operator adjustment through the ledger.
     *
     * Writes a `adjust` movement with a CLI-scoped idempotency key. A note is
     * mandatory (it is the audit trail). In shadow mode this is refused — that is
     * the correct behavior: owned writes require mode=active. Backdating uses
     * site-local midday, converted to UTC, capped 90 days back by the ledger.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : The stock-managing product/variation id (get_stock_managed_by_id()).
     *
     * <delta>
     * : Signed integer change, e.g. 5 or -3. Never 0.
     *
     * --note=<note>
     * : Required reason for the adjustment (recorded on the movement).
     *
     * [--date=<date>]
     * : Effective date as Y-m-d (occurred_at). Defaults to now.
     *
     * [--location=<id>]
     * : Multi-location only. Record the adjustment at this active location.
     *
     * [--cost=<kr>]
     * : Unit cost ex-VAT in kr for a positive adjustment ("add 20 @ 101") —
     *   prices the FIFO cost layer exactly. Requires cost tracking enabled.
     *
     * ## EXAMPLES
     *
     *     wp kaupang-stock adjust 1234 5 --note="Found in back room"
     *     wp kaupang-stock adjust 1234 -2 --note="Breakage" --date=2026-07-01
     *     wp kaupang-stock adjust 1234 20 --note="Restock" --cost=101
     *
     * @param string[] $args
     * @param array<string,string> $assoc
     */
    public function adjust(array $args, array $assoc): void {
        $productId = (int) ($args[0] ?? 0);
        $deltaRaw  = $args[1] ?? '';
        $note      = (string) ($assoc['note'] ?? '');

        if ($productId <= 0) {
            \WP_CLI::error('A positive product id is required.');
        }
        if ($deltaRaw === '' || !is_numeric($deltaRaw)) {
            \WP_CLI::error('A numeric delta is required (e.g. 5 or -3).');
        }
        $delta = (float) $deltaRaw;
        if (abs($delta) < 1e-9) {
            \WP_CLI::error('Delta must not be 0.');
        }
        if (trim($note) === '') {
            \WP_CLI::error('--note is required for an adjustment.');
        }

        $occurredAt = null;
        if (isset($assoc['date']) && $assoc['date'] !== '') {
            $date = (string) $assoc['date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                \WP_CLI::error('--date must be Y-m-d, e.g. 2026-07-01.');
            }
            // Site-local midday → UTC, so a backdated entry lands squarely on the
            // day regardless of timezone; the ledger caps it to 90 days back.
            $occurredAt = \get_gmt_from_date($date . ' 12:00:00');
        }

        $idem = 'cli:adjust:' . \wp_generate_uuid4();
        $locationId = 0;
        if (Locations::isMulti() && isset($assoc['location']) && (int) $assoc['location'] > 0) {
            $locationId = (int) $assoc['location'];
            if (!Locations::isActive($locationId)) {
                \WP_CLI::error('--location must name an active stock location.');
            }
        }

        // --cost: stash the entered øre under the movement's idempotency key
        // BEFORE the ledger posts (the costing fold runs inside recordBatch).
        if (isset($assoc['cost']) && $assoc['cost'] !== '') {
            $costRaw = str_replace(',', '.', (string) $assoc['cost']);
            if ($delta <= 0) {
                \WP_CLI::error('--cost only applies when adding stock (positive delta).');
            }
            if (!\Kaupang\Stock\Costing\Costing::enabled()) {
                \WP_CLI::error('--cost requires cost tracking (Lager → Innstillinger → Cost tracking).');
            }
            if (!is_numeric($costRaw) || (float) $costRaw < 0) {
                \WP_CLI::error('--cost must be a non-negative kr amount, e.g. 101 or 88.50.');
            }
            \Kaupang\Stock\Costing\CostInputs::stash($idem, (int) round(((float) $costRaw) * 100));
        }

        try {
            $movement = Ledger::adjust(
                $productId,
                $delta,
                $note,
                $occurredAt,
                $idem,
                $locationId
            );
        } catch (LedgerException $e) {
            // Shadow-mode refusal surfaces here by design — do not swallow it.
            \WP_CLI::error($e->getMessage());
            return;
        }

        \WP_CLI::success(sprintf(
            'Recorded movement #%d for %s: %s%s → on hand %s.',
            $movement->id,
            ProductSearch::label($productId),
            $movement->delta > 0 ? '+' : '',
            $this->qty($movement->delta),
            $this->qty($movement->balanceAfter)
        ));
    }

    /**
     * Export ledger movements as CSV.
     *
     * Streams the movements table (optionally filtered) in insertion order,
     * paging 500 rows at a time. Defaults to STDOUT; pass --file to write.
     *
     * ## OPTIONS
     *
     * [--product=<id>]
     * : Restrict to one product/variation id.
     *
     * [--reason=<reason>]
     * : Restrict to one reason (sale, receipt, adjust, count, external, …).
     *
     * [--location=<id>]
     * : Multi-location only. Restrict to one active stock location.
     *
     * [--from=<date>]
     * : Only movements with occurred_at on/after this Y-m-d (site-local midnight).
     *
     * [--to=<date>]
     * : Only movements with occurred_at on/before this Y-m-d (site-local end of day).
     *
     * [--file=<path>]
     * : Write CSV here instead of STDOUT.
     *
     * ## EXAMPLES
     *
     *     wp kaupang-stock export
     *     wp kaupang-stock export --product=1234 --reason=adjust
     *     wp kaupang-stock export --from=2026-01-01 --to=2026-06-30 --file=movements.csv
     *
     * @param string[] $args
     * @param array<string,string> $assoc
     */
    public function export(array $args, array $assoc): void {
        $filters = [];
        if (isset($assoc['product']) && (int) $assoc['product'] > 0) {
            $filters['product_id'] = (int) $assoc['product'];
        }
        if (isset($assoc['reason']) && $assoc['reason'] !== '') {
            $reason = (string) $assoc['reason'];
            if (!Reasons::isValid($reason)) {
                \WP_CLI::error(sprintf("Unknown reason '%s'. Known: %s", $reason, implode(', ', Reasons::all())));
            }
            $filters['reason'] = $reason;
        }
        if (Locations::isMulti() && isset($assoc['location']) && (int) $assoc['location'] > 0) {
            $locationId = (int) $assoc['location'];
            if (!Locations::isActive($locationId)) {
                \WP_CLI::error('--location must name an active stock location.');
            }
            $filters['location_id'] = $locationId;
        }
        if (isset($assoc['from']) && $assoc['from'] !== '') {
            $filters['occurred_from'] = $this->bound((string) $assoc['from'], false);
        }
        if (isset($assoc['to']) && $assoc['to'] !== '') {
            $filters['occurred_to'] = $this->bound((string) $assoc['to'], true);
        }

        $file   = isset($assoc['file']) && $assoc['file'] !== '' ? (string) $assoc['file'] : null;
        $handle = $file !== null ? fopen($file, 'wb') : fopen('php://stdout', 'wb');
        if ($handle === false) {
            \WP_CLI::error($file !== null ? "Could not open {$file} for writing." : 'Could not open STDOUT.');
        }

        $written = Movements::export($filters, $handle);

        if ($file !== null) {
            fclose($handle);
            \WP_CLI::success(sprintf('Exported %d movement(s) to %s.', $written, $file));
        } else {
            fclose($handle);
        }
    }

    /**
     * Seed the ledger for every stock-managed product (enable-time sweep).
     *
     * Creates a balance row per stock-managed product and an `initial` movement
     * wherever _stock is non-zero. Idempotent and safe to interleave with live
     * checkouts — primes the catalog so Lagerstatus and the reconciler are
     * complete from day one. Lazy seeding still covers first-touch regardless.
     *
     * ## EXAMPLES
     *
     *     wp kaupang-stock seed
     *
     * @param string[] $args
     * @param array<string,string> $assoc
     */
    public function seed(array $args, array $assoc): void {
        $result = Seeder::sweep();
        \WP_CLI::success(sprintf(
            'Seeded %d of %d stock-managed product(s).',
            (int) $result['seeded'],
            (int) $result['products']
        ));
    }

    /**
     * FIFO costing operations: verify invariants, rebuild the projection,
     * COGS/valuation reports, opening-cost entry and cost corrections.
     *
     * ## OPTIONS
     *
     * <action>
     * : One of: verify, rebuild, report, opening, correct.
     *
     * [--from=<date>]
     * : report: period start (Y-m-d, site-local). Defaults to the 1st of this month.
     *
     * [--to=<date>]
     * : report: period end (Y-m-d, site-local). Defaults to today.
     *
     * [--product=<id>]
     * : opening: product id to save an opening cost for.
     *
     * [--cost=<kr>]
     * : opening/correct: unit cost ex-VAT in kr.
     *
     * [--layer=<id>]
     * : correct: the cost layer to correct.
     *
     * [--note=<note>]
     * : correct: required correction note.
     *
     * [--yes]
     * : rebuild: skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp kaupang-stock cost verify
     *     wp kaupang-stock cost rebuild --yes
     *     wp kaupang-stock cost report --from=2026-01-01 --to=2026-06-30
     *     wp kaupang-stock cost opening                      # list pending
     *     wp kaupang-stock cost opening --product=1814 --cost=42.50
     *     wp kaupang-stock cost correct --layer=12 --cost=88.00 --note="Invoice showed 88"
     *
     * @param string[] $args
     * @param array<string,string> $assoc
     */
    public function cost(array $args, array $assoc): void {
        $action = (string) ($args[0] ?? '');
        if (!\Kaupang\Stock\Costing\Costing::enabled() && $action !== 'verify') {
            \WP_CLI::error('Cost tracking is not enabled (Lager → Innstillinger → Cost tracking).');
        }

        switch ($action) {
            case 'verify':
                \Kaupang\Stock\Costing\Sweeper::sweepAll();
                $report = \Kaupang\Stock\Costing\Verify::run();
                $this->renderCostVerify($report);
                if (!empty($report['issues'])) {
                    \WP_CLI::halt(1);
                }
                return;

            case 'rebuild':
                if (!isset($assoc['yes'])) {
                    \WP_CLI::confirm('Rebuild deletes every derived cost row (consumptions + non-opening layers + cache) and re-folds from the movements. Continue?');
                }
                $before = \Kaupang\Stock\Costing\Valuation::totals();
                $swept  = \Kaupang\Stock\Costing\Rebuild::run();
                $after  = \Kaupang\Stock\Costing\Valuation::totals();
                \WP_CLI::log(sprintf('Re-folded %d movement(s) across %d product(s).', $swept['movements'], $swept['products']));
                \WP_CLI::log(sprintf('Valuation before: %s kr · after: %s kr', $this->kr($before['total_ore']), $this->kr($after['total_ore'])));
                if ($before['total_ore'] === $after['total_ore'] && abs($before['open_qty'] - $after['open_qty']) < 1e-6) {
                    \WP_CLI::success('Rebuild re-derived identical totals.');
                } else {
                    \WP_CLI::warning('Totals changed across rebuild — inputs were edited since the last fold, or this flags an engine bug. Run `cost verify`.');
                }
                return;

            case 'report':
                $from = $this->bound((string) ($assoc['from'] ?? \wp_date('Y-m-01')), false);
                $to   = $this->bound((string) ($assoc['to'] ?? \wp_date('Y-m-d')), true);
                \Kaupang\Stock\Costing\Sweeper::sweepAll();
                $report = \Kaupang\Stock\Costing\Valuation::cogsReport($from, $to);
                $this->renderCostReport($report);
                return;

            case 'opening':
                if (isset($assoc['product'], $assoc['cost'])) {
                    $productId = (int) $assoc['product'];
                    $kr        = str_replace(',', '.', (string) $assoc['cost']);
                    if (!is_numeric($kr) || (float) $kr < 0) {
                        \WP_CLI::error('--cost must be a non-negative kr amount.');
                    }
                    try {
                        $layerId = \Kaupang\Stock\Costing\Opening::save($productId, (int) round(((float) $kr) * 100));
                    } catch (\Kaupang\Stock\Costing\CostingException $e) {
                        \WP_CLI::error($e->getMessage());
                        return;
                    }
                    \WP_CLI::success(sprintf('Opening layer #%d saved for %s at %s kr.', $layerId, ProductSearch::label($productId), $kr));
                    return;
                }
                $pending = \Kaupang\Stock\Costing\Opening::pending();
                if ($pending === []) {
                    \WP_CLI::success('No products are waiting for an opening cost.');
                    return;
                }
                $rows = [];
                foreach ($pending as $productId => $info) {
                    $rows[] = [
                        'product' => sprintf('#%d %s', $productId, ProductSearch::label($productId)),
                        'qty'     => $this->qty($info['qty']),
                    ];
                }
                \WP_CLI\Utils\format_items('table', $rows, ['product', 'qty']);
                \WP_CLI::log('Save with: wp kaupang-stock cost opening --product=<id> --cost=<kr>');
                return;

            case 'correct':
                $layerId = (int) ($assoc['layer'] ?? 0);
                $kr      = str_replace(',', '.', (string) ($assoc['cost'] ?? ''));
                $note    = (string) ($assoc['note'] ?? '');
                if ($layerId <= 0 || !is_numeric($kr) || trim($note) === '') {
                    \WP_CLI::error('correct requires --layer=<id>, --cost=<kr> and --note=<note>.');
                }
                try {
                    $newLayerId = \Kaupang\Stock\Costing\Rebuild::correctLayer($layerId, (int) round(((float) $kr) * 100), $note);
                } catch (\Kaupang\Stock\Costing\CostingException $e) {
                    \WP_CLI::error($e->getMessage());
                    return;
                }
                \WP_CLI::success(sprintf('Layer #%d corrected → new layer #%d at %s kr (remaining qty re-entered today).', $layerId, $newLayerId, $kr));
                return;

            default:
                \WP_CLI::error("Unknown action '$action'. Use: verify, rebuild, report, opening, correct.");
        }
    }

    /* ------------------------------ Internals ----------------------------- */

    /** @param array<string,mixed> $report Costing\Verify::run() output */
    private function renderCostVerify(array $report): void {
        \WP_CLI::log(sprintf('Cost projection checked for %d product(s).', (int) $report['checked']));

        if (!empty($report['issues'])) {
            $rows = [];
            foreach ((array) $report['issues'] as $issue) {
                $rows[] = [
                    'product' => sprintf('#%d %s', (int) $issue['product_id'], ProductSearch::label((int) $issue['product_id'])),
                    'type'    => (string) $issue['type'],
                    'detail'  => \wp_json_encode(array_diff_key($issue, ['product_id' => 1, 'type' => 1])),
                ];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['product', 'type', 'detail']);
            \WP_CLI::warning(sprintf('%d cost issue(s).', count((array) $report['issues'])));
        } else {
            \WP_CLI::success('Cost projection consistent — cache, layers and balances agree.');
        }

        foreach ([
            'lagging'         => 'lagging product(s) — run again to sweep',
            'opening_pending' => 'product(s) waiting for an opening cost (`cost opening`)',
            'uncosted'        => 'product(s) holding uncosted stock',
            'provisional'     => 'product(s) with outstanding provisional COGS',
        ] as $key => $label) {
            if (!empty($report[$key])) {
                \WP_CLI::log(sprintf('%d %s: %s', count((array) $report[$key]), $label, implode(', ', array_map(
                    static fn ($id): string => '#' . $id,
                    array_keys((array) $report[$key])
                ))));
            }
        }
    }

    /** @param array<string,mixed> $report Costing\Valuation::cogsReport() output */
    private function renderCostReport(array $report): void {
        \WP_CLI::log(sprintf('COGS %s → %s (UTC)', (string) $report['from'], (string) $report['to']));
        \WP_CLI::log('');
        if (!empty($report['by_reason'])) {
            $rows = [];
            foreach ((array) $report['by_reason'] as $reason => $bucket) {
                $rows[] = [
                    'reason'   => $reason,
                    'qty'      => $this->qty((float) $bucket['qty']),
                    'cogs_kr'  => $this->kr((int) $bucket['cost_ore']),
                    'uncosted' => $this->qty((float) $bucket['uncosted_qty']),
                ];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['reason', 'qty', 'cogs_kr', 'uncosted']);
        } else {
            \WP_CLI::log('No consumption in the period.');
        }
        \WP_CLI::log('');
        \WP_CLI::log(sprintf('Opening value:   %s kr', $this->kr((int) $report['opening_ore'])));
        \WP_CLI::log(sprintf('+ Inbound:       %s kr', $this->kr((int) $report['inbound_ore'])));
        \WP_CLI::log(sprintf('− COGS:          %s kr', $this->kr((int) $report['cogs_ore'])));
        \WP_CLI::log(sprintf('= Closing value: %s kr', $this->kr((int) $report['closing_ore'])));
        $gap = (int) $report['identity_gap_ore'];
        if ($gap === 0) {
            \WP_CLI::success('Identity ties out to the øre.');
        } else {
            \WP_CLI::warning(sprintf('Identity gap: %s kr — expected with uncosted/estimate stock in the period; otherwise run `cost verify`.', $this->kr($gap)));
        }
    }

    /** Integer øre → kr display string. */
    private function kr(int $ore): string {
        return number_format($ore / 100, 2, ',', ' ');
    }

    /**
     * @param array{time:string,checked:int,issues:array,out_of_scope:array} $report
     */
    private function renderReport(array $report): void {
        \WP_CLI::log(sprintf('Checked %d stock-managed product(s) at %s (UTC).', (int) $report['checked'], (string) $report['time']));

        $issues = $report['issues'] ?? [];
        if (!empty($issues)) {
            \WP_CLI::log('');
            \WP_CLI::log(sprintf('%d issue(s):', count($issues)));
            $rows = [];
            foreach ($issues as $issue) {
                $type = isset($issue['type']) ? (string) $issue['type'] : 'unknown';
                $location = isset($issue['location_id'])
                    ? sprintf('#%d %s', (int) $issue['location_id'], (string) ($issue['location'] ?? Locations::name((int) $issue['location_id'])))
                    : '—';
                $rows[] = [
                    'product'  => sprintf('#%d %s', (int) $issue['product_id'], ProductSearch::label((int) $issue['product_id'])),
                    'type'     => $type,
                    'location' => $location,
                    'sum'      => $this->qty((float) ($issue['sum'] ?? 0)),
                    'on_hand'  => $this->qty((float) ($issue['on_hand'] ?? 0)),
                    '_stock'   => $this->qty((float) ($issue['stock'] ?? 0)),
                    'lookup'   => !isset($issue['lookup']) ? 'NULL' : $this->qty((float) $issue['lookup']),
                    'has_tail' => !empty($issue['has_tail']) ? 'yes' : 'no',
                ];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['product', 'type', 'location', 'sum', 'on_hand', '_stock', 'lookup', 'has_tail']);
        }

        $outOfScope = $report['out_of_scope'] ?? [];
        if (!empty($outOfScope)) {
            \WP_CLI::log('');
            \WP_CLI::log(sprintf('%d out-of-scope balance row(s) (kept for audit, excluded from the invariant):', count($outOfScope)));
            $rows = [];
            foreach ($outOfScope as $row) {
                $rows[] = [
                    'product' => sprintf('#%d %s', (int) $row['product_id'], (string) $row['name']),
                    'on_hand' => $this->qty((float) $row['on_hand']),
                    'state'   => (string) $row['state'],
                ];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['product', 'on_hand', 'state']);
        }
    }

    /** A --from/--to Y-m-d bound → UTC 'Y-m-d H:i:s'; errors out on anything else. */
    private function bound(string $date, bool $endOfDay): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            \WP_CLI::error(sprintf("Date '%s' must be Y-m-d.", $date));
        }
        return Movements::dateBoundary($date, $endOfDay);
    }

    /** Integer-clean quantity rendering (v1 is integer-only; keep CSV tidy). */
    private function qty(float $value): string {
        if (abs($value - round($value)) < 1e-9) {
            return (string) (int) round($value);
        }
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
