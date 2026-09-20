<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sales\Models\SalesOrder;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SalesOrder::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'customer_id' => ['required', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'sales_rep_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'pay_type' => ['required', Rule::in(['cash', 'credit'])],
            'grace_period' => ['sometimes', 'integer', 'min:0'],
            'invoice_discount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'order_date' => ['sometimes', 'date'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'lines.*.batch_no' => ['nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit' => ['required', Rule::in(['Carton', 'Pack'])],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'lines.*.free_qty' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('sales_rep_id')) {
                    return;
                }

                $this->ensureRepOwnsTheOrder($validator);
            },
        ];
    }

    private function ensureRepOwnsTheOrder(Validator $validator): void
    {
        $requested = $this->input('sales_rep_id');

        if ($this->user()->hasRole('Sales Rep') && $requested && $requested !== $this->user()->id) {
            $validator->errors()->add('sales_rep_id', 'A Sales Rep can only create orders under their own name.');
        }
    }
}
