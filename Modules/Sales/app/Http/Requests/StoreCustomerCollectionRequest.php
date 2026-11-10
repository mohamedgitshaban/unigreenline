<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\Collection;

class StoreCustomerCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Collection::class);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Credit Card', 'Other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            // Left out, the payment is dated the day it is entered.
            'payment_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
