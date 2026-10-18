<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionScrapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeCosts = $request->user()?->can('production-orders.costs') ?? false;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'production_order' => $this->whenLoaded('productionOrder', fn () => ['id' => $this->productionOrder->id, 'number' => $this->productionOrder->number]),
            'work_order_id' => $this->work_order_id,
            'kind' => $this->kind,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
            'qty_cartons' => $this->qty_cartons,
            'reason' => $this->reason,
            'status' => $this->status,
            'scrap_cost' => $this->when($canSeeCosts, $this->scrap_cost),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name] : null),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? ['id' => $this->decidedBy->id, 'name' => $this->decidedBy->name] : null),
            'decided_at' => $this->decided_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
