<?php

namespace Modules\Recap\Services;

use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Recap\Models\Recap;
use Modules\Recap\Models\RecapLine;

/**
 * Builds draft write-off recaps for stock that has passed its expiry date:
 * one draft per warehouse holding expired batches, covering each batch's
 * full remaining quantity. Batches already on a draft recap are skipped, so
 * running it again (or daily from the scheduler) never double-lists stock.
 * The drafts still need approving before anything is deducted or posted.
 */
class ExpiredRecapGenerator
{
    public function __construct(private readonly CreateRecapService $createRecap) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return Collection<int, Recap>
     */
    public function generate(string $tenantId, ?string $warehouseId = null, ?string $createdBy = null, array $actor = []): Collection
    {
        $alreadyDrafted = RecapLine::query()
            ->whereHas('recap', fn ($query) => $query->where('status', 'draft'))
            ->pluck('batch_id');

        return InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->where('qty_cartons', '>', 0)
            ->whereDate('exp_date', '<', now()->toDateString())
            ->whereNotIn('id', $alreadyDrafted)
            ->orderBy('exp_date')
            ->get()
            ->groupBy('warehouse_id')
            ->map(fn (Collection $batches, string $warehouse) => $this->createRecap->create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouse,
                'reason' => 'expired',
                'notes' => 'Auto-generated from expired batches.',
                'created_by' => $createdBy,
                'lines' => $batches->map(fn (InventoryBatch $batch) => ['batch_id' => $batch->id, 'qty_cartons' => $batch->qty_cartons])->all(),
            ], $actor))
            ->values();
    }
}
