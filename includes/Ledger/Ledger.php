<?php
declare(strict_types=1);

namespace Kaupang\Stock\Ledger;

use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Locations;
use Kaupang\Stock\Schema;
use Kaupang\Stock\Settings;

/**
 * THE single write path (§0 principle 2). Every mutation the suite owns —
 * adjustment, receipt, count apply, reversal — and every observed WooCommerce
 * change becomes a movement through record()/recordBatch()/absorbResidual().
 * No second code path ever touches the tables.
 *
 * Transaction shape per intent (§3):
 *   lock balance row FOR UPDATE → idempotency re-check → maybe seed →
 *   INSERT movement (balance_after = on_hand + delta) → UPDATE balances.
 * Ledger and its projection commit together and can never diverge from each
 * other. Write-through to WooCommerce happens AFTER commit (holding a DB
 * transaction across WC_Product::save() would drag arbitrary third-party hook
 * code into it) — see WriteThrough.
 *
 * v1 is integer-only: core's default `woocommerce_stock_amount → intval` filter
 * truncates every core-mediated value, so a fractional write-through would
 * silently apply ±0 to _stock and poison the ledger with phantom residuals.
 */
final class Ledger {

    /** Operator-editable occurred_at may reach at most this far back. */
    private const MAX_BACKDATE_DAYS = 90;

    /* ------------------------------ Public API ---------------------------- */

    public static function record(MovementIntent $intent): Movement {
        $result = self::recordBatch([$intent]);
        return $result[0];
    }

    /**
     * One transaction for a multi-line operator action (receiving, count apply).
     * The batch UUID is caller-supplied where retry-stable idempotency keys need
     * it (the receive token) and minted otherwise. Intents are sorted by
     * (product, location) before locking — deterministic lock order, so two
     * concurrent batches cannot deadlock on opposite orderings.
     *
     * @param MovementIntent[] $intents
     * @return Movement[] in the sorted processing order
     */
    public static function recordBatch(array $intents, ?string $batch = null): array {
        if (empty($intents)) {
            return [];
        }
        foreach ($intents as $intent) {
            if (!$intent instanceof MovementIntent) {
                throw new LedgerException('recordBatch expects MovementIntent[]');
            }
            self::validate($intent);
        }

        if ($batch === null && count($intents) > 1) {
            $batch = \wp_generate_uuid4();
        }
        if ($batch !== null) {
            foreach ($intents as $intent) {
                if ($intent->batch === null) {
                    $intent->batch = $batch;
                }
            }
        }

        usort($intents, static function (MovementIntent $a, MovementIntent $b): int {
            return [$a->productId, Balances::resolveLocation($a->locationId)]
                <=> [$b->productId, Balances::resolveLocation($b->locationId)];
        });

        // One retry on deadlock/lock-timeout, mirroring core's ReserveStock loop.
        // Safe: rollback wipes the first attempt entirely; owned intents carry
        // idempotency keys on top.
        $results = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $results = self::runBatchTransaction($intents);
                break;
            } catch (LedgerException $e) {
                $retryable = $attempt === 1 && (
                    stripos($e->getMessage(), 'deadlock') !== false
                    || stripos($e->getMessage(), 'lock wait timeout') !== false
                );
                if (!$retryable) {
                    throw $e;
                }
                Logger::warning('ledger_retry_after_lock_error', ['error' => $e->getMessage()]);
            }
        }
        if ($results === null) {
            throw new LedgerException('Ledger batch failed after retry');
        }

        // Post-commit: write owned movements through to WooCommerce (active mode),
        // complete write-throughs a prior crashed attempt left pending (§3 step 3),
        // then announce. Observed movements never write through — Woo already moved.
        $movements = [];
        foreach ($results as [$movement, $wasExisting]) {
            /** @var Movement $movement */
            if (Reasons::isOwned($movement->reason) && Settings::activeMode()) {
                if ($wasExisting) {
                    WriteThrough::completeIfPending($movement);
                } else {
                    WriteThrough::apply($movement);
                }
            }
            if (!$wasExisting) {
                \do_action('kaupang/stock/movement_recorded', $movement);
            }
            $movements[] = $movement;
        }
        return $movements;
    }

    /**
     * Operator adjustment (the Lagerstatus quick adjust / Bevegelser form / CLI).
     * Note is mandatory — it is the audit trail on a single-operator store.
     */
    public static function adjust(
        int $productId,
        float $delta,
        string $note,
        ?string $occurredAt = null,
        ?string $idempotencyKey = null,
        int $locationId = 0
    ): Movement {
        return self::record(new MovementIntent(
            $productId,
            $delta,
            Reasons::ADJUST,
            null,
            null,
            null,
            null,
            null,
            '',
            $note,
            $occurredAt,
            $idempotencyKey,
            $locationId
        ));
    }

    /**
     * Compensating entry for an existing movement (§5 immutability: history is
     * never edited — "Reverser" creates a new movement pointing at the original).
     * Idempotent per original movement: one reversal, ever.
     */
    public static function reverse(int $movementId, string $note): Movement {
        $original = self::find($movementId);
        if ($original === null) {
            throw new LedgerException("Movement $movementId not found");
        }
        if ($original->reason === Reasons::REVERSAL) {
            throw new LedgerException('Refusing to reverse a reversal — record a fresh adjustment instead');
        }
        return self::record(new MovementIntent(
            $original->productId,
            -$original->delta,
            Reasons::REVERSAL,
            'movement',
            $original->id,
            null,
            null,
            null,
            '',
            $note,
            null,
            'reversal:' . $original->id,
            $original->locationId
        ));
    }

    /**
     * Enable-time seeding (§4 path a): balance row for every stock-managed
     * product; an `initial` movement only where _stock ≠ 0 (a zero-stock product
     * is seeded by its bare balance row — the no-zero-delta rule). Returns true
     * if the product was newly seeded.
     */
    public static function seedProduct(int $productId, int $locationId = 0): bool {
        $locationId = Balances::resolveLocation($locationId);
        self::begin();
        try {
            $row = Balances::lockRow($productId, $locationId);
            if ((int) $row['last_movement_id'] > 0 || self::hasMovements($productId)) {
                self::commit();
                return false;
            }
            $fresh = self::readStockDirect($productId);
            if (abs($fresh) < 1e-9) {
                self::commit();
                return true; // bare balance row is the seed
            }
            $movementId = self::insertMovementRow([
                'product_id'      => $productId,
                'location_id'     => $locationId,
                'delta'           => $fresh,
                'balance_after'   => $fresh,
                'reason'          => Reasons::INITIAL,
                'actor_id'        => 0,
                'via'             => 'system',
                'note'            => null,
                'idempotency_key' => 'initial:' . $productId,
                'occurred_at'     => gmdate('Y-m-d H:i:s'),
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            // The seed mirrors what Woo already holds, so the watermark advances.
            Balances::updateLocked($productId, $locationId, $fresh, $movementId, $movementId);
            self::commit();
            return true;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /**
     * Shutdown/reconcile true-up (§4 mechanics): lock the balance row FIRST,
     * then read _stock — the lock-then-read order is what makes concurrent
     * true-ups of the same product serialize instead of double-recording one
     * residual. Records residual = _stock − SUM(on_hand) with the best pending
     * claim's attribution, `initial` on first sight (path c), else `external`.
     *
     * @param array<string,mixed>|null $claim {reason, ref_type, ref_id, ref_line, via, location_id}
     */
    public static function absorbResidual(int $productId, int $locationId = 0, ?array $claim = null, ?string $note = null): ?Movement {
        if (is_array($claim) && !empty($claim['location_id'])) {
            $locationId = (int) $claim['location_id'];
        }
        $locationId = Balances::resolveLocation($locationId);
        self::begin();
        try {
            $rows     = Balances::lockProductRows($productId, $locationId);
            $row      = $rows[$locationId];
            $fresh    = self::readStockDirect($productId);
            $onHand   = (float) $row['on_hand'];
            $aggregate = array_sum(array_map(static fn (array $r): float => (float) $r['on_hand'], $rows));
            $residual = $fresh - $aggregate;

            $unseeded = !self::hasMovements($productId);

            if (abs($residual) < 1e-9) {
                self::commit();
                return null;
            }

            $now = gmdate('Y-m-d H:i:s');
            if ($unseeded) {
                // First sight via the absorber: the whole residual IS the
                // opening balance (§4 seeding path c).
                $movementId = self::insertMovementRow([
                    'product_id'      => $productId,
                    'location_id'     => $locationId,
                    'delta'           => $residual,
                    'balance_after'   => $residual,
                    'reason'          => Reasons::INITIAL,
                    'actor_id'        => 0,
                    'via'             => 'system',
                    'note'            => $note,
                    'idempotency_key' => 'initial:' . $productId,
                    'occurred_at'     => $now,
                    'created_at'      => $now,
                ]);
                Balances::updateLocked($productId, $locationId, $onHand + $residual, $movementId, $movementId);
                self::commit();
                return self::find($movementId);
            }

            $reason = is_array($claim) && isset($claim['reason']) && Reasons::isValid((string) $claim['reason'])
                ? (string) $claim['reason']
                : Reasons::EXTERNAL;

            $balanceAfter = $onHand + $residual; // ≡ $fresh
            $movementId   = self::insertMovementRow([
                'product_id'      => $productId,
                'location_id'     => $locationId,
                'delta'           => $residual,
                'balance_after'   => $balanceAfter,
                'reason'          => $reason,
                'ref_type'        => isset($claim['ref_type']) ? (string) $claim['ref_type'] : null,
                'ref_id'          => isset($claim['ref_id']) ? (int) $claim['ref_id'] : null,
                'ref_line'        => isset($claim['ref_line']) ? (int) $claim['ref_line'] : null,
                'actor_id'        => isset($claim['actor_id']) ? (int) $claim['actor_id'] : \get_current_user_id(),
                'via'             => isset($claim['via']) ? (string) $claim['via'] : '',
                'note'            => $note,
                'idempotency_key' => null,
                'occurred_at'     => $now,
                'created_at'      => $now,
            ]);

            // Residuals mirror a change Woo already holds — advance the watermark
            // unless an unwritten owned tail sits beneath it (it must stay
            // detectable for WriteThrough::healProduct()).
            $noGap = (int) $row['last_written_id'] === (int) $row['last_movement_id'];
            Balances::updateLocked($productId, $locationId, $balanceAfter, $movementId, $noGap ? $movementId : null);
            self::commit();
            return self::find($movementId);
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    public static function find(int $movementId): ?Movement {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::movements() . ' WHERE id = %d',
            $movementId
        ), ARRAY_A);
        return is_array($row) ? Movement::fromRow($row) : null;
    }

    public static function findByKey(string $idempotencyKey): ?Movement {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Schema::movements() . ' WHERE idempotency_key = %s',
            $idempotencyKey
        ), ARRAY_A);
        return is_array($row) ? Movement::fromRow($row) : null;
    }

    /**
     * Fresh _stock via direct SQL against postmeta — the same statement shape
     * core's own ±delta path uses. Never get_post_meta()/wc_get_product(), which
     * can serve a stale Redis object-cache entry mid-request.
     */
    public static function readStockDirect(int $productId): float {
        global $wpdb;
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' LIMIT 1",
            $productId
        ));
        return $val !== null ? (float) $val : 0.0;
    }

    /* ------------------------------ Internals ----------------------------- */

    /** @param MovementIntent[] $intents  @return array<int,array{0:Movement,1:bool}> */
    private static function runBatchTransaction(array $intents): array {
        self::begin();
        try {
            $results = [];
            foreach ($intents as $intent) {
                $results[] = self::processIntentLocked($intent);
            }
            self::commit();
            return $results;
        } catch (\Throwable $e) {
            self::rollback();
            if ($e instanceof LedgerException) {
                throw $e;
            }
            throw new LedgerException($e->getMessage(), 0, $e);
        }
    }

    /** @return array{0:Movement,1:bool} [movement, wasExisting] */
    private static function processIntentLocked(MovementIntent $intent): array {
        $locationId = Balances::resolveLocation($intent->locationId);
        $row        = Balances::lockRow($intent->productId, $locationId);

        // Idempotent retry: the key is checked under the same row lock that
        // serialises writers, so a double-submit dedupes to the original row.
        if ($intent->idempotencyKey !== null) {
            $existing = self::findByKey($intent->idempotencyKey);
            if ($existing !== null) {
                return [$existing, true];
            }
        }

        $onHand       = (float) $row['on_hand'];
        $lastMovement = (int) $row['last_movement_id'];
        $lastWritten  = (int) $row['last_written_id'];

        // First ledger touch for this product → seed (§4 path b: rich-hook /
        // owned first sight). For observed intents Woo's _stock already moved,
        // so the opening balance is fresh − delta; for owned intents it is
        // fresh as-is (the write-through has not run yet).
        if ($lastMovement === 0 && $intent->reason !== Reasons::INITIAL
            && !self::hasMovements($intent->productId)
        ) {
            $fresh    = self::readStockDirect($intent->productId);
            $seedBase = $fresh - (Reasons::isOwned($intent->reason) ? 0.0 : $intent->delta);
            if (abs($seedBase) > 1e-9) {
                $now    = gmdate('Y-m-d H:i:s');
                $seedId = self::insertMovementRow([
                    'product_id'      => $intent->productId,
                    'location_id'     => $locationId,
                    'delta'           => $seedBase,
                    'balance_after'   => $seedBase,
                    'reason'          => Reasons::INITIAL,
                    'actor_id'        => 0,
                    'via'             => 'system',
                    'note'            => null,
                    'idempotency_key' => 'initial:' . $intent->productId,
                    'occurred_at'     => $now,
                    'created_at'      => $now,
                ]);
                $onHand       = $seedBase;
                $lastMovement = $seedId;
                $lastWritten  = $seedId;
            }
        }

        $balanceAfter = $onHand + $intent->delta;
        $allowNegative = (bool) Settings::get('allow_negative_locations', true);
        if (Reasons::isOwned($intent->reason) && $balanceAfter < 0
            && !\apply_filters('kaupang/stock/allow_negative', $allowNegative, $intent, $row)
        ) {
            throw new LedgerException(sprintf(
                'Negative stock refused for product %d (on hand %s, delta %s)',
                $intent->productId,
                (string) $onHand,
                (string) $intent->delta
            ));
        }

        $movementId = self::insertMovementRow([
            'product_id'      => $intent->productId,
            'location_id'     => $locationId,
            'delta'           => $intent->delta,
            'balance_after'   => $balanceAfter,
            'reason'          => $intent->reason,
            'ref_type'        => $intent->refType,
            'ref_id'          => $intent->refId,
            'ref_line'        => $intent->refLine,
            'batch'           => $intent->batch,
            'actor_id'        => $intent->actorId ?? \get_current_user_id(),
            'via'             => $intent->via !== '' ? $intent->via : self::currentVia(),
            'note'            => $intent->note,
            'idempotency_key' => $intent->idempotencyKey,
            'occurred_at'     => $intent->occurredAt ?? gmdate('Y-m-d H:i:s'),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        // Watermark: owned movements advance it only after a successful
        // write-through (WriteThrough::apply). Observed movements are already in
        // _stock, so they advance it — but only when no unwritten owned tail
        // sits beneath (else the tail would slip out of the heal window).
        $advance = null;
        if (!Reasons::isOwned($intent->reason) && $lastWritten === $lastMovement) {
            $advance = $movementId;
        }
        Balances::updateLocked($intent->productId, $locationId, $balanceAfter, $movementId, $advance);

        return [self::find($movementId), false];
    }

    private static function validate(MovementIntent $intent): void {
        if ($intent->productId <= 0) {
            throw new LedgerException('Movement requires a product id');
        }
        if ($intent->locationId > 0 && !Locations::isActive($intent->locationId)) {
            throw new LedgerException("Unknown or inactive location {$intent->locationId}");
        }
        if (abs($intent->delta) < 1e-9) {
            throw new LedgerException('Movement delta must not be 0');
        }
        if (!Reasons::isValid($intent->reason)) {
            throw new LedgerException("Unknown movement reason '{$intent->reason}'");
        }
        if (Reasons::requiresNote($intent->reason) && trim((string) $intent->note) === '') {
            throw new LedgerException("Movements with reason '{$intent->reason}' require a note");
        }
        if (Reasons::isOwned($intent->reason) && !Settings::activeMode()) {
            throw new LedgerException('Owned stock operations require mode=active (currently shadow/disabled)');
        }

        // v1 integer guard (§2): while core truncates every mediated value via
        // the default woocommerce_stock_amount → intval filter, a fractional
        // delta would write ±0 into _stock and leave a phantom residual.
        if (function_exists('wc_is_stock_amount_integer') && \wc_is_stock_amount_integer()
            && abs($intent->delta - round($intent->delta)) > 1e-9
        ) {
            throw new LedgerException('Fractional stock is not supported while WooCommerce stock amounts are integers');
        }

        if ($intent->idempotencyKey !== null && strlen($intent->idempotencyKey) > 100) {
            throw new LedgerException('Idempotency key exceeds 100 characters');
        }

        if ($intent->note !== null) {
            $intent->note = mb_substr($intent->note, 0, 255);
        }
        if ($intent->via !== '') {
            $intent->via = mb_substr($intent->via, 0, 40);
        }

        if ($intent->occurredAt !== null) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $intent->occurredAt, new \DateTimeZone('UTC'));
            if ($dt === false || $dt->format('Y-m-d H:i:s') !== $intent->occurredAt) {
                throw new LedgerException('occurred_at must be a UTC Y-m-d H:i:s datetime');
            }
            $now = time();
            if ($dt->getTimestamp() > $now + 5 * MINUTE_IN_SECONDS) {
                throw new LedgerException('occurred_at cannot be in the future');
            }
            if ($dt->getTimestamp() < $now - self::MAX_BACKDATE_DAYS * DAY_IN_SECONDS) {
                throw new LedgerException(sprintf('occurred_at cannot be more than %d days back', self::MAX_BACKDATE_DAYS));
            }
        }
    }

    private static function hasMovements(int $productId, ?int $locationId = null): bool {
        global $wpdb;
        if ($locationId === null) {
            $id = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . Schema::movements() . ' WHERE product_id = %d LIMIT 1',
                $productId
            ));
        } else {
            $id = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . Schema::movements() . ' WHERE product_id = %d AND location_id = %d LIMIT 1',
                $productId,
                $locationId
            ));
        }
        return $id !== null;
    }

    /** @param array<string,mixed> $data  @return int new movement id */
    private static function insertMovementRow(array $data): int {
        global $wpdb;

        $columns = [
            'product_id'      => '%d',
            'location_id'     => '%d',
            'delta'           => '%s',
            'balance_after'   => '%s',
            'reason'          => '%s',
            'ref_type'        => '%s',
            'ref_id'          => '%d',
            'ref_line'        => '%d',
            'batch'           => '%s',
            'actor_id'        => '%d',
            'via'             => '%s',
            'note'            => '%s',
            'idempotency_key' => '%s',
            'occurred_at'     => '%s',
            'created_at'      => '%s',
        ];
        $row     = [];
        $formats = [];
        foreach ($columns as $column => $format) {
            if (!array_key_exists($column, $data) || $data[$column] === null) {
                continue; // omitted → SQL NULL / column default
            }
            $value = $data[$column];
            if ($column === 'delta' || $column === 'balance_after') {
                $value = number_format((float) $value, 3, '.', '');
            }
            $row[$column]  = $value;
            $formats[]     = $format;
        }

        $ok = $wpdb->insert(Schema::movements(), $row, $formats);
        if ($ok === false || $wpdb->insert_id <= 0) {
            throw new LedgerException('Movement insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /** Request context for attribution when the caller didn't say. */
    public static function currentVia(): string {
        if (defined('WP_CLI') && \WP_CLI) {
            return 'cli';
        }
        if (function_exists('wp_doing_cron') && \wp_doing_cron()) {
            return 'cron';
        }
        if (function_exists('wp_doing_ajax') && \wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? \sanitize_key((string) $_REQUEST['action']) : '';
            return $action !== '' ? 'ajax:' . $action : 'ajax';
        }
        if (defined('REST_REQUEST') && \REST_REQUEST) {
            return 'rest';
        }
        if (function_exists('is_admin') && \is_admin()) {
            return 'admin';
        }
        return 'checkout';
    }

    private static function begin(): void {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new LedgerException('Could not open transaction: ' . $wpdb->last_error);
        }
    }

    private static function commit(): void {
        global $wpdb;
        if ($wpdb->query('COMMIT') === false) {
            throw new LedgerException('Commit failed: ' . $wpdb->last_error);
        }
    }

    private static function rollback(): void {
        global $wpdb;
        $wpdb->query('ROLLBACK');
    }
}
