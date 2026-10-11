<?php

namespace Modules\Recap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Warehouse;
use Modules\Recap\Models\Recap;

class StoreRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Recap::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['sometimes', Rule::in(Recap::REASONS)],
            'recap_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.batch_id' => [
                'required', 'string', 'distinct',
                Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)->where('warehouse_id', $this->input('warehouse_id')),
            ],
            'lines.*.qty_cartons' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * A warehouse-scoped user can only write off stock in their own warehouses.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $warehouse = Warehouse::query()->where('tenant_id', $this->user()->tenant_id)->find($this->input('warehouse_id'));

                if ($warehouse !== null && ! $warehouse->isVisibleTo($this->user())) {
                    $validator->errors()->add('warehouse_id', 'You do not have access to this warehouse.');
                }
            },
        ];
    }
}
