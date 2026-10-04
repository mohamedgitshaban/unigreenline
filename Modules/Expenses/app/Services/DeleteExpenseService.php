<?php

namespace Modules\Expenses\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Services\AuditLogService;
use Modules\Expenses\Exceptions\ExpenseNotDraftException;
use Modules\Expenses\Models\Expense;

/**
 * Deletes a draft expense and its receipt file. Approved expenses are
 * refused — undoing one needs a reversing journal entry, not a delete — and
 * rejected ones are kept as the record of the rejection.
 */
class DeleteExpenseService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function delete(Expense $expense, array $actor = []): void
    {
        $receiptPath = DB::transaction(function () use ($expense, $actor) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new ExpenseNotDraftException;
            }

            $locked->delete();

            $this->auditLog->record([
                'module' => 'expenses',
                'entity_type' => 'Expense',
                'entity_id' => $locked->id,
                'operation' => 'DELETE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => ['category_id' => $locked->category_id, 'status' => $locked->status, 'amount' => (string) $locked->amount],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked->receipt_path;
        });

        if ($receiptPath !== null) {
            Storage::delete($receiptPath);
        }
    }
}
