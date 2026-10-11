<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\ProductionOrder;

class StoreProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProductionOrder::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'bom_id' => ['required', 'string', Rule::exists('boms', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'runs' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'production_date' => ['sometimes', 'date'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'mfg_date' => ['nullable', 'date'],
            'exp_date' => ['required', 'date', 'after:production_date'],
            'conversion_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $warehouse = Warehouse::query()->where('tenant_id', $this->user()->tenant_id)->find($this->input('warehouse_id'));

                if ($warehouse !== null && ! $warehouse->isVisibleTo($this->user())) {
                    $validator->errors()->add('warehouse_id', 'You do not have access to this warehouse.');
                }
            },
        ];
    }
}
