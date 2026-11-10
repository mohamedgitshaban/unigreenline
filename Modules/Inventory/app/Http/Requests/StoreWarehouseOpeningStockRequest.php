<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseOpeningStockRequest extends FormRequest
{
    /**
     * The route's goods-receipts.create permission is the gate; the warehouse is checked in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            'items.*.batch_no' => ['required', 'string', 'max:255'],
            'items.*.qty_cartons' => ['required', 'integer', 'min:1'],
            'items.*.cost_per_carton' => ['required', 'numeric', 'min:0'],
            'items.*.exp_date' => ['required', 'date'],
            'items.*.mfg_date' => ['nullable', 'date', 'before_or_equal:items.*.exp_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['items.*.product_id.exists' => 'This product is inactive or does not exist, so it cannot be used.'];
    }
}
