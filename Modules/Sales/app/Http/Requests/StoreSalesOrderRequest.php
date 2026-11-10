<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Support\DataScope;
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
            'pay_type' => ['required', Rule::in(['cash', 'credit'])],
            'grace_period' => ['sometimes', 'integer', 'min:0'],
            'invoice_discount' => ['sometimes', 'numeric', 'min:0'],
            // The same discount as a percentage of the subtotal; send this or invoice_discount, not both.
            'invoice_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shipping_cost' => ['sometimes', 'numeric', 'min:0'],
            // The warehouse lines draw from when a line does not name its own.
            'warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'order_date' => ['sometimes', 'date'],
            // When the customer wants the goods. The delivery keeps the actual date.
            'delivery_date' => ['nullable', 'date'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            // A line may name the warehouse it is drawn from; otherwise the order's warehouse_id, or whichever warehouse holds the stock.
            'lines.*.warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'lines.*.batch_no' => ['nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            // Either a plain Carton/Pack, or one of the product's packaging units (unit_id).
            'lines.*.unit' => ['required_without:lines.*.unit_id', 'nullable', Rule::in(['Carton', 'Pack'])],
            'lines.*.unit_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'lines.*.is_gift' => ['sometimes', 'boolean'],
            // Not needed on gift lines, which are sold at 0.
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
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
            function (Validator $validator) {
                if ((float) $this->input('invoice_discount', 0) > 0 && (float) $this->input('invoice_discount_pct', 0) > 0) {
                    $validator->errors()->add('invoice_discount_pct', 'Send either invoice_discount or invoice_discount_pct, not both.');
                }
            },
            function (Validator $validator) {
                foreach ((array) $this->input('lines', []) as $index => $line) {
                    if (! filter_var($line['is_gift'] ?? false, FILTER_VALIDATE_BOOLEAN) && ($line['unit_price'] ?? null) === null) {
                        $validator->errors()->add("lines.{$index}.unit_price", 'A price is required unless the line is a free gift.');
                    }
                }
            },
        ];
    }

    private function ensureRepOwnsTheOrder(Validator $validator): void
    {
        $requested = $this->input('sales_rep_id');

        if ($requested && ! app(DataScope::class)->canAssignTo($this->user(), $requested)) {
            $validator->errors()->add('sales_rep_id', 'You can only create orders under your own name or a member of your team.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['lines.*.product_id.exists' => 'This product is inactive or does not exist, so it cannot be used.'];
    }
}
