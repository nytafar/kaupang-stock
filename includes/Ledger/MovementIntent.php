<?php
declare(strict_types=1);

namespace Kaupang\Stock\Ledger;

/**
 * What a caller wants recorded — validated and completed by Ledger::record().
 * product_id must already be the stock-managing entity
 * (WC_Product::get_stock_managed_by_id()), exactly like core keys _stock.
 */
final class MovementIntent {

    public int $productId;
    public float $delta;
    public string $reason;
    public ?string $refType;
    public ?int $refId;
    public ?int $refLine;
    public ?string $batch;
    public ?int $actorId;      // null → current user at record time
    public string $via;        // '' → detected at record time
    public ?string $note;
    /** UTC 'Y-m-d H:i:s'; null → now. Operator-editable for receipts/adjustments. */
    public ?string $occurredAt;
    public ?string $idempotencyKey;
    /** 0 → default location. */
    public int $locationId;

    public function __construct(
        int $productId,
        float $delta,
        string $reason,
        ?string $refType = null,
        ?int $refId = null,
        ?int $refLine = null,
        ?string $batch = null,
        ?int $actorId = null,
        string $via = '',
        ?string $note = null,
        ?string $occurredAt = null,
        ?string $idempotencyKey = null,
        int $locationId = 0
    ) {
        $this->productId       = $productId;
        $this->delta           = $delta;
        $this->reason          = $reason;
        $this->refType         = $refType;
        $this->refId           = $refId;
        $this->refLine         = $refLine;
        $this->batch           = $batch;
        $this->actorId         = $actorId;
        $this->via             = $via;
        $this->note            = $note;
        $this->occurredAt      = $occurredAt;
        $this->idempotencyKey  = $idempotencyKey;
        $this->locationId      = $locationId;
    }
}
