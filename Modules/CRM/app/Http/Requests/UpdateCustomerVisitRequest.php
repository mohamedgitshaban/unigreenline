<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Support\DataScope;

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

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $requested = $this->input('rep_id');

                if ($requested && ! app(DataScope::class)->canAssignTo($this->user(), $requested)) {
                    $validator->errors()->add('rep_id', 'You can only log visits under your own name or a member of your team.');
                }
            },
        ];
    }
}
