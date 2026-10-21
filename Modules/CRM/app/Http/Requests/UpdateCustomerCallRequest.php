<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Support\DataScope;

class UpdateCustomerCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('call'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'customer_id' => ['sometimes', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'rep_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'called_at' => ['sometimes', 'date'],
            'direction' => ['sometimes', Rule::in(['outbound', 'inbound'])],
            'outcome' => ['nullable', Rule::in(['answered', 'no_answer', 'busy', 'voicemail', 'wrong_number'])],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['sometimes', 'string', 'max:5000'],
            'next_follow_up' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $requested = $this->input('rep_id');

                if ($requested && ! app(DataScope::class)->canAssignTo($this->user(), $requested)) {
                    $validator->errors()->add('rep_id', 'You can only log calls under your own name or a member of your team.');
                }
            },
        ];
    }
}
