<?php

namespace Modules\Analytics\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sales\Models\SalesOrder;

class SalesReportService
{
    /**
     * @param  array{start_date?: ?string, end_date?: ?string, warehouse_id?: ?string}  $filters
     */
    public function generate(string $tenantId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return SalesOrder::query()
            ->where('tenant_id', $tenantId)
            ->when($filters['start_date'] ?? null, fn ($q, $date) => $q->whereDate('order_date', '>=', $date))
            ->when($filters['end_date'] ?? null, fn ($q, $date) => $q->whereDate('order_date', '<=', $date))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->orderBy('order_date')
            ->paginate($perPage);
    }
}
