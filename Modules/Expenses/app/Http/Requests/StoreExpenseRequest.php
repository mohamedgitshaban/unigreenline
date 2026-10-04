<?php

namespace Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Rules\ExpenseReceiptPath;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Expense::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'category_id' => ['required', 'string', Rule::exists('expense_categories', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            'warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'payee' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(array_keys(Expense::PAYMENT_ACCOUNT_CODES))],
            'expense_date' => ['required', 'date'],
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'receipt_path' => ['nullable', 'string', new ExpenseReceiptPath($tenantId)],
        ];
    }
}
