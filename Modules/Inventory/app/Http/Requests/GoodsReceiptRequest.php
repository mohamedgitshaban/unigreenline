<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Warehouse;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $warehouse = Warehouse::find($this->input('warehouse_id'));

        return $warehouse !== null && $warehouse->isVisibleTo($this->user());
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'product_id' => [
                'required', 'string',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId)->where('active', true),
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
            // Cartons of this delivery that arrived damaged, or must be destroyed. They are received with the rest and
            // a draft write-off is raised for them.
            'damaged_cartons' => ['sometimes', 'integer', 'min:0'],
            'destroyed_cartons' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['product_id.exists' => 'This product is inactive or does not exist, so it cannot be used.'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $bad = (int) $this->input('damaged_cartons', 0) + (int) $this->input('destroyed_cartons', 0);

                if (! $validator->errors()->hasAny(['qty_cartons', 'damaged_cartons', 'destroyed_cartons']) && $bad > (int) $this->input('qty_cartons')) {
                    $validator->errors()->add('damaged_cartons', 'More cartons are marked damaged or destroyed than were received.');
                }
            },
        ];
    }
}
