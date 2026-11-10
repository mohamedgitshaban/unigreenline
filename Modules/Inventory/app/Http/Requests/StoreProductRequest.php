<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Http\Requests\Concerns\ChecksProductPackaging;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;

class StoreProductRequest extends FormRequest
{
    use ChecksProductPackaging;

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
            // Left out, the next free code (PRD-00001 …) is assigned.
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products')->where('tenant_id', $tenantId)],
            'brand' => ['nullable', 'string', 'max:255'],
            'pack_unit' => ['required_without:units', 'nullable', 'string', 'max:255'],
            'carton_qty' => ['required_without:units', 'integer', 'min:1'],
            'pack_cost_price' => ['required', 'numeric', 'min:0'],
            'pack_selling_price' => ['required', 'numeric', 'min:0'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0'],
            // Smallest unit first; every larger unit says how many of the previous one it holds.
            'units' => ['sometimes', 'array', 'min:1', 'max:6'],
            'units.*.unit_id' => ['required', 'string', 'distinct', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'units.*.contains' => ['nullable', 'integer', 'min:2', 'max:100000'],
            'discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_stock_cartons' => ['sometimes', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            // Stock the product starts with, received into one batch in one warehouse (optional).
            'opening_stock' => ['sometimes', 'array'],
            'opening_stock.warehouse_id' => ['required_with:opening_stock', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'opening_stock.batch_no' => ['required_with:opening_stock', 'string', 'max:255'],
            'opening_stock.qty_cartons' => ['required_with:opening_stock', 'integer', 'min:1'],
            'opening_stock.cost_per_carton' => ['required_with:opening_stock', 'numeric', 'min:0'],
            'opening_stock.exp_date' => ['required_with:opening_stock', 'date'],
            'opening_stock.mfg_date' => ['nullable', 'date', 'before_or_equal:opening_stock.exp_date'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            $this->packagingChecks(null),
            function (Validator $validator) {
                if (! $this->has('opening_stock') || $validator->errors()->has('opening_stock.warehouse_id')) {
                    return;
                }

                // Receiving stock is its own right and is limited to the warehouses the user works in.
                $warehouse = Warehouse::query()->find($this->input('opening_stock.warehouse_id'));

                if (! $this->user()->can('goods-receipts.create') || $warehouse === null || ! $warehouse->isVisibleTo($this->user())) {
                    $validator->errors()->add('opening_stock.warehouse_id', 'You cannot receive stock into this warehouse.');
                }
            },
        ];
    }
}
