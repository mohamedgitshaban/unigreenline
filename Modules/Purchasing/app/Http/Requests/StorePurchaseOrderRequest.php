<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Purchasing\Models\PurchaseOrder;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PurchaseOrder::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'supplier_id' => ['required', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'order_date' => ['sometimes', 'date'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            // Order either in cartons (qty_cartons + cost_per_carton) or in any of the product's
            // packaging units (unit_id + qty + cost_per_unit); the latter must come to whole cartons.
            'lines.*.qty_cartons' => ['required_without:lines.*.unit_id', 'integer', 'min:1'],
            'lines.*.cost_per_carton' => ['required_without:lines.*.unit_id', 'numeric', 'min:0'],
            'lines.*.unit_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'lines.*.qty' => ['required_with:lines.*.unit_id', 'integer', 'min:1'],
            'lines.*.cost_per_unit' => ['required_with:lines.*.unit_id', 'numeric', 'min:0'],
        ];
    }
}
