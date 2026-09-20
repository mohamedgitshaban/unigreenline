<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $product = $this->route('product');

        return [
            'category_id' => [
                'sometimes', 'string',
                Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId),
            ],
            'supplier_id' => ['nullable', 'string'],
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('products')->where('tenant_id', $tenantId)->ignore($product?->id),
            ],
            'brand' => ['nullable', 'string', 'max:255'],
            'pack_unit' => ['sometimes', 'string', 'max:255'],
            'carton_qty' => ['sometimes', 'integer', 'min:1'],
            'pack_cost_price' => ['sometimes', 'numeric', 'min:0'],
            'pack_selling_price' => ['sometimes', 'numeric', 'min:0'],
            'discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_stock_cartons' => ['sometimes', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
