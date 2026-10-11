<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('visit'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'customer_id' => ['sometimes', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'rep_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'visit_date' => ['sometimes', 'date'],
            'type' => ['nullable', 'string', 'max:255'],
            'outcome' => ['nullable', Rule::in(['positive', 'neutral', 'negative'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'next_visit' => ['nullable', 'date', 'after_or_equal:visit_date'],
        ];
    }
}
