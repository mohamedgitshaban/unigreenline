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
            // How much to make: whole cartons, or the number of runs of the recipe.
            'qty_cartons' => ['sometimes', 'integer', 'min:1', 'max:10000000', 'prohibits:runs'],
            'runs' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'production_date' => ['sometimes', 'date'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'mfg_date' => ['nullable', 'date'],
            'exp_date' => ['nullable', 'date', 'after:production_date'],
            'conversion_cost' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'between:0,3'],
            'planned_start' => ['nullable', 'date'],
            'planned_finish' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'assigned_to' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'source_type' => ['nullable', Rule::in(['sales_order', 'manual'])],
            'source_id' => ['nullable', 'required_if:source_type,sales_order', 'string', 'max:40'],
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
