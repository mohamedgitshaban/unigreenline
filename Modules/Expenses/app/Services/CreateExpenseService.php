<?php

namespace Modules\Expenses\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Expenses\Models\Expense;

/**
 * Records a draft expense. Nothing reaches the journal until the expense is
 * approved — see ExpenseApprovalService.
 */
class CreateExpenseService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data  Validated fields plus tenant_id and created_by.
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(array $data, array $actor = []): Expense
    {
        return DB::transaction(function () use ($data, $actor) {
            $expense = Expense::create([...$data, 'status' => 'draft']);

            $this->auditLog->record([
                'module' => 'expenses',
                'entity_type' => 'Expense',
                'entity_id' => $expense->id,
                'operation' => 'INSERT',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $expense->tenant_id,
                'warehouse_id' => $expense->warehouse_id,
                'new_values' => ['category_id' => $expense->category_id, 'amount' => (string) $expense->amount],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $expense;
        });
    }
}
