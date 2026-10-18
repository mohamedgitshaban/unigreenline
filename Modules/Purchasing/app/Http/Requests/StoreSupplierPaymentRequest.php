<?php

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Purchasing\Models\PurchaseOrder;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'supplier_id' => ['required', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            // Optional: which received order this payment is for (informational — the payment always reduces the supplier's balance).
            'purchase_order_id' => ['nullable', 'string', Rule::exists('purchase_orders', 'id')->where('tenant_id', $tenantId)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'method' => ['required', Rule::in(['cash', 'bank'])],
            'payment_date' => ['sometimes', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->input('purchase_order_id') === null) {
                    return;
                }

                $order = PurchaseOrder::query()->find($this->input('purchase_order_id'));

                if ($order->supplier_id !== $this->input('supplier_id')) {
                    $validator->errors()->add('purchase_order_id', 'That purchase order belongs to a different supplier.');
                } elseif (! $order->stock_added) {
                    $validator->errors()->add('purchase_order_id', 'Only a received purchase order can be paid — nothing is owed for it until then.');
                }
            },
        ];
    }
}
