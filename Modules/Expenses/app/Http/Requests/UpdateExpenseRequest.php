<?php

namespace Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Rules\ExpenseReceiptPath;

class UpdateExpenseRequest extends FormRequest
{
    /**
     * Tenant is checked here rather than in the policy: Administrator's
     * Gate::before bypass would otherwise let the edit cross tenants.
     */
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        abort_unless($expense->tenant_id === $this->user()->tenant_id, 404);

        return $this->user()->can('update', $expense);
    }

    /**
     * Status is not editable here — it only moves through approve/reject.
     * `receipt_path` swaps in a newly uploaded receipt; null removes it.
     */
    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'category_id' => ['sometimes', 'string', Rule::exists('expense_categories', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            'warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'payee' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['sometimes', Rule::in(array_keys(Expense::PAYMENT_ACCOUNT_CODES))],
            'expense_date' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'receipt_path' => ['nullable', 'string', new ExpenseReceiptPath($tenantId, $this->route('expense')->id)],
        ];
    }
}
