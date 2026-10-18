<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Events\FinishedGoodsReceived;
use Modules\Manufacturing\Events\MaterialsConsumed;
use Modules\Manufacturing\Exceptions\BatchNumberTakenException;
use Modules\Manufacturing\Exceptions\InvalidOrderTransitionException;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\ProductionConsumption;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderComponent;
use Modules\Manufacturing\Models\ProductionOutput;
use Modules\Manufacturing\Models\ProductionScrap;

/**
 * Everything that moves stock or cost on a manufacturing order: issuing
 * materials, receiving finished goods and by-products, and scrap. Each public
 * method is one atomic unit (it locks the order first, so two requests for the
 * same order run one after the other) and can be made safe to repeat with an
 * idempotency key.
 */
class ProductionRecordingService
{
    public function __construct(
        private readonly MaterialIssueService $issue,
        private readonly MaterialReservationService $reservations,
        private readonly OrderComponentService $componentService,
        private readonly ProductionCostingService $costing,
        private readonly ManufacturingPostingService $posting,
        private readonly IdempotencyService $idempotency,
        private readonly OrderHistoryService $history,
        private readonly NumberSequenceService $numbers,
    ) {}

    /**
     * Issues materials by hand.
     *
     * @param  array{lines: array<int, array{component_id?: string, product_id?: string, cartons: int, work_order_id?: ?string}>, idempotency_key?: ?string}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{order: ProductionOrder, replayed: bool}
     */
    public function consumeMaterials(ProductionOrder $order, array $input, array $actor = []): array
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $locked = $this->lock($order);
            $this->assertStatus($locked, ['confirmed', 'planned', 'in_progress'], 'have materials issued');

            $outcome = $this->idempotency->once($locked, 'consume-materials', $input['idempotency_key'] ?? null, function () use ($locked, $input, $actor) {
                $settings = ManufacturingSetting::forTenant($locked->tenant_id);
                $rows = [];
                $total = 0.0;

                foreach ($input['lines'] as $line) {
                    $component = $this->findComponent($locked, $line);
                    $cartons = (int) $line['cartons'];
                    $remaining = $component->required_cartons - $component->consumed_cartons;

                    if ($cartons > $remaining && ! $settings->allow_over_consumption) {
                        throw new ManufacturingRuleException("Only {$remaining} more carton(s) of that component are needed on this order; issuing {$cartons} would be more than the order requires.");
                    }

                    [$value, $issued] = $this->issueComponent($locked, $component, $cartons, $line['work_order_id'] ?? null, $actor);
                    $total += $value;
                    $rows = [...$rows, ...$issued];
                }

                $this->postIssued($locked, $rows, $actor);
                $this->reservations->refreshStatus($locked);
                $this->history->record($locked, 'consume-materials', $locked->status, $locked->status, $actor, ['value' => number_format($total, 2, '.', ''), 'lines' => (string) count($input['lines'])]);
                event(new MaterialsConsumed($locked->tenant_id, $locked->id, ['value' => round($total, 2)]));

                return ['consumption_ids' => array_map(fn ($row) => $row->id, $rows)];
            });

            return ['order' => $locked->refresh(), 'replayed' => $outcome['replayed']];
        });
    }

    /**
     * Receives finished goods (and the by-products that go with them), issuing the materials for them
     * first when the company backflushes.
     *
     * @param  array{qty?: int, rejected?: int, batch_no?: ?string, exp_date?: ?string, mfg_date?: ?string, reject_reason?: ?string, notes?: ?string, work_order_id?: ?string, idempotency_key?: ?string}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{order: ProductionOrder, replayed: bool}
     */
    public function recordProduction(ProductionOrder $order, array $input, array $actor = []): array
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $locked = $this->lock($order);
            $this->assertStatus($locked, ['confirmed', 'planned', 'in_progress'], 'record production');

            $outcome = $this->idempotency->once($locked, 'record-production', $input['idempotency_key'] ?? null, function () use ($locked, $input, $actor) {
                if (in_array($locked->status, ['confirmed', 'planned'], true)) {
                    $this->lifecycle()->start($locked, $actor);
                    $locked->refresh();
                }

                return $this->produce($locked, $input, $actor);
            });

            return ['order' => $locked->refresh(), 'replayed' => $outcome['replayed']];
        });
    }

    /**
     * The receipt itself. The caller holds the order lock and has made sure the order is in progress.
     *
     * @param  array<string, mixed>  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{output_ids: array<int, string>}
     */
    public function produce(ProductionOrder $order, array $input, array $actor = []): array
    {
        $settings = ManufacturingSetting::forTenant($order->tenant_id);
        $good = (int) ($input['qty'] ?? 0);
        $rejected = (int) ($input['rejected'] ?? 0);

        if ($good + $rejected <= 0) {
            throw new ManufacturingRuleException('Enter the quantity produced or rejected.');
        }

        $remaining = $order->remainingQty();

        if ($good + $rejected > $remaining) {
            throw new ManufacturingRuleException("Only {$remaining} carton(s) are left to make on this order.");
        }

        if (! $settings->allow_partial_production && $good + $rejected !== $remaining) {
            throw new ManufacturingRuleException("Partial production is switched off: record all {$remaining} remaining carton(s) at once.");
        }

        $this->assertReceivable($order, $good + $rejected);

        $exp = $input['exp_date'] ?? $order->exp_date?->toDateString();
        if ($good > 0 && ($exp === null || $exp <= now()->toDateString())) {
            throw new ManufacturingRuleException('Give the expiry date of the finished goods (it must be in the future).');
        }

        $cumulative = $order->produced_qty_cartons + $order->rejected_qty_cartons + $good + $rejected;

        $issuedValue = $this->issueForOutput($order, $settings, $cumulative, $actor);

        $valuation = $this->costing->valueReceipt($order->refresh(), $settings, $good, $rejected);
        $outputIds = [];
        $firstBatch = null;
        $finishedValue = 0.0;

        if ($good > 0) {
            [$finishedRow, $byProductRows] = $this->receive($order, $settings, $input, $good, $valuation['value'], $exp);
            $outputIds = [$finishedRow->id, ...array_column($byProductRows, 'id')];
            $firstBatch = $finishedRow->batch_id;
            $finishedValue = (float) $finishedRow->line_value;
        }

        $entry = $this->posting->postReceipt($order, $valuation['value'], $valuation['material_portion'], $valuation['overhead_applied'], $actor['actor_id'] ?? null);

        if ($entry !== null && $outputIds !== []) {
            ProductionOutput::query()->whereIn('id', $outputIds)->update(['journal_entry_id' => $entry->id]);
        }

        if ($rejected > 0) {
            $rawValue = $valuation['share'] > 0 ? round(max(0.0, $valuation['pool']) * $valuation['share'] * $rejected / ($good + $rejected), 2) : 0.0;
            $this->createScrap($order, 'finished', $order->bom->product_id, $rejected, $input['reject_reason'] ?? 'Rejected in production', $rawValue, $input['work_order_id'] ?? null, 'approved', $actor);
        }

        $producedBefore = $order->produced_qty_cartons;
        $finishedCapitalized = (float) ProductionOutput::query()->where('production_order_id', $order->id)->where('kind', 'finished')->sum('line_value');

        $order->update([
            'produced_qty_cartons' => $producedBefore + $good,
            'rejected_qty_cartons' => $order->rejected_qty_cartons + $rejected,
            'material_cost' => $valuation['material'],
            'actual_operation_cost' => $valuation['operation'],
            'actual_overhead_cost' => $valuation['overhead_total'],
            'capitalized_cost' => round((float) $order->capitalized_cost + $valuation['value'], 2),
            'capitalized_material' => round((float) $order->capitalized_material + $valuation['material_portion'], 2),
            'cost_per_carton' => $producedBefore + $good > 0 ? round($finishedCapitalized / ($producedBefore + $good), 2) : 0,
            'output_batch_id' => $order->output_batch_id ?? $firstBatch,
            'journal_entry_id' => $order->journal_entry_id ?? $entry?->id,
        ]);

        Warehouse::query()->find($order->warehouse_id)?->recalculateStockValue();

        $this->reservations->refreshStatus($order);
        $this->history->record($order, 'record-production', $order->status, $order->status, $actor, [
            'good' => (string) $good, 'rejected' => (string) $rejected, 'value' => number_format($valuation['value'], 2, '.', ''),
        ]);

        event(new FinishedGoodsReceived($order->tenant_id, $order->id, ['good' => $good, 'rejected' => $rejected, 'value' => $valuation['value']]));

        return ['output_ids' => $outputIds, 'issued_value' => round($issuedValue, 2)];
    }

    /**
     * Wastes material. Goes through approval first when the company requires it.
     *
     * @param  array{kind?: string, product_id?: ?string, cartons: int, reason: string, work_order_id?: ?string, notes?: ?string, idempotency_key?: ?string}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{order: ProductionOrder, scrap: ProductionScrap, replayed: bool}
     */
    public function recordScrap(ProductionOrder $order, array $input, array $actor = []): array
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $locked = $this->lock($order);
            $this->assertStatus($locked, ['confirmed', 'planned', 'in_progress', 'paused'], 'have scrap recorded');
            $kind = $input['kind'] ?? 'component';
            $scrapId = null;

            $outcome = $this->idempotency->once($locked, 'record-scrap', $input['idempotency_key'] ?? null, function () use ($locked, $input, $actor, $kind, &$scrapId) {
                $settings = ManufacturingSetting::forTenant($locked->tenant_id);
                $cartons = (int) $input['cartons'];

                if ($kind === 'finished') {
                    if ($cartons > $locked->remainingQty()) {
                        throw new ManufacturingRuleException("Only {$locked->remainingQty()} carton(s) are left to make on this order.");
                    }

                    $scrap = $this->createScrap($locked, 'finished', $locked->bom->product_id, $cartons, $input['reason'], 0.0, $input['work_order_id'] ?? null, $settings->scrap_requires_approval ? 'pending' : 'approved', $actor, $input['notes'] ?? null);
                } else {
                    $component = $locked->components()->where('product_id', $input['product_id'] ?? null)->first()
                        ?? throw new ManufacturingRuleException('That product is not a component of this order.');

                    $scrap = $this->createScrap($locked, 'component', $component->product_id, $cartons, $input['reason'], 0.0, $input['work_order_id'] ?? null, $settings->scrap_requires_approval ? 'pending' : 'approved', $actor, $input['notes'] ?? null);
                }

                if ($scrap->status === 'approved') {
                    $this->applyScrap($locked, $scrap, $settings, $actor);
                }

                $scrapId = $scrap->id;
                $this->history->record($locked, 'record-scrap', $locked->status, $locked->status, $actor, ['scrap' => $scrap->number, 'status' => $scrap->status, 'cartons' => (string) $cartons]);

                return ['scrap_id' => $scrap->id];
            });

            return ['order' => $locked->refresh(), 'scrap' => ProductionScrap::query()->findOrFail($outcome['result']['scrap_id']), 'replayed' => $outcome['replayed']];
        });
    }

    /**
     * Approves or rejects a scrap request that was waiting for a decision.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function decideScrap(ProductionScrap $scrap, bool $approve, array $actor = []): ProductionScrap
    {
        return DB::transaction(function () use ($scrap, $approve, $actor) {
            $order = $this->lock($scrap->productionOrder);
            $locked = ProductionScrap::query()->whereKey($scrap->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new ManufacturingRuleException('Only a scrap request that is waiting for a decision can be approved or rejected.');
            }

            $this->assertStatus($order, ['confirmed', 'planned', 'in_progress', 'paused'], 'have scrap decided');

            $locked->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor['actor_id'] ?? null, 'decided_at' => now()]);

            if ($approve) {
                $this->applyScrap($order, $locked, ManufacturingSetting::forTenant($order->tenant_id), $actor);
            }

            $this->history->record($order, $approve ? 'approve-scrap' : 'reject-scrap', $order->status, $order->status, $actor, ['scrap' => $locked->number]);

            return $locked->refresh();
        });
    }

    /**
     * Issues the materials the output made so far calls for. Returns the value issued.
     * With manual consumption nothing is issued; the materials must already have been.
     * With `$workOrderId`, only the components tied to that operation.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function issueForOutput(ProductionOrder $order, ManufacturingSetting $settings, int $cumulativeOutput, array $actor = [], ?string $workOrderId = null): float
    {
        $value = 0.0;
        $short = [];
        $rows = [];

        $components = $order->components()->where('optional', false)
            ->when($workOrderId, fn ($query) => $query->where('work_order_id', $workOrderId))
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($components as $component) {
            $target = $this->componentService->targetConsumed($component, $order->output_qty_cartons, $cumulativeOutput);
            $delta = $target - $component->consumed_cartons;

            if ($delta <= 0) {
                continue;
            }

            if ($settings->consumption_policy === 'manual') {
                // Issuing is manual: an operation finishing issues nothing, but receiving output requires it done.
                if ($workOrderId === null) {
                    $short[] = ['product_id' => $component->product_id, 'still_to_issue' => $delta];
                }

                continue;
            }

            [$issued, $issuedRows] = $this->issueComponent($order, $component, $delta, $workOrderId, $actor);
            $value += $issued;
            $rows = [...$rows, ...$issuedRows];
        }

        $this->postIssued($order, $rows, $actor);

        if ($short !== []) {
            throw new ManufacturingRuleException('Issue the materials for this output first (consumption is set to manual).', ['materials_to_issue' => $short]);
        }

        if ($value > 0) {
            event(new MaterialsConsumed($order->tenant_id, $order->id, ['value' => round($value, 2)]));
        }

        return $value;
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{0: float, 1: array<int, ProductionConsumption>} Value issued and the consumption rows.
     */
    private function issueComponent(ProductionOrder $order, ProductionOrderComponent $component, int $cartons, ?string $workOrderId, array $actor): array
    {
        $taken = $this->issue->issue($order, $component, $cartons);
        $rows = [];
        $total = 0.0;

        foreach ($taken as $row) {
            $value = round($row['cartons'] * $row['cost_per_carton'], 2);
            $total += $value;

            $rows[] = ProductionConsumption::create([
                'production_order_id' => $order->id,
                'component_id' => $component->id,
                'work_order_id' => $workOrderId,
                'kind' => 'consume',
                'product_id' => $component->product_id,
                'batch_id' => $row['batch_id'],
                'batch_no' => $row['batch_no'],
                'qty_cartons' => $row['cartons'],
                'cost_per_carton' => $row['cost_per_carton'],
                'line_value' => $value,
                'recorded_by' => $actor['actor_id'] ?? null,
            ]);
        }

        $component->increment('consumed_cartons', $cartons);

        return [round($total, 2), $rows];
    }

    /**
     * Books one entry for everything issued in a step (work-in-progress policy) and links it to the rows.
     *
     * @param  array<int, ProductionConsumption>  $rows
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function postIssued(ProductionOrder $order, array $rows, array $actor): void
    {
        if ($rows === []) {
            return;
        }

        $entry = $this->posting->postIssue($order, round(array_sum(array_map(fn ($row) => (float) $row->line_value, $rows)), 2), $actor['actor_id'] ?? null);

        if ($entry !== null) {
            ProductionConsumption::query()->whereIn('id', array_map(fn ($row) => $row->id, $rows))->update(['journal_entry_id' => $entry->id]);
        }
    }

    /**
     * Creates the finished-goods batch and any by-product batches for a receipt.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: ProductionOutput, 1: array<int, ProductionOutput>}
     */
    private function receive(ProductionOrder $order, ManufacturingSetting $settings, array $input, int $good, float $totalValue, string $exp): array
    {
        $receiptNo = $order->outputs()->where('kind', 'finished')->count() + 1;
        $baseBatch = $input['batch_no'] ?? $order->batch_no;
        $batchNo = $receiptNo === 1 || isset($input['batch_no']) ? $baseBatch : "{$order->batch_no}-{$receiptNo}";

        $by = $this->byProductSlices($order, $settings, $good, $totalValue);
        $finishedValue = round($totalValue - array_sum(array_column($by, 'value')), 2);

        $finished = $this->createOutput($order, 'finished', $order->bom->product_id, $batchNo, $good, $finishedValue, $exp, $input, $input['work_order_id'] ?? null);

        $byRows = [];
        foreach ($by as $index => $slice) {
            $byRows[] = $this->createOutput(
                $order, 'by_product', $slice['product_id'], "{$batchNo}-BP".($index + 1), $slice['qty'], $slice['value'], $exp, $input, null, $slice['warehouse_id'],
            );
        }

        return [$finished, $byRows];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createOutput(ProductionOrder $order, string $kind, string $productId, string $batchNo, int $qty, float $value, string $exp, array $input, ?string $workOrderId, ?string $warehouseId = null): ProductionOutput
    {
        $warehouseId ??= $order->warehouse_id;

        $taken = InventoryBatch::query()
            ->where('tenant_id', $order->tenant_id)->where('warehouse_id', $warehouseId)->where('batch_no', $batchNo)
            ->exists();

        if ($taken) {
            throw new BatchNumberTakenException($batchNo);
        }

        $unit = round($value / $qty, 2);

        $batch = InventoryBatch::create([
            'tenant_id' => $order->tenant_id,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'batch_no' => $batchNo,
            'mfg_date' => $input['mfg_date'] ?? $order->mfg_date ?? $order->production_date,
            'exp_date' => $exp,
            'rcv_date' => now()->toDateString(),
            'qty_cartons' => $qty,
            'qty_packs' => 0,
            'cost_per_carton' => $unit,
        ]);

        return ProductionOutput::create([
            'production_order_id' => $order->id,
            'work_order_id' => $workOrderId,
            'kind' => $kind,
            'product_id' => $productId,
            'batch_id' => $batch->id,
            'batch_no' => $batchNo,
            'qty_cartons' => $qty,
            'cost_per_carton' => $unit,
            'line_value' => $value,
            'notes' => $input['notes'] ?? null,
        ]);
    }

    /**
     * By-products this receipt yields and the cost each carries. The expected by-products come from the
     * revision of the recipe the order was confirmed with, so a later edit does not change them.
     *
     * @return array<int, array{product_id: string, qty: int, value: float, warehouse_id: ?string}>
     */
    private function byProductSlices(ProductionOrder $order, ManufacturingSetting $settings, int $good, float $totalValue): array
    {
        $definitions = $order->bomRevision?->snapshot['by_products'] ?? $order->bom->byProducts()->get()->map(fn ($b) => [
            'product_id' => $b->product_id, 'qty_cartons' => $b->qty_cartons, 'cost_share_pct' => (string) $b->cost_share_pct, 'warehouse_id' => $b->warehouse_id,
        ])->all();

        if ($definitions === []) {
            return [];
        }

        $bomOutput = max(1, $order->bomRevision?->snapshot['output_qty_cartons'] ?? $order->bom->output_qty_cartons);
        $runs = $order->output_qty_cartons / $bomOutput;
        $goodAfter = $order->produced_qty_cartons + $good;
        $fraction = $order->output_qty_cartons > 0 ? min(1.0, $goodAfter / $order->output_qty_cartons) : 0.0;
        $slices = [];

        foreach ($definitions as $definition) {
            $expectedTotal = (int) floor(round($definition['qty_cartons'] * $runs, 4) + 1e-9);
            $target = (int) floor(round($definition['qty_cartons'] * $runs * $fraction, 4) + 1e-9);
            $received = (int) ProductionOutput::query()->where('production_order_id', $order->id)->where('kind', 'by_product')
                ->where('product_id', $definition['product_id'])->sum('qty_cartons');
            $qty = $target - $received;

            if ($qty > 0) {
                $slices[] = ['definition' => $definition, 'qty' => $qty, 'expected_total' => max(1, $expectedTotal)];
            }
        }

        if ($slices === [] || $settings->by_product_cost_method === 'none') {
            return array_map(fn ($s) => ['product_id' => $s['definition']['product_id'], 'qty' => $s['qty'], 'value' => 0.0, 'warehouse_id' => $s['definition']['warehouse_id'] ?? null], $slices);
        }

        $values = [];
        if ($settings->by_product_cost_method === 'sales_value') {
            $products = Product::query()->whereIn('id', [...array_column(array_column($slices, 'definition'), 'product_id'), $order->bom->product_id])->get()->keyBy('id');
            $weight = fn (string $productId, int $qty) => $qty * (float) $products[$productId]->pack_selling_price * $products[$productId]->carton_qty;
            $weights = array_map(fn ($s) => $weight($s['definition']['product_id'], $s['qty']), $slices);
            $total = array_sum($weights) + $weight($order->bom->product_id, $good);

            foreach ($slices as $i => $s) {
                $values[$i] = $total > 0 ? round($totalValue * $weights[$i] / $total, 2) : 0.0;
            }
        } else {
            foreach ($slices as $i => $s) {
                $share = (float) ($s['definition']['cost_share_pct'] ?? 0) / 100 * min(1.0, $s['qty'] / $s['expected_total']);
                $values[$i] = round($totalValue * $share, 2);
            }
        }

        // Never hand the by-products more than the receipt cost.
        $excess = array_sum($values) - $totalValue;
        if ($excess > 0) {
            $values[array_key_last($values)] = round($values[array_key_last($values)] - $excess, 2);
        }

        return array_map(fn ($s, $i) => [
            'product_id' => $s['definition']['product_id'], 'qty' => $s['qty'], 'value' => max(0.0, $values[$i]), 'warehouse_id' => $s['definition']['warehouse_id'] ?? null,
        ], $slices, array_keys($slices));
    }

    /**
     * Cartons an operation rejected: they will never be received, and the product absorbs their cost.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function recordWorkOrderRejects(ProductionOrder $order, \Modules\Manufacturing\Models\WorkOrder $workOrder, int $cartons, string $reason, array $actor = []): ProductionScrap
    {
        $scrap = $this->createScrap($order, 'finished', $order->bom->product_id, $cartons, $reason, 0.0, $workOrder->id, 'approved', $actor);
        $order->update(['rejected_qty_cartons' => $order->rejected_qty_cartons + $cartons]);

        return $scrap;
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function createScrap(ProductionOrder $order, string $kind, string $productId, int $cartons, string $reason, float $cost, ?string $workOrderId, string $status, array $actor, ?string $notes = null): ProductionScrap
    {
        $settings = ManufacturingSetting::forTenant($order->tenant_id);

        $scrap = ProductionScrap::create([
            'tenant_id' => $order->tenant_id,
            'number' => $this->numbers->next($order->tenant_id, 'scrap', $settings->scrap_prefix, $settings->number_padding),
            'production_order_id' => $order->id,
            'work_order_id' => $workOrderId,
            'kind' => $kind,
            'product_id' => $productId,
            'qty_cartons' => $cartons,
            'reason' => $reason,
            'status' => $status,
            'scrap_cost' => $cost,
            'requested_by' => $actor['actor_id'] ?? null,
            'decided_by' => $status === 'approved' ? ($actor['actor_id'] ?? null) : null,
            'decided_at' => $status === 'approved' ? now() : null,
            'notes' => $notes,
        ]);

        return $scrap;
    }

    /**
     * Carries out an approved scrap: wasted material leaves stock; rejected output uses up quantity.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function applyScrap(ProductionOrder $order, ProductionScrap $scrap, ManufacturingSetting $settings, array $actor): void
    {
        if ($scrap->kind === 'finished') {
            $order->update(['rejected_qty_cartons' => $order->rejected_qty_cartons + $scrap->qty_cartons]);

            return;
        }

        $component = $order->components()->where('product_id', $scrap->product_id)->firstOrFail();
        $taken = $this->issue->issue($order, $component, $scrap->qty_cartons);
        $total = 0.0;
        $absorb = $settings->scrap_cost_policy === 'absorb';
        $rows = [];

        foreach ($taken as $row) {
            $value = round($row['cartons'] * $row['cost_per_carton'], 2);
            $total += $value;
            $rows[] = ProductionConsumption::create([
                'production_order_id' => $order->id, 'component_id' => $component->id, 'work_order_id' => $scrap->work_order_id,
                'kind' => 'scrap', 'absorbed' => $absorb, 'product_id' => $scrap->product_id, 'batch_id' => $row['batch_id'],
                'batch_no' => $row['batch_no'], 'qty_cartons' => $row['cartons'], 'cost_per_carton' => $row['cost_per_carton'],
                'line_value' => $value, 'recorded_by' => $actor['actor_id'] ?? null,
            ]);
        }

        $total = round($total, 2);
        $entry = $absorb
            ? $this->posting->postIssue($order, $total, $actor['actor_id'] ?? null)
            : $this->posting->postExpensedScrap($order, $total, $actor['actor_id'] ?? null);

        if ($entry !== null) {
            ProductionConsumption::query()->whereIn('id', array_map(fn ($r) => $r->id, $rows))->update(['journal_entry_id' => $entry->id]);
        }

        $scrap->update(['scrap_cost' => $total, 'journal_entry_id' => $entry?->id]);
        $order->update(['scrap_cost' => round((float) $order->scrap_cost + $total, 2)]);

        $this->reservations->refreshStatus($order);
        Warehouse::query()->find($order->warehouse_id)?->recalculateStockValue();
    }

    /**
     * With routing, finished goods can only be received for what the last operation has processed.
     */
    private function assertReceivable(ProductionOrder $order, int $cartons): void
    {
        $last = $order->workOrders()->where('status', '!=', 'cancelled')->reorder('sequence', 'desc')->first();

        if ($last === null) {
            return;
        }

        $receivable = $last->qty_processed - $order->produced_qty_cartons;

        if ($cartons > $receivable) {
            throw new ManufacturingRuleException("The last operation ({$last->name}) has only processed {$last->qty_processed} carton(s); {$receivable} more can be received.");
        }
    }

    /**
     * @param  array{component_id?: string, product_id?: string}  $line
     */
    private function findComponent(ProductionOrder $order, array $line): ProductionOrderComponent
    {
        $query = $order->components()->lockForUpdate();

        $component = isset($line['component_id'])
            ? $query->whereKey($line['component_id'])->first()
            : $query->where('product_id', $line['product_id'] ?? null)->first();

        return $component ?? throw new ManufacturingRuleException('That material is not a component of this order.');
    }

    private function lock(ProductionOrder $order): ProductionOrder
    {
        return ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertStatus(ProductionOrder $order, array $allowed, string $what): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw new ManufacturingRuleException("A manufacturing order that is {$order->status} cannot {$what}.");
        }
    }

    private function lifecycle(): ProductionOrderLifecycleService
    {
        return app(ProductionOrderLifecycleService::class);
    }
}
