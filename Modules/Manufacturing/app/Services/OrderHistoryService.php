<?php

namespace Modules\Manufacturing\Services;

use Modules\Core\Services\AuditLogService;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderEvent;

/**
 * Writes what happened to an order in two places: its own append-only history
 * (what the order screen shows) and the company-wide tamper-evident audit log.
 */
class OrderHistoryService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        ProductionOrder $order,
        string $action,
        ?string $from,
        ?string $to,
        array $actor = [],
        ?array $payload = null,
        string $auditOperation = 'UPDATE',
    ): ProductionOrderEvent {
        $event = ProductionOrderEvent::create([
            'tenant_id' => $order->tenant_id,
            'production_order_id' => $order->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $actor['actor_id'] ?? null,
            'payload' => $payload,
        ]);

        $this->auditLog->record([
            'module' => 'manufacturing',
            'entity_type' => 'ProductionOrder',
            'entity_id' => $order->id,
            'operation' => $auditOperation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $order->tenant_id,
            'warehouse_id' => $order->warehouse_id,
            'prev_values' => $from === null ? null : ['status' => $from],
            'new_values' => ['action' => $action] + ($to === null ? [] : ['status' => $to]) + array_map(
                fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v),
                $payload ?? [],
            ),
            'ip_address' => $actor['ip_address'] ?? null,
        ]);

        return $event;
    }
}
