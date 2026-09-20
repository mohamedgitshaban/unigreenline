<?php

namespace Modules\Analytics\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Models\InventoryBatch;

class InventoryReportService
{
    /**
     * @param  array{start_date?: ?string, end_date?: ?string, warehouse_id?: ?string}  $filters
     */
    public function generate(string $tenantId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->when($filters['start_date'] ?? null, fn ($q, $date) => $q->whereDate('rcv_date', '>=', $date))
            ->when($filters['end_date'] ?? null, fn ($q, $date) => $q->whereDate('rcv_date', '<=', $date))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->with(['product', 'warehouse'])
            ->orderBy('rcv_date')
            ->paginate($perPage);
    }
}
