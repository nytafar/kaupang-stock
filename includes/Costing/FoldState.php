<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

/**
 * In-memory state for one fold transaction: the product's open layers in FIFO
 * order, its outstanding provisionals, and the last known unit cost. Loaded
 * fresh inside the transaction (sees committed rows; the engine keeps it in
 * step with its own uncommitted inserts as it folds).
 */
final class FoldState {

    /** @var array<int,array{id:int,source_movement_id:?int,unit_cost_ore:?int,remaining:float,occurred_at:string}> FIFO order */
    private array $openLayers = [];

    /** @var array<int,array{movement_id:int,outstanding:float,unit_cost_ore:?int}> oldest movement first */
    private array $provisionals = [];

    private ?int $lastKnownCost = null;

    public static function load(int $productId, int $locationId): self {
        $state = new self();
        foreach (Layers::openForProduct($productId, $locationId) as $row) {
            $state->openLayers[] = [
                'id'                 => (int) $row['id'],
                'source_movement_id' => $row['source_movement_id'] !== null ? (int) $row['source_movement_id'] : null,
                'unit_cost_ore'      => $row['unit_cost_ore'] !== null ? (int) $row['unit_cost_ore'] : null,
                'remaining'          => (float) $row['remaining'],
                'occurred_at'        => (string) $row['occurred_at'],
            ];
        }
        foreach (Consumptions::outstandingProvisionals($productId, $locationId) as $prov) {
            $state->provisionals[] = [
                'movement_id'   => $prov['movement_id'],
                'outstanding'   => $prov['outstanding'],
                'unit_cost_ore' => $prov['unit_cost_ore'],
            ];
        }
        $state->lastKnownCost = Layers::lastKnownCost($productId, $locationId);
        return $state;
    }

    public function lastKnownCost(): ?int {
        return $this->lastKnownCost;
    }

    /* ------------------------------ Layers -------------------------------- */

    /** Register a just-inserted layer, keeping FIFO (occurred_at, id) order. */
    public function pushLayer(int $layerId, ?int $sourceMovementId, ?int $unitCostOre, float $qty, string $occurredAt): void {
        $entry = [
            'id'                 => $layerId,
            'source_movement_id' => $sourceMovementId,
            'unit_cost_ore'      => $unitCostOre,
            'remaining'          => $qty,
            'occurred_at'        => $occurredAt,
        ];
        // Backdated receipts may belong mid-queue — insert sorted.
        $at = count($this->openLayers);
        foreach ($this->openLayers as $i => $layer) {
            if ([$occurredAt, $layerId] < [$layer['occurred_at'], $layer['id']]) {
                $at = $i;
                break;
            }
        }
        array_splice($this->openLayers, $at, 0, [$entry]);

        if ($unitCostOre !== null) {
            $this->lastKnownCost = $unitCostOre; // newest layer by id — always this one
        }
    }

    /** @return array{id:int,source_movement_id:?int,unit_cost_ore:?int,remaining:float,occurred_at:string}|null */
    public function oldestOpenLayer(): ?array {
        foreach ($this->openLayers as $layer) {
            if ($layer['remaining'] > 1e-9) {
                return $layer;
            }
        }
        return null;
    }

    /** @return array{id:int,source_movement_id:?int,unit_cost_ore:?int,remaining:float,occurred_at:string}|null */
    public function layerBySourceMovement(int $movementId): ?array {
        foreach ($this->openLayers as $layer) {
            if ($layer['source_movement_id'] === $movementId && $layer['remaining'] > 1e-9) {
                return $layer;
            }
        }
        return null;
    }

    public function layerRemaining(int $layerId): float {
        foreach ($this->openLayers as $layer) {
            if ($layer['id'] === $layerId) {
                return $layer['remaining'];
            }
        }
        return 0.0;
    }

    public function consumeLayer(int $layerId, float $qty): void {
        foreach ($this->openLayers as $i => $layer) {
            if ($layer['id'] === $layerId) {
                $this->openLayers[$i]['remaining'] = max(0.0, $layer['remaining'] - $qty);
                return;
            }
        }
    }

    /* --------------------------- Provisionals ----------------------------- */

    /** @return array{movement_id:int,outstanding:float,unit_cost_ore:?int}|null oldest first */
    public function oldestOutstandingProvisional(): ?array {
        foreach ($this->provisionals as $prov) {
            if ($prov['outstanding'] > 1e-9) {
                return $prov;
            }
        }
        return null;
    }

    public function hasOutstandingProvisionals(): bool {
        return $this->oldestOutstandingProvisional() !== null;
    }

    public function addProvisional(int $movementId, float $qty, ?int $unitCostOre): void {
        $this->provisionals[] = [
            'movement_id'   => $movementId,
            'outstanding'   => $qty,
            'unit_cost_ore' => $unitCostOre,
        ];
    }

    public function settleProvisional(int $movementId, float $qty): void {
        foreach ($this->provisionals as $i => $prov) {
            if ($prov['movement_id'] === $movementId) {
                $this->provisionals[$i]['outstanding'] = max(0.0, $prov['outstanding'] - $qty);
                return;
            }
        }
    }
}
