<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Warehouse;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('inventory.add')) {
            return false;
        }

        $warehouse = Warehouse::find($this->input('warehouse_id'));

        return $warehouse !== null && $warehouse->isVisibleTo($this->user());
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'product_id' => [
                'required', 'string',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'warehouse_id' => [
                'required', 'string',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'batch_no' => ['required', 'string', 'max:255'],
            'qty_cartons' => ['required', 'integer', 'min:1'],
            'cost_per_carton' => ['required', 'numeric', 'min:0'],
            // Deliberately required with no default — the prototype defaulted
            // to "received date + 365 days", which is wrong for real pharma
            // data (spec §5.2). The real expiry must be supplied here.
            'exp_date' => ['required', 'date'],
            'mfg_date' => ['nullable', 'date', 'before_or_equal:exp_date'],
            'rcv_date' => ['nullable', 'date'],
        ];
    }
}
