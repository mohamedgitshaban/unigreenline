<?php

namespace Modules\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Purchasing\Models\PurchaseOrder;

class CreatePurchaseOrderService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly PurchaseOrderTotalsCalculator $totals,
    ) {}

    /**
     * @param  array{
     *     tenant_id: string, supplier_id: string, warehouse_id: string, created_by: string,
     *     expected_date?: ?string, notes?: ?string, order_date?: string,
     *     actor_id?: ?string, actor_name?: ?string, ip_address?: ?string,
     *     lines: array<int, array{product_id: string, qty_cartons: int, cost_per_carton: float}>,
     * }  $data
     */
    public function create(array $data): PurchaseOrder
    {
        $totals = $this->totals->calculate($data['lines']);
        $computedLines = $totals['lines'];
        $subtotal = $totals['subtotal'];
        $taxAmount = $totals['tax_amount'];
        $total = $totals['total'];

        return DB::transaction(function () use ($data, $computedLines, $subtotal, $taxAmount, $total) {
            $po = PurchaseOrder::create([
                'tenant_id' => $data['tenant_id'],
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'created_by' => $data['created_by'],
                'status' => 'draft',
                'stock_added' => false,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($computedLines as $line) {
                $po->lines()->create([
                    'product_id' => $line['product_id'],
                    'qty_cartons' => $line['qty_cartons'],
                    'cost_per_carton' => $line['cost_per_carton'],
                    'total' => $line['total'],
                ]);
            }

            $this->auditLog->record([
                'module' => 'purchasing',
                'entity_type' => 'PurchaseOrder',
                'entity_id' => $po->id,
                'operation' => 'INSERT',
                'user_id' => $data['actor_id'] ?? null,
                'user_name' => $data['actor_name'] ?? null,
                'tenant_id' => $data['tenant_id'],
                'warehouse_id' => $po->warehouse_id,
                'new_values' => ['supplier_id' => $po->supplier_id, 'total' => (string) $po->total],
                'ip_address' => $data['ip_address'] ?? null,
            ]);

            return $po->load('lines');
        });
    }
}
