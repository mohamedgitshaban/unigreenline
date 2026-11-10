<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Notification;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Events\StockRequestGranted;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Exceptions\StockRequestNotOpenException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\StockRequest;

/**
 * A warehouse asks for stock. Granting it does the work:
 *  - from another warehouse: the cartons are transferred, earliest-expiring batches first;
 *  - from Purchasing or Manufacturing: a draft purchase order or production order is raised (by the module
 *    that owns it, through {@see StockRequestGranted}).
 * A request can also be rejected, with a reason, or cancelled by the person who made it. Only an open
 * request can be decided.
 */
class StockRequestService
{
    public function __construct(
        private readonly TransferStockService $transfers,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{tenant_id: string, warehouse_id: string, source: string, from_warehouse_id?: ?string, product_id: string, qty_cartons: int, needed_by?: ?string, notes?: ?string, requested_by: string}  $data
     * @param  array{actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(array $data, array $actor = []): StockRequest
    {
        return DB::transaction(function () use ($data, $actor) {
            $request = StockRequest::create($data);

            $this->record($request, 'INSERT', null, ['source' => $request->source, 'qty_cartons' => $request->qty_cartons], $actor + ['actor_id' => $data['requested_by']]);

            // The manager of the warehouse being asked has something to answer.
            $managerId = $request->fromWarehouse?->manager_id;

            if ($managerId !== null && $managerId !== $data['requested_by']) {
                Notification::create([
                    'tenant_id' => $request->tenant_id,
                    'user_id' => $managerId,
                    'actor_id' => $data['requested_by'],
                    'type' => 'stock_request',
                    'title' => 'Stock requested from your warehouse',
                    'body' => "{$request->warehouse?->name} asked for {$request->qty_cartons} carton(s) of {$request->product?->name} ({$request->number}).",
                    'link_module' => 'inventory',
                    'link_entity' => 'stock-requests',
                    'link_id' => $request->id,
                    'unread' => true,
                ]);
            }

            return $request->load(['warehouse', 'fromWarehouse', 'product']);
        });
    }

    /**
     * @param  array<string, mixed>  $options  supplier_id (purchasing) or bom_id (manufacturing), when the product does not settle it.
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function grant(StockRequest $stockRequest, array $options, array $actor): StockRequest
    {
        return DB::transaction(function () use ($stockRequest, $options, $actor) {
            $request = $this->lockOpen($stockRequest);

            $result = match ($request->source) {
                'warehouse' => $this->transferStock($request, $actor),
                default => $this->raiseDocument($request, $options, $actor),
            };

            $request->update([
                'status' => 'fulfilled',
                'decided_by' => $actor['actor_id'] ?? null,
                'decided_at' => now(),
                'result_type' => $result['type'],
                'result_id' => $result['id'],
            ]);

            $this->record($request, 'UPDATE', ['status' => 'requested'], ['status' => 'fulfilled', 'result' => $result['type']], $actor);
            $this->tellRequester($request, 'Stock request granted', "Your request {$request->number} was granted.", $actor);

            return $request->load(['warehouse', 'fromWarehouse', 'product']);
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function reject(StockRequest $stockRequest, string $reason, array $actor): StockRequest
    {
        return DB::transaction(function () use ($stockRequest, $reason, $actor) {
            $request = $this->lockOpen($stockRequest);

            $request->update(['status' => 'rejected', 'decided_by' => $actor['actor_id'] ?? null, 'decided_at' => now(), 'rejection_reason' => $reason]);

            $this->record($request, 'UPDATE', ['status' => 'requested'], ['status' => 'rejected', 'reason' => $reason], $actor);
            $this->tellRequester($request, 'Stock request rejected', "Your request {$request->number} was rejected: {$reason}", $actor);

            return $request->load(['warehouse', 'fromWarehouse', 'product']);
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function cancel(StockRequest $stockRequest, array $actor): StockRequest
    {
        return DB::transaction(function () use ($stockRequest, $actor) {
            $request = $this->lockOpen($stockRequest);

            $request->update(['status' => 'cancelled', 'decided_by' => $actor['actor_id'] ?? null, 'decided_at' => now()]);

            $this->record($request, 'UPDATE', ['status' => 'requested'], ['status' => 'cancelled'], $actor);

            return $request->load(['warehouse', 'fromWarehouse', 'product']);
        });
    }

    private function lockOpen(StockRequest $stockRequest): StockRequest
    {
        $request = StockRequest::query()->whereKey($stockRequest->id)->lockForUpdate()->firstOrFail();

        if ($request->status !== 'requested') {
            throw new StockRequestNotOpenException("This request is already {$request->status}.");
        }

        return $request;
    }

    /**
     * Moves the cartons from the warehouse asked, earliest-expiring batches first, as one transfer per batch used.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{type: string, id: string}
     */
    private function transferStock(StockRequest $request, array $actor): array
    {
        $batches = InventoryBatch::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('product_id', $request->product_id)
            ->where('warehouse_id', $request->from_warehouse_id)
            ->where('is_free_gift', false)
            ->whereColumn('qty_cartons', '>', 'reserved_cartons')
            ->orderBy('exp_date')->orderBy('rcv_date')
            ->get();

        $available = $batches->sum(fn (InventoryBatch $batch) => $batch->freeCartons());

        if ($available < $request->qty_cartons) {
            throw new InsufficientStockException([[
                'product_id' => $request->product_id, 'warehouse_id' => $request->from_warehouse_id,
                'needed' => $request->qty_cartons, 'available' => $available,
            ]]);
        }

        $remaining = $request->qty_cartons;
        $firstTransferId = null;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $batch->freeCartons());

            $transfer = $this->transfers->transfer([
                'tenant_id' => $request->tenant_id,
                'product_id' => $request->product_id,
                'from_warehouse_id' => $request->from_warehouse_id,
                'to_warehouse_id' => $request->warehouse_id,
                'batch_no' => $batch->batch_no,
                'qty_cartons' => $take,
                'notes' => "Stock request {$request->number}",
                'created_by' => $actor['actor_id'] ?? null,
                'actor_id' => $actor['actor_id'] ?? null,
                'actor_name' => $actor['actor_name'] ?? null,
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            $firstTransferId ??= $transfer->id;
            $remaining -= $take;
        }

        return ['type' => 'transfer', 'id' => $firstTransferId];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{type: string, id: string}
     */
    private function raiseDocument(StockRequest $request, array $options, array $actor): array
    {
        $event = new StockRequestGranted($request, $options, $actor);

        Event::dispatch($event);

        if ($event->result === null) {
            throw ValidationException::withMessages(['source' => "Nothing is set up to handle a request to {$request->source}."]);
        }

        return $event->result;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(StockRequest $request, string $operation, ?array $previous, ?array $new, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'inventory',
            'entity_type' => 'StockRequest',
            'entity_id' => $request->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $request->tenant_id,
            'warehouse_id' => $request->warehouse_id,
            'prev_values' => $previous,
            'new_values' => $new,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }

    /**
     * @param  array{actor_id?: ?string}  $actor
     */
    private function tellRequester(StockRequest $request, string $title, string $body, array $actor): void
    {
        if ($request->requested_by === null || $request->requested_by === ($actor['actor_id'] ?? null)) {
            return;
        }

        Notification::create([
            'tenant_id' => $request->tenant_id,
            'user_id' => $request->requested_by,
            'actor_id' => $actor['actor_id'] ?? null,
            'type' => 'stock_request',
            'title' => $title,
            'body' => $body,
            'link_module' => 'inventory',
            'link_entity' => 'stock-requests',
            'link_id' => $request->id,
            'unread' => true,
        ]);
    }
}
