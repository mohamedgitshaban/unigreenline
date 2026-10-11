<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Purchasing\Http\Requests\Concerns\RestrictsCostsToPricers;
use Modules\Purchasing\Models\PurchaseOrder;

class StorePurchaseOrderRequest extends FormRequest
{
    use RestrictsCostsToPricers;

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
