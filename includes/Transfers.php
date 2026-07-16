<?php
declare(strict_types=1);

namespace Kaupang\Stock;

use Kaupang\Stock\Ledger\Ledger;
use Kaupang\Stock\Ledger\LedgerException;
use Kaupang\Stock\Ledger\Movement;
use Kaupang\Stock\Ledger\MovementIntent;
use Kaupang\Stock\Ledger\Reasons;

/**
 * Immediate stock transfers between two locations.
 *
 * A transfer is not a document. The shared batch token is its durable identity,
 * and the two owned ledger movements are the complete audit trail. Retrying the
 * same token is safe because each side carries a stable idempotency key.
 */
final class Transfers {

    /**
     * @return Movement[] transfer pair in Ledger's deterministic processing order
     */
    public static function transfer(
        int $productId,
        int $quantity,
        int $sourceLocationId,
        int $destinationLocationId,
        string $token,
        ?string $note = null
    ): array {
        if (!Settings::activeMode()) {
            throw new LedgerException('Transfers require mode=active (currently shadow/disabled)');
        }
        if (!\current_user_can(Settings::capability())) {
            throw new LedgerException('You are not allowed to transfer stock');
        }
        if ($productId <= 0) {
            throw new LedgerException('Transfer requires a product id');
        }
        $product = function_exists('wc_get_product') ? \wc_get_product($productId) : false;
        if (!$product instanceof \WC_Product || !$product->managing_stock()
            || (int) $product->get_stock_managed_by_id() !== $productId
        ) {
            throw new LedgerException('Transfer requires the stock-managing product id');
        }
        if ($quantity <= 0) {
            throw new LedgerException('Transfer quantity must be a positive integer');
        }
        if ($sourceLocationId <= 0 || !Locations::isActive($sourceLocationId)) {
            throw new LedgerException('Transfer source must be an active location');
        }
        if ($destinationLocationId <= 0 || !Locations::isActive($destinationLocationId)) {
            throw new LedgerException('Transfer destination must be an active location');
        }
        if ($sourceLocationId === $destinationLocationId) {
            throw new LedgerException('Transfer source and destination must be different locations');
        }

        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9-]{8,36}$/', $token)) {
            throw new LedgerException('Transfer token must be 8–36 letters, numbers, or hyphens');
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        $intents = [
            new MovementIntent(
                $productId,
                -$quantity,
                Reasons::TRANSFER_OUT,
                'transfer',
                0,
                null,
                $token,
                null,
                '',
                $note,
                null,
                sprintf('transfer:%s:%d:out', $token, $productId),
                $sourceLocationId
            ),
            new MovementIntent(
                $productId,
                $quantity,
                Reasons::TRANSFER_IN,
                'transfer',
                0,
                null,
                $token,
                null,
                '',
                $note,
                null,
                sprintf('transfer:%s:%d:in', $token, $productId),
                $destinationLocationId
            ),
        ];

        return Ledger::recordBatch($intents, $token);
    }
}
