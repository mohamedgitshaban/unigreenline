<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Transfer::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'from_warehouse_id' => ['required', 'string', 'different:to_warehouse_id', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'to_warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'batch_no' => ['required', 'string', 'max:255'],
            'qty_cartons' => ['required', 'integer', 'min:1'],
            'transfer_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['from_warehouse_id', 'to_warehouse_id'])) {
                    return;
                }

                foreach (['from_warehouse_id', 'to_warehouse_id'] as $field) {
                    $warehouse = Warehouse::find($this->input($field));

                    if ($warehouse && ! $warehouse->isVisibleTo($this->user())) {
                        $validator->errors()->add($field, 'This warehouse is not assigned to you.');
                    }
                }
            },
        ];
    }
}
