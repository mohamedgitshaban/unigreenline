<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\StockRequest;
use Modules\Inventory\Models\Warehouse;

class StoreStockRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StockRequest::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            // The warehouse that needs the stock, and where it should end up.
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'source' => ['required', Rule::in(['warehouse', 'purchasing', 'manufacturing'])],
            'from_warehouse_id' => [
                'required_if:source,warehouse', 'nullable', 'string', 'different:warehouse_id',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            // Ask in whole cartons, or in one of the product's packaging units (6 packs): a unit quantity is rounded up to cartons.
            'qty_cartons' => ['required_without:unit_id', 'nullable', 'integer', 'min:1'],
            'unit_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'qty' => ['required_with:unit_id', 'nullable', 'integer', 'min:1'],
            'needed_by' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('warehouse_id')) {
                    return;
                }

                $warehouse = Warehouse::query()->find($this->input('warehouse_id'));

                if ($warehouse !== null && ! $warehouse->isVisibleTo($this->user())) {
                    $validator->errors()->add('warehouse_id', 'You can only ask for stock for a warehouse you work in.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['product_id.exists' => 'This product is inactive or does not exist, so it cannot be used.'];
    }
}
