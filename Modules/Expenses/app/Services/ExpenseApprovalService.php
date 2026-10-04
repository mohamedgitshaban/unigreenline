<?php

namespace Modules\Expenses\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Expenses\Exceptions\ExpenseNotDraftException;
use Modules\Expenses\Models\Expense;

/**
 * Moves a draft expense to approved or rejected. Approval posts
 * Dr <category's expense account> / Cr Cash (1110) or Bank (1120), dated on
 * the expense date, so it shows on the income statement. Rejection posts
 * nothing. The row is locked so the same expense can't be posted twice.
 */
class ExpenseApprovalService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function approve(Expense $expense, array $actor = []): Expense
    {
        return DB::transaction(function () use ($expense, $actor) {
            $locked = $this->lockDraft($expense);
            $amount = (float) $locked->amount;

            $entry = $this->journalPosting->post(
                $locked->tenant_id,
                $locked->id,
                "Expense {$locked->id}: {$locked->category->name}",
                [
                    ['account_code' => $locked->category->account->code, 'debit' => $amount],
                    ['account_code' => Expense::PAYMENT_ACCOUNT_CODES[$locked->payment_method], 'credit' => $amount],
                ],
                $actor['actor_id'] ?? null,
                $locked->expense_date,
            );

            $locked->update([
                'status' => 'approved',
                'approved_by' => $actor['actor_id'] ?? null,
                'approved_at' => now(),
                'journal_entry_id' => $entry->id,
            ]);

            $this->record($locked, 'APPROVE', ['status' => 'approved', 'journal_entry_id' => $entry->id], $actor);

            return $locked;
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function reject(Expense $expense, ?string $reason, array $actor = []): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actor) {
            $locked = $this->lockDraft($expense);

            $locked->update([
                'status' => 'rejected',
                'approved_by' => $actor['actor_id'] ?? null,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->record($locked, 'UPDATE', ['status' => 'rejected', 'rejection_reason' => $reason], $actor);

            return $locked;
        });
    }

    private function lockDraft(Expense $expense): Expense
    {
        $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw new ExpenseNotDraftException;
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(Expense $expense, string $operation, array $newValues, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'expenses',
            'entity_type' => 'Expense',
            'entity_id' => $expense->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $expense->tenant_id,
            'warehouse_id' => $expense->warehouse_id,
            'prev_values' => ['status' => 'draft'],
            'new_values' => $newValues,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
