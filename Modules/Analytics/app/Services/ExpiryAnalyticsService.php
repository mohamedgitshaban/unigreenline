<?php

namespace Modules\Analytics\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Models\InventoryBatch;

class ExpiryAnalyticsService
{
    public function generate(string $tenantId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($tenantId)->paginate($perPage);
    }

    /**
     * Exposed separately (not just inlined into generate()) so the
     * `?export=` path (Modules\Core\Http\Controllers\Concerns\Exportable)
     * can pull every matching batch, not just one paginated page.
     */
    public function query(string $tenantId): Builder
    {
        return InventoryBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('qty_cartons', '>', 0)
            ->with(['product', 'warehouse'])
            ->orderBy('exp_date');
    }
}
