<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\Lead;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Lead::class);
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['Clinic', 'Farm', 'Poultry', 'Distributor', 'Retailer', 'Other'])],
            'contact' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['new', 'qualified', 'proposal', 'won', 'lost'])],
            'value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
