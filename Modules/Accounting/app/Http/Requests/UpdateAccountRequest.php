<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $account = $this->route('account');

        return [
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('chart_of_accounts')->where('tenant_id', $tenantId)->ignore($account)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(Account::TYPES)],
            'parent_id' => ['nullable', 'string', Rule::notIn([$account->id]), Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId)],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * An account's code and type are what postings and statements key on, so
     * they freeze once the account is a system account or has journal lines.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $account = $this->route('account');

                foreach (['code', 'type'] as $field) {
                    if ($this->has($field) && $this->input($field) !== $account->{$field} && $account->isLocked()) {
                        $validator->errors()->add($field, "The {$field} of a system account or one with journal lines cannot be changed.");
                    }
                }
            },
        ];
    }
}
