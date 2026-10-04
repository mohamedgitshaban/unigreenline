<?php

namespace Modules\Expenses\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Services\AuditLogService;
use Modules\Expenses\Exceptions\ExpenseNotDraftException;
use Modules\Expenses\Models\Expense;

/**
 * Edits a draft expense. Approved and rejected expenses are immutable — an
 * approved one already has a posted journal entry. The row is locked and the
 * status re-checked inside the transaction so a concurrent approval can't
 * leave the journal disagreeing with the expense. A replaced or removed
 * receipt file is deleted once the change has committed.
 */
class UpdateExpenseService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(Expense $expense, array $data, array $actor = []): Expense
    {
        [$updated, $oldReceiptPath] = DB::transaction(function () use ($expense, $data, $actor) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new ExpenseNotDraftException;
            }

            $oldReceiptPath = $locked->receipt_path;
            $previous = Arr::only($locked->getAttributes(), array_keys($data));

            $locked->update($data);

            $this->auditLog->record([
                'module' => 'expenses',
                'entity_type' => 'Expense',
                'entity_id' => $locked->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => $previous,
                'new_values' => Arr::only($locked->getAttributes(), array_keys($data)),
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return [$locked, $oldReceiptPath];
        });

        if ($oldReceiptPath !== null && $oldReceiptPath !== $updated->receipt_path) {
            Storage::delete($oldReceiptPath);
        }

        return $updated;
    }
}
