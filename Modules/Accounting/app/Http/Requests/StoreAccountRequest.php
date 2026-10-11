<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\Account;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Account::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('chart_of_accounts')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'parent_id' => ['nullable', 'string', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId)],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
