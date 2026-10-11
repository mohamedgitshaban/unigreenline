<?php

namespace Modules\Recap\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Recap\Models\Recap;

/**
 * Creates a draft recap. Drafts touch no stock and post nothing; they only
 * snapshot the batches being written off (and their cost) so the document
 * can be reviewed before it is approved.
 */
class CreateRecapService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{
     *     tenant_id: string, warehouse_id: string, reason?: string, recap_date?: string, notes?: ?string, created_by?: ?string,
     *     lines: array<int, array{batch_id: string, qty_cartons: int}>,
     * }  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(array $data, array $actor = []): Recap
    {
        return DB::transaction(function () use ($data, $actor) {
            $recap = Recap::create([
                'tenant_id' => $data['tenant_id'],
                'warehouse_id' => $data['warehouse_id'],
                'reason' => $data['reason'] ?? 'expired',
                'recap_date' => $data['recap_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $this->syncLines($recap, $data['lines']);

            $this->auditLog->record([
                'module' => 'recap',
                'entity_type' => 'Recap',
                'entity_id' => $recap->id,
                'operation' => 'INSERT',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $recap->tenant_id,
                'warehouse_id' => $recap->warehouse_id,
                'new_values' => ['reason' => $recap->reason, 'total_value' => (string) $recap->total_value, 'lines' => count($data['lines'])],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $recap->load('lines');
        });
    }

    /**
     * Replaces the recap's lines with snapshots of the given batches and
     * refreshes its total.
     *
     * @param  array<int, array{batch_id: string, qty_cartons: int}>  $lines
     */
    public function syncLines(Recap $recap, array $lines): void
    {
        $recap->lines()->delete();

        $batches = InventoryBatch::query()
            ->where('tenant_id', $recap->tenant_id)
            ->where('warehouse_id', $recap->warehouse_id)
            ->whereIn('id', array_column($lines, 'batch_id'))
            ->get()
            ->keyBy('id');

        $total = 0.0;

        foreach ($lines as $line) {
            $batch = $batches[$line['batch_id']];
            $value = round($line['qty_cartons'] * (float) $batch->cost_per_carton, 2);
            $total += $value;

            $recap->lines()->create([
                'batch_id' => $batch->id,
                'product_id' => $batch->product_id,
                'batch_no' => $batch->batch_no,
                'exp_date' => $batch->exp_date,
                'qty_cartons' => $line['qty_cartons'],
                'cost_per_carton' => $batch->cost_per_carton,
                'line_value' => $value,
            ]);
        }

        $recap->update(['total_value' => round($total, 2)]);
    }
}
