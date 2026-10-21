<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\CustomerCall;

class StoreCustomerCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CustomerCall::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'customer_id' => ['required', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'rep_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'called_at' => ['nullable', 'date'],
            'direction' => ['nullable', Rule::in(['outbound', 'inbound'])],
            'outcome' => ['nullable', Rule::in(['answered', 'no_answer', 'busy', 'voicemail', 'wrong_number'])],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'next_follow_up' => ['nullable', 'date', 'after_or_equal:today'],
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
