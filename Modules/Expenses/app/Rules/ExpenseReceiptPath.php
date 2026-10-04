<?php

namespace Modules\Expenses\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Storage;
use Modules\Expenses\Models\Expense;

/**
 * Accepts only a path returned by POST /expenses/receipts for this tenant:
 * inside the tenant's receipt folder, no traversal, the file still exists,
 * and no other expense already points at it.
 */
class ExpenseReceiptPath implements ValidationRule
{
    public function __construct(private readonly string $tenantId, private readonly ?string $ignoreExpenseId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = '#^'.preg_quote(Expense::receiptDirectory($this->tenantId), '#').'/[A-Za-z0-9]+\.(jpg|jpeg|png|pdf)$#';

        if (! is_string($value) || ! preg_match($pattern, $value) || ! Storage::exists($value)) {
            $fail('The :attribute must be a receipt uploaded via POST /expenses/receipts.');

            return;
        }

        $alreadyAttached = Expense::query()
            ->where('receipt_path', $value)
            ->when($this->ignoreExpenseId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();

        if ($alreadyAttached) {
            $fail('The :attribute is already attached to another expense.');
        }
    }
}
