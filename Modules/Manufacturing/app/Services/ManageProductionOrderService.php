<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Exceptions\ProductionOrderNotDraftException;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * Create / edit / delete for production orders. While an order is a draft anything about it may
 * change; once confirmed only the planning details (priority, operator, dates, notes) may, since
 * its components and costs are then fixed. Moving an order through its life is the lifecycle
 * service's job.
 */
class ManageProductionOrderService
{
    /** What may still be edited after the order is confirmed. */
    private const SOFT_FIELDS = ['priority', 'assigned_to', 'notes', 'planned_start', 'planned_finish'];

    public function __construct(
        private readonly OrderHistoryService $history,
        private readonly NumberSequenceService $numbers,
    ) {}

    /**
     * `qty_cartons` is the quantity to make; `runs` (the older way) multiplies the recipe's yield. Give either.
     *
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(array $data, array $actor = []): ProductionOrder
    {
        $bom = Bom::query()->findOrFail($data['bom_id']);

        if (! $bom->isUsableOn()) {
            throw new ManufacturingRuleException("BoM {$bom->code} is not approved and active, so no order can be raised from it.");
        }

        return DB::transaction(function () use ($data, $bom, $actor) {
            $settings = ManufacturingSetting::forTenant($data['tenant_id']);
            $output = $this->outputQty($bom, $data);
            $productionDate = $data['production_date'] ?? now()->toDateString();

            $order = ProductionOrder::create([
                ...$data,
                'number' => $this->numbers->next($data['tenant_id'], 'mo', $settings->mo_prefix, $settings->number_padding),
                'runs' => $this->runs($bom, $output),
                'output_qty_cartons' => $output,
                'production_date' => $productionDate,
                'mfg_date' => $data['mfg_date'] ?? $productionDate,
                'batch_no' => $data['batch_no'] ?? 'MO-'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
                'conversion_cost' => $data['conversion_cost'] ?? round((float) $bom->conversion_cost * $output / max(1, $bom->output_qty_cartons), 2),
                'warehouse_id' => $data['warehouse_id'] ?? $settings->default_warehouse_id ?? throw new ManufacturingRuleException('Choose a warehouse, or set a default one in the manufacturing settings.'),
            ]);

            $this->history->record($order, 'create', null, 'draft', $actor, [
                'bom' => (string) $bom->code, 'qty_cartons' => (string) $output, 'source' => (string) ($order->source_type ?? 'manual'),
            ], 'INSERT');

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(ProductionOrder $order, array $data, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                if (! in_array($locked->status, ['confirmed', 'planned', 'in_progress', 'paused'], true)) {
                    throw new ProductionOrderNotDraftException;
                }

                if (array_diff(array_keys($data), self::SOFT_FIELDS) !== []) {
                    throw new ProductionOrderNotDraftException('Once an order is confirmed only its priority, operator, planned dates and notes can change. Cancel it to change anything else.');
                }
            }

            if ($locked->isDraft()) {
                $bom = Bom::query()->findOrFail($data['bom_id'] ?? $locked->bom_id);

                if (isset($data['bom_id']) && ! $bom->isUsableOn()) {
                    throw new ManufacturingRuleException("BoM {$bom->code} is not approved and active, so no order can be raised from it.");
                }

                $quantityChanged = isset($data['qty_cartons']) || isset($data['runs']) || isset($data['bom_id']);

                if ($quantityChanged) {
                    // Changing the recipe alone keeps the quantity being made.
                    $data['output_qty_cartons'] = isset($data['qty_cartons']) || isset($data['runs'])
                        ? $this->outputQty($bom, $data)
                        : $locked->output_qty_cartons;
                    $data['runs'] = $this->runs($bom, $data['output_qty_cartons']);

                    if (! array_key_exists('conversion_cost', $data)) {
                        $data['conversion_cost'] = round((float) $bom->conversion_cost * $data['output_qty_cartons'] / max(1, $bom->output_qty_cartons), 2);
                    }
                }

                unset($data['qty_cartons']);
            }

            $locked->fill($data);
            $dirty = $locked->getDirty();
            $previous = array_intersect_key($locked->getOriginal(), $dirty);
            $locked->save();

            if ($dirty !== []) {
                $this->history->record($locked, 'update', $locked->status, $locked->status, $actor, array_map(fn ($v) => (string) $v, $dirty) + ['was' => json_encode(array_map(fn ($v) => (string) $v, $previous))]);
            }

            return $locked;
        });
    }

    /**
     * Drafts and cancelled orders can be removed; an order that is under way or finished cannot.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function delete(ProductionOrder $order, array $actor = []): void
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'completed') {
                throw new ProductionOrderNotDraftException('A completed production order cannot be deleted.');
            }

            if (! in_array($locked->status, ['draft', 'cancelled'], true)) {
                throw new ProductionOrderNotDraftException('Cancel the order before deleting it.');
            }

            $this->history->record($locked, 'delete', $locked->status, null, $actor, ['bom_id' => $locked->bom_id, 'runs' => (string) $locked->runs], 'DELETE');
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function outputQty(Bom $bom, array $data): int
    {
        if (isset($data['qty_cartons'])) {
            return (int) $data['qty_cartons'];
        }

        return $bom->output_qty_cartons * (int) ($data['runs'] ?? 1);
    }

    private function runs(Bom $bom, int $output): int
    {
        return max(1, (int) ceil($output / max(1, $bom->output_qty_cartons)));
    }
}
