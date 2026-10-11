<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Services\AuditLogService;
use Modules\Manufacturing\Exceptions\ProductionOrderNotDraftException;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * Create / edit / cancel / delete for production orders that haven't been
 * completed. Drafts touch no stock and post nothing, so they can change
 * freely; once completed an order's stock movements and journal entry exist
 * and it is left alone.
 */
class ManageProductionOrderService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{tenant_id: string, bom_id: string, warehouse_id: string, runs?: int, production_date?: string, batch_no?: ?string, mfg_date?: ?string, exp_date: string, conversion_cost?: ?float, notes?: ?string, created_by?: ?string}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(array $data, array $actor = []): ProductionOrder
    {
        $bom = Bom::query()->findOrFail($data['bom_id']);
        $runs = $data['runs'] ?? 1;
        $productionDate = $data['production_date'] ?? now()->toDateString();

        return DB::transaction(function () use ($data, $bom, $runs, $productionDate, $actor) {
            $order = ProductionOrder::create([
                ...$data,
                'runs' => $runs,
                'output_qty_cartons' => $bom->output_qty_cartons * $runs,
                'production_date' => $productionDate,
                'mfg_date' => $data['mfg_date'] ?? $productionDate,
                'batch_no' => $data['batch_no'] ?? 'MO-'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
                'conversion_cost' => $data['conversion_cost'] ?? round((float) $bom->conversion_cost * $runs, 2),
            ]);

            $this->record($order, 'INSERT', null, [
                'bom_id' => $order->bom_id, 'runs' => (string) $order->runs, 'output_qty_cartons' => (string) $order->output_qty_cartons,
            ], $actor);

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
            $locked = $this->lockDraft($order);

            $bom = Bom::query()->findOrFail($data['bom_id'] ?? $locked->bom_id);
            $runs = $data['runs'] ?? $locked->runs;

            // Re-derive the yield, and the default conversion cost when the
            // recipe or run count changed without an explicit cost.
            $data['output_qty_cartons'] = $bom->output_qty_cartons * $runs;

            if (! array_key_exists('conversion_cost', $data) && (isset($data['runs']) || isset($data['bom_id']))) {
                $data['conversion_cost'] = round((float) $bom->conversion_cost * $runs, 2);
            }

            $locked->fill($data);
            $dirty = $locked->getDirty();
            $previous = array_intersect_key($locked->getOriginal(), $dirty);
            $locked->save();

            $this->record($locked, 'UPDATE', array_map('strval', $previous), array_map('strval', $dirty), $actor);

            return $locked;
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function cancel(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lockDraft($order, 'Only draft production orders can be cancelled.');

            $locked->update(['status' => 'cancelled']);

            $this->record($locked, 'UPDATE', ['status' => 'draft'], ['status' => 'cancelled'], $actor);

            return $locked;
        });
    }

    /**
     * Drafts and cancelled orders can be removed; completed ones cannot.
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

            $locked->delete();

            $this->record($locked, 'DELETE', ['status' => $locked->status, 'bom_id' => $locked->bom_id, 'runs' => (string) $locked->runs], null, $actor);
        });
    }

    private function lockDraft(ProductionOrder $order, ?string $message = null): ProductionOrder
    {
        $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw $message ? new ProductionOrderNotDraftException($message) : new ProductionOrderNotDraftException;
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(ProductionOrder $order, string $operation, ?array $previous, ?array $new, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'manufacturing',
            'entity_type' => 'ProductionOrder',
            'entity_id' => $order->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $order->tenant_id,
            'warehouse_id' => $order->warehouse_id,
            'prev_values' => $previous,
            'new_values' => $new,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
