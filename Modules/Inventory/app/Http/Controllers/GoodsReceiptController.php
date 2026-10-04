<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Http\Requests\GoodsReceiptRequest;
use Modules\Inventory\Http\Resources\InventoryBatchResource;
use Modules\Inventory\Services\GoodsReceiptService;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $goodsReceipt,
        private readonly AuditLogService $auditLog,
    ) {}

    public function store(GoodsReceiptRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $batch = DB::transaction(function () use ($data, $request) {
            $batch = $this->goodsReceipt->receive($data);

            $this->auditLog->record([
                'module' => 'inventory',
                'entity_type' => 'InventoryBatch',
                'entity_id' => $batch->id,
                'operation' => 'INSERT',
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'tenant_id' => $request->user()->tenant_id,
                'warehouse_id' => $batch->warehouse_id,
                'new_values' => [
                    'batch_no' => $batch->batch_no,
                    'qty_cartons_received' => $data['qty_cartons'],
                ],
                'ip_address' => $request->ip(),
            ]);

            return $batch;
        });

        return (new InventoryBatchResource($batch->load(['product', 'warehouse'])))->response()->setStatusCode(201);
    }
}
