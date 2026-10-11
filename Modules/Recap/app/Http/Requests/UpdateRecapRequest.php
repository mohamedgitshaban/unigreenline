<?php

namespace Modules\Recap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Warehouse;
use Modules\Recap\Models\Recap;

class UpdateRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('recap'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $warehouseId = $this->input('warehouse_id', $this->route('recap')->warehouse_id);

        return [
            // Lines belong to a warehouse's batches, so moving the recap means re-picking them.
            'warehouse_id' => ['sometimes', 'string', 'required_with:lines', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['sometimes', Rule::in(Recap::REASONS)],
            'recap_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['sometimes', 'required_with:warehouse_id', 'array', 'min:1'],
            'lines.*.batch_id' => [
                'required', 'string', 'distinct',
                Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId),
            ],
            'lines.*.qty_cartons' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
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
