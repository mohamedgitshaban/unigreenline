<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Exceptions\SalesOrderLockedException;
use Modules\Sales\Models\SalesOrder;

/**
 * Deletes a draft sales order. Drafts have no stock, invoice or journal
 * side effects (those start at picking/invoiced/delivered), so the order and
 * its lines (cascade) can simply go. Anything further along is refused —
 * cancel it instead.
 */
class DeleteSalesOrderService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function delete(SalesOrder $order, array $actor = []): void
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft' || $locked->stock_deducted || $locked->invoices()->exists()) {
                throw new SalesOrderLockedException;
            }

            $locked->delete();

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'SalesOrder',
                'entity_id' => $locked->id,
                'operation' => 'DELETE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => ['customer_id' => $locked->customer_id, 'status' => $locked->status, 'total' => (string) $locked->total],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);
        });
    }
}
