<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\Http\Resources\SupplierResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeCost = (bool) $request->user()?->hasRole('Administrator');

        return [
            'id' => $this->id,
            'category' => ProductCategoryResource::make($this->category),
            'supplier' => SupplierResource::make($this->supplier),
            'name' => $this->name,
            'sku' => $this->sku,
            'brand' => $this->brand,
            'pack_unit' => $this->pack_unit,
            'carton_qty' => $this->carton_qty,
            // Cost is for administrators only; everyone else sees the selling and minimum selling price.
            'pack_cost_price' => $this->when($canSeeCost, fn () => $this->pack_cost_price),
            'pack_selling_price' => $this->pack_selling_price,
            'min_selling_price' => $this->min_selling_price,
            'cost_price' => $this->when($canSeeCost, fn () => $this->cost_price),
            'selling_price' => $this->selling_price,
            'min_selling_price_per_carton' => $this->min_selling_price === null ? null : number_format($this->min_selling_price * $this->carton_qty, 2, '.', ''),
            'discount_pct' => $this->discount_pct,
            'tax_pct' => $this->tax_pct,
            'min_stock_cartons' => $this->min_stock_cartons,
            'reorder_level' => $this->reorder_level,
            'active' => $this->active,
            'units' => $this->whenLoaded('units', fn () => $this->units->map(fn ($level) => [
                'unit_id' => $level->unit_id,
                'name' => $level->unit?->name,
                'contains' => $level->contains,
                'base_qty' => $level->base_qty,
            ])->values()),
            // Paginated at GET /products/{id}/batches.
            'batches_count' => $this->whenCounted('batches'),
        ];
    }
}
