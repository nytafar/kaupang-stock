<?php
declare(strict_types=1);

namespace Kaupang\Stock\Purchasing;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Reasons;
use Kaupang\Stock\Logging\Logger;
use Kaupang\Stock\Settings;

/**
 * The heart of innkjøp: turning a receiving session into ledger movements (§6.4).
 *
 * A receipt is NOT a table — it is a movement batch (§1.3): one shared UUID, one
 * operator action, reason=receipt, ref_type=po_line. N receiving sessions per PO.
 *
 * Two invariants make this safe:
 *
 *  1. Idempotency. The receive FORM mints a token (UUID) at LOAD. That token is
 *     BOTH the batch id AND the per-line idempotency key
 *     `po_line:{lineId}:receive:{token}`. A double-click, a timeout resend, or a
 *     replay of the same form therefore dedupes — the Ledger checks the key under
 *     the balance-row lock and returns the existing movement, so the same posting
 *     happens exactly ONCE (Ledger::recordBatch($intents, $token) with the token
 *     as the batch argument).
 *
 *  2. Server-side remainder recheck. At submit the server RE-DERIVES each line's
 *     true remainder (ordered − Σ received) from the movements — never trusts the
 *     client's pre-fill, which may be stale (another operator received in between).
 *     Any line whose entered qty exceeds the live remainder becomes an issue row.
 *     If there are issues and the caller did not confirm, we return
 *     confirm_required WITHOUT posting; the dialog is driven by this check, not by
 *     the client. Over-receipt is ALLOWED once confirmed (supplier overs are
 *     normal) — the extra delta is plainly visible in the ledger, never clamped.
 */
final class Receiving {

    /**
     * Post a receiving session against a PO.
     *
     * @param int                $poId          the purchase order
     * @param string             $token         client-minted receive token (UUID) — batch id + idem key seed
     * @param array<int,float>   $lines         [line_id => qty]; qty 0 / negative lines are skipped
     * @param string|null        $occurredAtUtc UTC 'Y-m-d H:i:s' (operator-editable "varene kom i forrige uke") or null=now
     * @param bool               $confirmed     true once the operator acknowledged the over-receipt / changed-PO dialog
     * @param array<int,int>     $costs         [line_id => actual unit cost ex-VAT in øre] for THIS session —
     *                                          partial deliveries may be invoiced at different prices, so the
     *                                          actual cost is captured per receive, not per PO line. Stashed
     *                                          durably (CostInputs, keyed by the line's idempotency key) BEFORE
     *                                          the ledger posts, because the costing fold runs inside
     *                                          recordBatch's post-commit hook and rebuilds re-derive from inputs.
     *
     * @return array{status:string,issues:array<int,array<string,mixed>>,batch:string,movements:int,po_status:string,message:string}
     *         status is one of ok | confirm_required | error.
     */
    public static function receive(int $poId, string $token, array $lines, ?string $occurredAtUtc, bool $confirmed, array $costs = []): array {
        $po = PurchaseOrders::find($poId);
        if ($po === null) {
            return self::result('error', [], '', 0, '', \__('Purchase order not found.', 'kaupang-stock'));
        }

        $token = self::normalizeToken($token);
        if ($token === '') {
            return self::result('error', [], '', 0, (string) $po['status'], \__('A receive token is required.', 'kaupang-stock'));
        }

        // Gate: receipts are owned writes — the PO must be an open, ordered PO
        // (operator status `ordered`, which derives to ordered/partial). draft,
        // cancelled and fully-received POs cannot take a receipt.
        $status = (string) $po['status'];
        if ($status !== PurchaseOrders::STATUS_ORDERED) {
            return self::result(
                'error',
                [],
                '',
                0,
                $status,
                \__('This purchase order is not open for receiving (it must be ordered).', 'kaupang-stock')
            );
        }

        // Owned writes require active mode. Surface this cleanly rather than
        // letting Ledger::recordBatch throw (validate() rejects owned reasons in
        // shadow/disabled mode).
        if (!Settings::activeMode()) {
            return self::result(
                'error',
                [],
                '',
                0,
                self::deriveStatus($po),
                \__('Receiving writes stock and requires active mode. Enable active mode under Lager → Innstillinger.', 'kaupang-stock')
            );
        }

        // Re-derive every line's live received/remaining from the movements.
        $poLines = Lines::forPoWithReceived($poId);
        $byId    = [];
        foreach ($poLines as $line) {
            $byId[(int) $line['id']] = $line;
        }

        // Build the requested postings, re-checking each entered qty against the
        // live remainder. Lines the operator left at 0 are skipped entirely.
        $intents = [];
        $issues  = [];
        $note    = self::contextNote($po);
        foreach ($lines as $lineId => $qty) {
            $lineId = (int) $lineId;
            $qty    = (float) $qty;
            if ($qty <= 1e-9) {
                continue; // skipped: nothing received on this line this session
            }
            if (!isset($byId[$lineId])) {
                // A line id that is not on this PO — reject the whole session so a
                // malformed submit never posts a partial mystery batch.
                return self::result(
                    'error',
                    [],
                    '',
                    0,
                    self::deriveStatus($po),
                    \__('A submitted line does not belong to this purchase order.', 'kaupang-stock')
                );
            }

            $line      = $byId[$lineId];
            $ordered   = (float) $line['qty_ordered'];
            $received  = (float) $line['received'];
            $remaining = (float) $line['remaining'];

            // Over-receipt or a PO that changed since the form loaded: surface it.
            if ($qty > $remaining + 1e-9) {
                $issues[] = [
                    'line_id'    => $lineId,
                    'product_id' => (int) $line['product_id'],
                    'label'      => (string) $line['product_label'],
                    'ordered'    => $ordered,
                    'received'   => $received,
                    'remaining'  => $remaining,
                    'entered'    => $qty,
                ];
            }

            $intents[] = new MovementIntent(
                (int) $line['product_id'],
                $qty,                                  // positive: goods in
                Reasons::RECEIPT,
                'po_line',
                $lineId,
                null,
                $token,                                // batch (also set by recordBatch, but explicit here)
                null,
                '',
                $note,
                $occurredAtUtc,                        // operator-editable, already UTC or null
                'po_line:' . $lineId . ':receive:' . $token,
                0
            );
        }

        if ($intents === []) {
            return self::result(
                'error',
                [],
                '',
                0,
                self::deriveStatus($po),
                \__('Nothing to receive — enter a quantity on at least one line.', 'kaupang-stock')
            );
        }

        // Issues found and the operator has not confirmed → do NOT post. One
        // combined dialog is driven by these server-side rows (never the stale
        // client pre-fill).
        if ($issues !== [] && !$confirmed) {
            return self::result(
                'confirm_required',
                $issues,
                '',
                0,
                self::deriveStatus($po),
                \__('Some lines exceed the remaining quantity. Confirm to receive anyway (over-receipt is allowed).', 'kaupang-stock')
            );
        }

        // Stash entered actual costs BEFORE posting: the costing fold fires
        // inside recordBatch (post-commit) and resolves them by the same
        // idempotency key the movement carries. REPLACE-idempotent, so a
        // confirm-resend of the same token is harmless.
        if ($costs !== [] && \Kaupang\Stock\Costing\Costing::enabled()) {
            $stash = [];
            foreach ($intents as $intent) {
                $lineId = (int) $intent->refId;
                if (isset($costs[$lineId]) && (int) $costs[$lineId] >= 0 && $intent->idempotencyKey !== null) {
                    $stash[$intent->idempotencyKey] = (int) $costs[$lineId];
                }
            }
            if ($stash !== []) {
                try {
                    \Kaupang\Stock\Costing\CostInputs::stashMany($stash);
                } catch (\Throwable $e) {
                    Logger::error('receive_cost_stash_failed', ['po' => $poId, 'token' => $token, 'error' => $e->getMessage()]);
                }
            }
        }

        // Post the batch. The token is the batch id; the per-line idempotency keys
        // make a resend/double-submit dedupe to one posting inside recordBatch.
        try {
            $movements = Ledger::recordBatch($intents, $token);
        } catch (LedgerException $e) {
            Logger::error('receive_failed', ['po' => $poId, 'token' => $token, 'error' => $e->getMessage()]);
            return self::result('error', [], '', 0, self::deriveStatus($po), $e->getMessage());
        }

        // A posting may have brought the PO to fully-received — stamp closed_at the
        // first time it derives to received (received status stays derived).
        PurchaseOrders::markClosedIfReceived($poId);

        $freshStatus = PurchaseOrders::statusFor($poId);

        return self::result(
            'ok',
            [],
            $token,
            count($movements),
            $freshStatus,
            \__('Goods received.', 'kaupang-stock')
        );
    }

    /**
     * A note string carrying supplier/PO context onto each receipt movement — the
     * provenance the Bevegelser log shows next to the movement. Kept within the
     * 255-char note column (the Ledger also truncates).
     */
    private static function contextNote(array $po): string {
        $poId     = (int) $po['id'];
        $ref      = trim((string) ($po['supplier_ref'] ?? ''));
        $supplier = Suppliers::name(isset($po['supplier_id']) ? (int) $po['supplier_id'] : null);

        $parts = [sprintf('Varemottak innkjøp #%d', $poId)];
        if ($supplier !== '' && $supplier !== '—') {
            $parts[] = $supplier;
        }
        if ($ref !== '') {
            $parts[] = 'ref: ' . $ref;
        }
        return mb_substr(implode(' · ', $parts), 0, 255);
    }

    /** Derive the PO's full status for the response (loads its lines). */
    private static function deriveStatus(array $po): string {
        return PurchaseOrders::derivedStatus($po, Lines::forPoWithReceived((int) $po['id']));
    }

    /** UUID-ish tokens only: strip to a safe 36-char id (no control/injection risk). */
    private static function normalizeToken(string $token): string {
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $token)) {
            return '';
        }
        return substr($token, 0, 64);
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return array{status:string,issues:array<int,array<string,mixed>>,batch:string,movements:int,po_status:string,message:string}
     */
    private static function result(string $status, array $issues, string $batch, int $movements, string $poStatus, string $message): array {
        return [
            'status'    => $status,
            'issues'    => $issues,
            'batch'     => $batch,
            'movements' => $movements,
            'po_status' => $poStatus,
            'message'   => $message,
        ];
    }
}
