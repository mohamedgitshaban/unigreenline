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
            'lines.*.qty_cartons' => ['required', 'integer', 'min:1'],
            'lines.*.cost_per_carton' => ['required', 'numeric', 'min:0'],
        ];
    }
}
