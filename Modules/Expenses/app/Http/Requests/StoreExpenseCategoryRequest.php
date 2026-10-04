<?php

namespace Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Expenses\Models\ExpenseCategory;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ExpenseCategory::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('expense_categories')->where('tenant_id', $tenantId)],
            // Only Expense-type accounts — approval debits this account.
            'account_id' => ['required', 'string', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId)->where('type', 'Expense')],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
