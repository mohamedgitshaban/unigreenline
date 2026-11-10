<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Events\DamagedGoodsReceived;
use Modules\Inventory\Http\Requests\GoodsReceiptRequest;
use Modules\Inventory\Http\Resources\InventoryBatchResource;
use Modules\Inventory\Services\ManualGoodsReceiptService;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly ManualGoodsReceiptService $receipts) {}

    public function store(GoodsReceiptRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $damaged = (int) ($data['damaged_cartons'] ?? 0);
        $destroyed = (int) ($data['destroyed_cartons'] ?? 0);
        unset($data['damaged_cartons'], $data['destroyed_cartons']);

        $actor = ['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'ip_address' => $request->ip()];

        [$batch, $writeOffs] = DB::transaction(function () use ($data, $actor, $damaged, $destroyed) {
            $batch = $this->receipts->receive($data, $actor);
            $event = new DamagedGoodsReceived($batch, $damaged, $destroyed, $actor);

            // Damaged or destroyed goods are received like the rest, then written off by a draft the manager approves.
            if ($damaged > 0 || $destroyed > 0) {
                Event::dispatch($event);
            }

            return [$batch, $event->recapIds];
        });

        return (new InventoryBatchResource($batch->load(['product', 'warehouse'])))
            ->additional(['write_offs' => $writeOffs])
            ->response()->setStatusCode(201);
    }
}
