<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Models\SalesOrder;

/**
 * Creates a sales order and delivers it in one atomic step — for the
 * counter / van sale where the goods leave with the customer. It is exactly
 * "create the order" followed by "move it to `delivered`": stock is checked
 * and deducted (FEFO, per line warehouse), the invoice and its journal entry
 * are created, and the delivery record is created already delivered; the
 * optional delivery details (driver, date, notes, signed invoice file) are
 * written onto that delivery. If any step fails — credit limit, stock,
 * minimum price — nothing at all is saved.
 */
class CreateAndDeliverSalesOrderService
{
    public function __construct(
        private readonly CreateSalesOrderService $createSalesOrder,
        private readonly UpdateSalesOrderStatusService $updateStatus,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The create-order payload (with actor_* keys) and an optional `delivery` block.
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function handle(array $data, array $actor): SalesOrder
    {
        $delivery = $data['delivery'] ?? [];
        unset($data['delivery']);

        return DB::transaction(function () use ($data, $delivery, $actor) {
            $order = $this->createSalesOrder->create($data);
            $order = $this->updateStatus->transition($order, 'delivered', $actor);

            $details = array_filter([
                'driver' => $delivery['driver'] ?? null,
                'delivery_date' => $delivery['delivery_date'] ?? null,
                'notes' => $delivery['notes'] ?? null,
                'invoice_data' => $delivery['invoice_data'] ?? null,
            ], fn ($value) => $value !== null);

            if ($details !== [] && $order->delivery !== null) {
                $order->delivery->update($details);

                $this->auditLog->record([
                    'module' => 'sales',
                    'entity_type' => 'Delivery',
                    'entity_id' => $order->delivery->id,
                    'operation' => 'UPDATE',
                    'user_id' => $actor['actor_id'] ?? null,
                    'user_name' => $actor['actor_name'] ?? null,
                    'tenant_id' => $order->tenant_id,
                    'new_values' => $details,
                    'ip_address' => $actor['ip_address'] ?? null,
                ]);
            }

            return $order;
        });
    }
}
