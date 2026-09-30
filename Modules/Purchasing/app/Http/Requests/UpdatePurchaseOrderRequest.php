<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    /**
     * Tenant is checked here rather than in the policy: Administrator's
     * Gate::before bypass would otherwise let the edit cross tenants.
     */
    public function authorize(): bool
    {
        $purchaseOrder = $this->route('purchaseOrder');

        abort_unless($purchaseOrder->tenant_id === $this->user()->tenant_id, 404);

        return $this->user()->can('update', $purchaseOrder);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'supplier_id' => ['sometimes', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['sometimes', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'order_date' => ['sometimes', 'date'],
            // `received` is only reachable through the receive endpoint.
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'pending', 'cancelled'])],

            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'lines.*.qty_cartons' => ['required', 'integer', 'min:1'],
            'lines.*.cost_per_carton' => ['required', 'numeric', 'min:0'],
        ];
    }
}
