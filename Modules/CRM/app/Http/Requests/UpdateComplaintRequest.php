<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('complaint'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'customer_id' => ['sometimes', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'product_id' => ['nullable', 'string', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'assigned_to' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'priority' => ['sometimes', Rule::in(['high', 'medium', 'low'])],
            'complaint_date' => ['sometimes', 'date'],
        ];
    }
}
