<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Warehouse;

class UpdateProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productionOrder'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'bom_id' => ['sometimes', 'string', Rule::exists('boms', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            'warehouse_id' => ['sometimes', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'runs' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'production_date' => ['sometimes', 'date'],
            'batch_no' => ['sometimes', 'string', 'max:255'],
            'mfg_date' => ['nullable', 'date'],
            'exp_date' => ['sometimes', 'date', 'after:production_date'],
            'conversion_cost' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->filled('warehouse_id')) {
                    return;
                }

                $warehouse = Warehouse::query()->where('tenant_id', $this->user()->tenant_id)->find($this->input('warehouse_id'));

                if ($warehouse !== null && ! $warehouse->isVisibleTo($this->user())) {
                    $validator->errors()->add('warehouse_id', 'You do not have access to this warehouse.');
                }
            },
        ];
    }
}
