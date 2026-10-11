<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Purchasing\Http\Requests\Concerns\RestrictsCostsToPricers;

class UpdatePurchaseOrderRequest extends FormRequest
{
    use RestrictsCostsToPricers;

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
            // Order either in cartons (qty_cartons) or in any of the product's packaging units
            // (unit_id + qty); the latter must come to whole cartons. Costs are optional and
            // only a user with pricing access may send them — otherwise the order goes to
            // `pending_pricing` and an administrator prices it (PUT /purchase-orders/{id}/pricing).
            'lines.*.qty_cartons' => ['required_without:lines.*.unit_id', 'integer', 'min:1'],
            'lines.*.cost_per_carton' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'lines.*.qty' => ['required_with:lines.*.unit_id', 'integer', 'min:1'],
            'lines.*.cost_per_unit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [$this->costsOnlyFromPricers()];
    }
}
