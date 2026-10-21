<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\FefoStockService;
use Modules\Inventory\Support\StockMovementContext;
use Modules\Manufacturing\Exceptions\BatchNumberTakenException;
use Modules\Manufacturing\Exceptions\ProductionOrderNotDraftException;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * Completes a draft production order in one transaction:
 *  - consumes every BOM component × runs from the warehouse, earliest-expiring
 *    batch first (FEFO); the whole order is refused, nothing written, if any
 *    component is short;
 *  - costs the output: material cost (what the consumed batches cost) plus
 *    the order's conversion cost, spread over the cartons produced;
 *  - creates the finished-goods batch at that cost;
 *  - posts Dr Inventory (1300) for the finished goods / Cr Inventory (1300)
 *    for the materials used / Cr Manufacturing Overhead Absorbed (6400) for
 *    the conversion cost, so inventory carries the full cost of what was made.
 */
class CompleteProductionOrderService
{
    public function __construct(
        private readonly FefoStockService $fefoStock,
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function complete(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new ProductionOrderNotDraftException('Only draft production orders can be completed.');
            }

            $bom = $locked->bom()->with('lines')->firstOrFail();

            $batchTaken = InventoryBatch::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('warehouse_id', $locked->warehouse_id)
                ->where('batch_no', $locked->batch_no)
                ->exists();

            if ($batchTaken) {
                throw new BatchNumberTakenException($locked->batch_no);
            }

            $deductions = $this->fefoStock->deduct($bom->lines->map(fn ($line) => [
                'product_id' => $line->component_product_id,
                'warehouse_id' => $locked->warehouse_id,
                'cartons_needed' => $line->qty_cartons * $locked->runs,
            ])->all(), ['type' => 'production_consumption', 'reference_type' => 'production_order', 'reference_id' => $locked->id]);

            $costs = InventoryBatch::query()->whereIn('id', array_column($deductions, 'batch_id'))->pluck('cost_per_carton', 'id');
            $materialCost = 0.0;

            foreach ($deductions as $deduction) {
                $costPerCarton = (float) $costs[$deduction['batch_id']];
                $value = round($deduction['cartons_taken'] * $costPerCarton, 2);
                $materialCost += $value;

                $locked->consumptions()->create([
                    'product_id' => $deduction['product_id'],
                    'batch_id' => $deduction['batch_id'],
                    'batch_no' => $deduction['batch_no'],
                    'qty_cartons' => $deduction['cartons_taken'],
                    'cost_per_carton' => $costPerCarton,
                    'line_value' => $value,
                ]);
            }

            $materialCost = round($materialCost, 2);
            $conversionCost = round((float) $locked->conversion_cost, 2);
            $totalCost = round($materialCost + $conversionCost, 2);
            $costPerCarton = round($totalCost / $locked->output_qty_cartons, 2);

            $outputBatch = StockMovementContext::run(
                ['type' => 'production_output', 'reference_type' => 'production_order', 'reference_id' => $locked->id],
                fn () => InventoryBatch::create([
                    'tenant_id' => $locked->tenant_id,
                    'product_id' => $bom->product_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'batch_no' => $locked->batch_no,
                    'mfg_date' => $locked->mfg_date,
                    'exp_date' => $locked->exp_date,
                    'rcv_date' => $locked->production_date,
                    'qty_cartons' => $locked->output_qty_cartons,
                    'qty_packs' => 0,
                    'cost_per_carton' => $costPerCarton,
                ]),
            );

            $entry = $totalCost <= 0 ? null : $this->journalPosting->post(
                $locked->tenant_id,
                $locked->id,
                "Production order {$locked->id}",
                array_values(array_filter([
                    ['account_code' => '1300', 'debit' => $totalCost],
                    ['account_code' => '1300', 'credit' => $materialCost],
                    ['account_code' => '6400', 'credit' => $conversionCost],
                ], fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0)),
                $actor['actor_id'] ?? null,
                $locked->production_date,
            );

            Warehouse::query()->find($locked->warehouse_id)?->recalculateStockValue();

            $locked->update([
                'status' => 'completed',
                'material_cost' => $materialCost,
                'cost_per_carton' => $costPerCarton,
                'output_batch_id' => $outputBatch->id,
                'journal_entry_id' => $entry?->id,
                'completed_by' => $actor['actor_id'] ?? null,
                'completed_at' => now(),
            ]);

            $this->auditLog->record([
                'module' => 'manufacturing',
                'entity_type' => 'ProductionOrder',
                'entity_id' => $locked->id,
                'operation' => 'APPROVE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => ['status' => 'draft'],
                'new_values' => [
                    'status' => 'completed',
                    'material_cost' => (string) $materialCost,
                    'cost_per_carton' => (string) $costPerCarton,
                    'output_batch_id' => $outputBatch->id,
                ],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked->load('consumptions');
        });
    }
}
