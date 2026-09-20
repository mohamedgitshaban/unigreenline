<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\ReturnRecord;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ReturnRecord::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $types = ['Sales Return', 'Damaged', 'Expired Return', 'Wrong Item', 'Purchase Return'];

        return [
            'invoice_id' => ['nullable', 'string', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => [
                'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId),
                Rule::requiredIf($this->input('type') === 'Purchase Return'),
            ],
            'product_id' => [
                'nullable', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId),
                Rule::requiredIf(fn () => $this->needsStockMovement()),
            ],
            'warehouse_id' => [
                'nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
                Rule::requiredIf(fn () => $this->needsStockMovement()),
            ],
            'batch_no' => [
                'nullable', 'string', 'max:255',
                Rule::requiredIf(fn () => $this->needsStockMovement()),
            ],
            'type' => ['required', Rule::in($types)],
            'qty' => ['required', 'integer', 'min:1'],
            'unit' => ['required', Rule::in(['Carton', 'Pack'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'restocked' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'return_date' => ['sometimes', 'date'],
        ];
    }

    private function needsStockMovement(): bool
    {
        return $this->input('type') === 'Purchase Return' || $this->boolean('restocked');
    }
}
