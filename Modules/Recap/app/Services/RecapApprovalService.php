<?php

namespace Modules\Recap\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Recap\Exceptions\RecapNotDraftException;
use Modules\Recap\Exceptions\RecapStockShortageException;
use Modules\Recap\Models\Recap;

/**
 * Moves a draft recap to approved or rejected. Approval is where the write-off
 * happens, all in one transaction: each batch is locked and its cartons
 * removed (refused if the batch no longer holds enough), the lines are
 * re-costed at the batch's current cost, the warehouse stock value is
 * refreshed, and Dr Inventory Write-off Expense (6300) / Cr Inventory (1300)
 * is posted for the total — so the loss shows on the income statement and the
 * inventory asset drops by the same amount. Rejection touches nothing.
 */
class RecapApprovalService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function approve(Recap $recap, array $actor = []): Recap
    {
        return DB::transaction(function () use ($recap, $actor) {
            $locked = $this->lockDraft($recap);
            $total = 0.0;

            foreach ($locked->lines()->orderBy('id')->get() as $line) {
                $batch = InventoryBatch::query()->whereKey($line->batch_id)->lockForUpdate()->firstOrFail();

                if ($batch->qty_cartons < $line->qty_cartons) {
                    throw new RecapStockShortageException($batch->batch_no, $line->qty_cartons, $batch->qty_cartons);
                }

                $batch->decrement('qty_cartons', $line->qty_cartons);

                $value = round($line->qty_cartons * (float) $batch->cost_per_carton, 2);
                $line->update(['cost_per_carton' => $batch->cost_per_carton, 'line_value' => $value]);
                $total += $value;
            }

            $total = round($total, 2);
            $entry = null;

            if ($total > 0) {
                $entry = $this->journalPosting->post(
                    $locked->tenant_id,
                    $locked->id,
                    "Stock write-off ({$locked->reason}) {$locked->id}",
                    [
                        ['account_code' => '6300', 'debit' => $total],
                        ['account_code' => '1300', 'credit' => $total],
                    ],
                    $actor['actor_id'] ?? null,
                    $locked->recap_date,
                );
            }

            Warehouse::query()->find($locked->warehouse_id)?->recalculateStockValue();

            $locked->update([
                'status' => 'approved',
                'total_value' => $total,
                'approved_by' => $actor['actor_id'] ?? null,
                'approved_at' => now(),
                'journal_entry_id' => $entry?->id,
            ]);

            $this->record($locked, 'APPROVE', ['status' => 'approved', 'total_value' => (string) $total, 'journal_entry_id' => $entry?->id], $actor);

            return $locked->load('lines');
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function reject(Recap $recap, ?string $reason, array $actor = []): Recap
    {
        return DB::transaction(function () use ($recap, $reason, $actor) {
            $locked = $this->lockDraft($recap);

            $locked->update([
                'status' => 'rejected',
                'approved_by' => $actor['actor_id'] ?? null,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->record($locked, 'UPDATE', ['status' => 'rejected', 'rejection_reason' => $reason], $actor);

            return $locked->load('lines');
        });
    }

    private function lockDraft(Recap $recap): Recap
    {
        $locked = Recap::query()->whereKey($recap->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw new RecapNotDraftException;
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(Recap $recap, string $operation, array $newValues, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'recap',
            'entity_type' => 'Recap',
            'entity_id' => $recap->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $recap->tenant_id,
            'warehouse_id' => $recap->warehouse_id,
            'prev_values' => ['status' => 'draft'],
            'new_values' => $newValues,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
