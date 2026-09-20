<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\Collection;

class StoreCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Collection::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'invoice_id' => ['required', 'string', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Credit Card', 'Other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'payment_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
