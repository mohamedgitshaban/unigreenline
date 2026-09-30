<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\Http\Resources\SupplierResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => ProductCategoryResource::make($this->whenLoaded('category')),
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'name' => $this->name,
            'sku' => $this->sku,
            'brand' => $this->brand,
            'pack_unit' => $this->pack_unit,
            'carton_qty' => $this->carton_qty,
            'pack_cost_price' => $this->pack_cost_price,
            'pack_selling_price' => $this->pack_selling_price,
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'discount_pct' => $this->discount_pct,
            'tax_pct' => $this->tax_pct,
            'min_stock_cartons' => $this->min_stock_cartons,
            'reorder_level' => $this->reorder_level,
            'active' => $this->active,
            'batches' => InventoryBatchResource::collection($this->whenLoaded('batches')),
        ];
    }
}
