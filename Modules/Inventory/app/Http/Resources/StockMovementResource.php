<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockMovement;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StockMovement $this */
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'direction' => $this->direction(),
            'qty_cartons' => $this->qty_cartons,
            'type' => $this->type,
            'type_label' => StockMovement::TYPES[$this->type] ?? $this->type,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->id, 'name' => $this->warehouse->name] : null),
            'product' => $this->whenLoaded('product', fn () => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku] : null),
            'batch_no' => $this->batch_no,
            'is_free_gift' => $this->is_free_gift,
            'counterpart_warehouse' => $this->whenLoaded('counterpartWarehouse', fn () => $this->counterpartWarehouse ? ['id' => $this->counterpartWarehouse->id, 'name' => $this->counterpartWarehouse->name] : null),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'notes' => $this->notes,
            // What the stock cost is shown only to those who see batch costs (the same rule as the batches).
            'unit_cost' => $this->when($request->user()?->hasRole('Administrator'), fn () => $this->unit_cost),
        ];
    }
}
