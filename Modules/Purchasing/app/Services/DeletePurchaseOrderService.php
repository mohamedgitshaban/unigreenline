<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Purchasing\Exceptions\PurchaseOrderAlreadyReceivedException;
use Modules\Purchasing\Models\PurchaseOrder;

/**
 * Deletes a purchase order that hasn't been received. Creating a PO has no
 * stock, supplier-balance or journal side effects (those all happen on
 * receive), so an unreceived PO can be removed outright — its lines cascade.
 * A received one is refused: undoing a receipt would need stock, AP and
 * journal reversals, not a delete.
 *
 * The row is locked and stock_added re-checked inside the transaction, so a
 * receive landing at the same moment can't leave stock and a journal entry
 * pointing at a deleted PO.
 */
class DeletePurchaseOrderService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function delete(PurchaseOrder $po, array $actor = []): void
    {
        DB::transaction(function () use ($po, $actor) {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($locked->stock_added) {
                throw new PurchaseOrderAlreadyReceivedException;
            }

            $locked->delete();

            $this->auditLog->record([
                'module' => 'purchasing',
                'entity_type' => 'PurchaseOrder',
                'entity_id' => $locked->id,
                'operation' => 'DELETE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => ['supplier_id' => $locked->supplier_id, 'status' => $locked->status, 'total' => (string) $locked->total],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);
        });
    }
}
