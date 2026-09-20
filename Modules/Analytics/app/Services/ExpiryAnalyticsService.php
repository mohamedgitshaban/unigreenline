<?php

namespace Modules\Analytics\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Models\InventoryBatch;

class ExpiryAnalyticsService
{
    public function generate(string $tenantId, int $perPage = 15): LengthAwarePaginator
    {
        return InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('qty_cartons', '>', 0)
            ->with(['product', 'warehouse'])
            ->orderBy('exp_date')
            ->paginate($perPage);
    }
}
