<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Product;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'category_id' => [
                'required', 'string',
                Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId),
            ],
            // No FK validation against a suppliers table yet — Purchasing
            // module hasn't shipped. Revisit once it does.
            'supplier_id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products')->where('tenant_id', $tenantId)],
            'brand' => ['nullable', 'string', 'max:255'],
            'pack_unit' => ['required', 'string', 'max:255'],
            'carton_qty' => ['required', 'integer', 'min:1'],
            'pack_cost_price' => ['required', 'numeric', 'min:0'],
            'pack_selling_price' => ['required', 'numeric', 'min:0'],
            'discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_stock_cartons' => ['sometimes', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
