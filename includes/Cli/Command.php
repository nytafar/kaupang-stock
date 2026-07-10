<?php
declare(strict_types=1);

namespace Kaupang\Stock\Cli;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\Movements;
use Kaupang\Stock\Ledger\Reasons;
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
     * ## EXAMPLES
     *
     *     wp kaupang-stock adjust 1234 5 --note="Found in back room"
     *     wp kaupang-stock adjust 1234 -2 --note="Breakage" --date=2026-07-01
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

        try {
            $movement = Ledger::adjust(
                $productId,
                $delta,
                $note,
                $occurredAt,
                'cli:adjust:' . \wp_generate_uuid4()
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
        if (isset($assoc['from']) && $assoc['from'] !== '') {
            $filters['occurred_from'] = $this->dateBoundary((string) $assoc['from'], false);
        }
        if (isset($assoc['to']) && $assoc['to'] !== '') {
            $filters['occurred_to'] = $this->dateBoundary((string) $assoc['to'], true);
        }

        $columns = [
            'id', 'occurred_at', 'created_at', 'product_id', 'product',
            'delta', 'balance_after', 'reason', 'ref_type', 'ref_id', 'ref_line',
            'batch', 'actor_id', 'via', 'note',
        ];

        $file   = isset($assoc['file']) && $assoc['file'] !== '' ? (string) $assoc['file'] : null;
        $handle = $file !== null ? fopen($file, 'wb') : fopen('php://stdout', 'wb');
        if ($handle === false) {
            \WP_CLI::error($file !== null ? "Could not open {$file} for writing." : 'Could not open STDOUT.');
        }

        fputcsv($handle, $columns);

        $labels  = [];
        $page    = 1;
        $perPage = 500;
        $written = 0;
        do {
            $result = Movements::query($filters, $page, $perPage, 'ASC');
            $rows   = $result['rows'];
            foreach ($rows as $row) {
                $productId = (int) $row['product_id'];
                if (!isset($labels[$productId])) {
                    $labels[$productId] = ProductSearch::label($productId);
                }
                fputcsv($handle, [
                    (int) $row['id'],
                    (string) $row['occurred_at'],
                    (string) $row['created_at'],
                    $productId,
                    $labels[$productId],
                    $this->qty((float) $row['delta']),
                    $this->qty((float) $row['balance_after']),
                    (string) $row['reason'],
                    (string) ($row['ref_type'] ?? ''),
                    $row['ref_id'] !== null ? (int) $row['ref_id'] : '',
                    $row['ref_line'] !== null ? (int) $row['ref_line'] : '',
                    (string) ($row['batch'] ?? ''),
                    (int) ($row['actor_id'] ?? 0),
                    (string) ($row['via'] ?? ''),
                    (string) ($row['note'] ?? ''),
                ]);
                $written++;
            }
            $page++;
        } while (count($rows) === $perPage);

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

    /* ------------------------------ Internals ----------------------------- */

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
                $rows[] = [
                    'product'  => sprintf('#%d %s', (int) $issue['product_id'], ProductSearch::label((int) $issue['product_id'])),
                    'sum'      => $this->qty((float) $issue['sum']),
                    'on_hand'  => $this->qty((float) $issue['on_hand']),
                    '_stock'   => $this->qty((float) $issue['stock']),
                    'lookup'   => $issue['lookup'] === null ? 'NULL' : $this->qty((float) $issue['lookup']),
                    'has_tail' => !empty($issue['has_tail']) ? 'yes' : 'no',
                ];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['product', 'sum', 'on_hand', '_stock', 'lookup', 'has_tail']);
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

    /**
     * A Y-m-d filter bound → UTC 'Y-m-d H:i:s'. Site-local midnight for the lower
     * bound, 23:59:59 for the upper, so an inclusive day range means what an
     * operator expects.
     */
    private function dateBoundary(string $date, bool $endOfDay): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            \WP_CLI::error(sprintf("Date '%s' must be Y-m-d.", $date));
        }
        return \get_gmt_from_date($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
    }

    /** Integer-clean quantity rendering (v1 is integer-only; keep CSV tidy). */
    private function qty(float $value): string {
        if (abs($value - round($value)) < 1e-9) {
            return (string) (int) round($value);
        }
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
