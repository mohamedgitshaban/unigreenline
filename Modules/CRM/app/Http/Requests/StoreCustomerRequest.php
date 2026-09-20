<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\CRM\Models\Customer;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'sales_rep_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['Clinic', 'Farm', 'Poultry', 'Distributor', 'Retailer', 'Other'])],
            'classification' => ['sometimes', Rule::in(['A+', 'A', 'B', 'C'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'pay_terms' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $requested = $this->input('sales_rep_id');

                if ($this->user()->hasRole('Sales Rep') && $requested && $requested !== $this->user()->id) {
                    $validator->errors()->add('sales_rep_id', 'A Sales Rep can only create customers under their own name.');
                }
            },
        ];
    }
}
