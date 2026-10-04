<?php

namespace Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends FormRequest
{
    /**
     * Tenant is checked here rather than in the policy: Administrator's
     * Gate::before bypass would otherwise let the edit cross tenants.
     */
    public function authorize(): bool
    {
        $category = $this->route('expenseCategory');

        abort_unless($category->tenant_id === $this->user()->tenant_id, 404);

        return $this->user()->can('update', $category);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('expense_categories')->where('tenant_id', $tenantId)->ignore($this->route('expenseCategory'))],
            'account_id' => ['sometimes', 'string', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId)->where('type', 'Expense')],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
