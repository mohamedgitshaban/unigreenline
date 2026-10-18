<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\StockReservationService;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderComponent;

/**
 * Sets an order's materials aside in its warehouse (through the inventory
 * reservation service, which keeps the books of who holds which cartons) and
 * keeps the order's own view — per component and overall — in step.
 */
class MaterialReservationService
{
    public const SOURCE = 'production_order';

    public function __construct(private readonly StockReservationService $reservations) {}

    /**
     * Reserves what is still missing for every mandatory component, as far as stock allows.
     * Returns the shortages (what could not be reserved); an empty list means fully reserved.
     *
     * @return array<int, array{product_id: string, needed: int, reserved: int, short: int}>
     */
    public function reserve(ProductionOrder $order, ?string $userId = null): array
    {
        return DB::transaction(function () use ($order, $userId) {
            $shortages = [];

            foreach ($order->components()->where('optional', false)->orderBy('id')->get() as $component) {
                $needed = max(0, $component->required_cartons - $component->consumed_cartons);
                $held = $this->reservations->reservedFor(self::SOURCE, $order->id, $component->id);
                $missing = $needed - $held;

                if ($missing > 0) {
                    $this->reservations->reserve(
                        $order->tenant_id, $component->product_id, $order->warehouse_id, $missing,
                        self::SOURCE, $order->id, $component->id, $userId,
                    );
                }

                $held = $this->reservations->reservedFor(self::SOURCE, $order->id, $component->id);
                $component->update(['reserved_cartons' => $held]);

                if ($held < $needed) {
                    $shortages[] = ['product_id' => $component->product_id, 'needed' => $needed, 'reserved' => $held, 'short' => $needed - $held];
                }
            }

            $this->refreshStatus($order);

            return $shortages;
        });
    }

    /**
     * Gives back everything the order holds (cancellation, completion, or a manual release).
     */
    public function release(ProductionOrder $order, ?ProductionOrderComponent $component = null): int
    {
        return DB::transaction(function () use ($order, $component) {
            $released = $this->reservations->release(self::SOURCE, $order->id, $component?->id);

            ($component ? $order->components()->whereKey($component->id) : $order->components())->update(['reserved_cartons' => 0]);
            $this->refreshStatus($order);

            return $released;
        });
    }

    /**
     * Re-reads what is reserved against what is still to be issued and sets the order's reservation status.
     */
    public function refreshStatus(ProductionOrder $order): void
    {
        $components = $order->components()->where('optional', false)->get();

        foreach ($components as $component) {
            $held = $this->reservations->reservedFor(self::SOURCE, $order->id, $component->id);

            if ($held !== $component->reserved_cartons) {
                $component->update(['reserved_cartons' => $held]);
            }
        }

        $needed = $components->sum(fn ($c) => max(0, $c->required_cartons - $c->consumed_cartons));
        $held = $components->sum(fn ($c) => min($c->reserved_cartons, max(0, $c->required_cartons - $c->consumed_cartons)));

        $order->update(['reservation_status' => match (true) {
            $held <= 0 => 'none',
            $held >= $needed => 'reserved',
            default => 'partial',
        }]);
    }
}
