<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockRequest;

/**
 * @mixin StockRequest
 */
class StockRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'name' => $this->warehouse->name]),
            'source' => $this->source,
            'from_warehouse' => $this->whenLoaded('fromWarehouse', fn () => $this->fromWarehouse ? ['id' => $this->fromWarehouse->id, 'name' => $this->fromWarehouse->name] : null),
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
            'qty_cartons' => $this->qty_cartons,
            'unit_id' => $this->unit_id,
            'unit_qty' => $this->unit_qty,
            'needed_by' => $this->needed_by?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'result' => $this->result_type === null ? null : ['type' => $this->result_type, 'id' => $this->result_id],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
