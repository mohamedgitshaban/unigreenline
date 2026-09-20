<?php

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Analytics\Http\Requests\ReportFilterRequest;
use Modules\Analytics\Services\InventoryReportService;
use Modules\Analytics\Services\SalesReportService;
use Modules\Inventory\Http\Resources\InventoryBatchResource;
use Modules\Sales\Http\Resources\SalesOrderResource;

/**
 * Requires the Export permission (spec §6). Reuses Sales' and Inventory's
 * own resource classes for the row shape rather than duplicating the
 * field mapping — Analytics already depends on their models for the
 * dashboard/expiry/stock endpoints, so this adds no new coupling.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly SalesReportService $salesReport,
        private readonly InventoryReportService $inventoryReport,
    ) {}

    public function sales(ReportFilterRequest $request)
    {
        $orders = $this->salesReport->generate(
            $request->user()->tenant_id,
            $request->only(['start_date', 'end_date', 'warehouse_id']),
            $request->integer('per_page', 15),
        );

        return SalesOrderResource::collection($orders);
    }

    public function inventory(ReportFilterRequest $request)
    {
        $batches = $this->inventoryReport->generate(
            $request->user()->tenant_id,
            $request->only(['start_date', 'end_date', 'warehouse_id']),
            $request->integer('per_page', 15),
        );

        return InventoryBatchResource::collection($batches);
    }
}
