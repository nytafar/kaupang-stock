<?php
declare(strict_types=1);

namespace Kaupang\Stock\Ledger;

/**
 * Immutable DTO for one recorded ledger row. Movements are never edited or
 * deleted; corrections are reversing entries referencing the original.
 */
final class Movement {

    public readonly int $id;
    public readonly int $productId;
    public readonly int $locationId;
    public readonly float $delta;
    public readonly float $balanceAfter;
    public readonly string $reason;
    public readonly ?string $refType;
    public readonly ?int $refId;
    public readonly ?int $refLine;
    public readonly ?string $batch;
    public readonly int $actorId;
    public readonly string $via;
    public readonly ?string $note;
    public readonly ?string $idempotencyKey;
    public readonly string $occurredAt;
    public readonly string $createdAt;

    private function __construct(
        int $id,
        int $productId,
        int $locationId,
        float $delta,
        float $balanceAfter,
        string $reason,
        ?string $refType,
        ?int $refId,
        ?int $refLine,
        ?string $batch,
        int $actorId,
        string $via,
        ?string $note,
        ?string $idempotencyKey,
        string $occurredAt,
        string $createdAt
    ) {
        $this->id             = $id;
        $this->productId      = $productId;
        $this->locationId     = $locationId;
        $this->delta          = $delta;
        $this->balanceAfter   = $balanceAfter;
        $this->reason         = $reason;
        $this->refType        = $refType;
        $this->refId          = $refId;
        $this->refLine        = $refLine;
        $this->batch          = $batch;
        $this->actorId        = $actorId;
        $this->via            = $via;
        $this->note           = $note;
        $this->idempotencyKey = $idempotencyKey;
        $this->occurredAt     = $occurredAt;
        $this->createdAt      = $createdAt;
    }

    /** @param array<string,mixed> $row raw movements-table row */
    public static function fromRow(array $row): self {
        return new self(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['location_id'],
            (float) $row['delta'],
            (float) $row['balance_after'],
            (string) $row['reason'],
            isset($row['ref_type']) && $row['ref_type'] !== null && $row['ref_type'] !== '' ? (string) $row['ref_type'] : null,
            isset($row['ref_id']) && $row['ref_id'] !== null ? (int) $row['ref_id'] : null,
            isset($row['ref_line']) && $row['ref_line'] !== null ? (int) $row['ref_line'] : null,
            isset($row['batch']) && $row['batch'] !== null && $row['batch'] !== '' ? (string) $row['batch'] : null,
            (int) ($row['actor_id'] ?? 0),
            (string) ($row['via'] ?? ''),
            isset($row['note']) && $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
            isset($row['idempotency_key']) && $row['idempotency_key'] !== null && $row['idempotency_key'] !== '' ? (string) $row['idempotency_key'] : null,
            (string) $row['occurred_at'],
            (string) $row['created_at']
        );
    }
}
