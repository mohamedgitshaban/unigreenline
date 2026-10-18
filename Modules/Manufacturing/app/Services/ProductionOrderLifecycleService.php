<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Events\MaterialShortageDetected;
use Modules\Manufacturing\Events\MaterialsReserved;
use Modules\Manufacturing\Events\ProductionOrderCancelled;
use Modules\Manufacturing\Events\ProductionOrderCompleted;
use Modules\Manufacturing\Events\ProductionOrderConfirmed;
use Modules\Manufacturing\Events\ProductionStarted;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\WorkOrder;
use Modules\Manufacturing\Support\OrderStateMachine;

/**
 * Moves a manufacturing order through its life. Every method locks the order,
 * asks the state machine whether the move is allowed, does the work that goes
 * with it (components, reservations, work orders, stock, ledger) and writes the
 * history — all in one transaction.
 */
class ProductionOrderLifecycleService
{
    public function __construct(
        private readonly OrderComponentService $componentService,
        private readonly MaterialReservationService $reservations,
        private readonly ProductionCostingService $costing,
        private readonly ProductionRecordingService $recording,
        private readonly ManufacturingPostingService $posting,
        private readonly BomRevisionService $revisions,
        private readonly OrderHistoryService $history,
        private readonly NumberSequenceService $numbers,
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * Draft → confirmed: fixes the components and the cost estimate, and reserves the materials
     * (unless the company reserves by hand).
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function confirm(ProductionOrder $order, array $actor = [], bool $reserve = true): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor, $reserve) {
            $locked = $this->lock($order);
            $settings = ManufacturingSetting::forTenant($locked->tenant_id);
            OrderStateMachine::assert($locked->status, 'confirmed', $settings->allowed_transitions);

            $bom = Bom::query()->findOrFail($locked->bom_id);

            if (! $bom->isUsableOn()) {
                throw new ManufacturingRuleException("BoM {$bom->code} is not approved and active, so no order can be confirmed from it.");
            }

            $revision = $this->revisions->snapshot($bom, null, $actor['actor_id'] ?? null);
            $plannedStart = $locked->planned_start ?? now();

            $locked->forceFill([
                'number' => $locked->number ?? $this->numbers->next($locked->tenant_id, 'mo', $settings->mo_prefix, $settings->number_padding),
                'bom_revision_id' => $revision->id,
                'wip_accounting' => $settings->wip_accounting,
                'planned_start' => $plannedStart,
                'planned_finish' => $locked->planned_finish ?? Carbon::parse($plannedStart)->addDays($settings->lead_time_days),
            ])->save();

            $this->componentService->snapshot($locked, $bom);
            $locked->forceFill($this->costing->estimate($locked->refresh(), $bom, $settings))->save();
            $locked->update(['status' => 'confirmed']);
            app(QualityService::class)->generateForOrder($locked);

            $this->history->record($locked, 'confirm', 'draft', 'confirmed', $actor, ['bom' => (string) $bom->code, 'bom_version' => (string) $revision->revision_no]);
            event(new ProductionOrderConfirmed($locked->tenant_id, $locked->id));

            if ($reserve && $settings->reservation_policy === 'on_confirm') {
                $this->reserveMaterials($locked, $actor);
            }

            return $locked->refresh();
        });
    }

    /**
     * Sets the materials aside; reports what could not be.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{order: ProductionOrder, shortages: array<int, array<string, mixed>>}
     */
    public function reserveMaterials(ProductionOrder $order, array $actor = []): array
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);

            if (! in_array($locked->status, ['confirmed', 'planned', 'in_progress', 'paused'], true)) {
                throw new ManufacturingRuleException("Materials can only be reserved for a confirmed order, not a {$locked->status} one.");
            }

            $shortages = $this->reservations->reserve($locked, $actor['actor_id'] ?? null);
            $this->history->record($locked, 'reserve-materials', $locked->status, $locked->status, $actor, ['status' => $locked->refresh()->reservation_status, 'short_lines' => (string) count($shortages)]);

            event($shortages === []
                ? new MaterialsReserved($locked->tenant_id, $locked->id)
                : new MaterialShortageDetected($locked->tenant_id, $locked->id, ['shortages' => $shortages]));

            return ['order' => $locked, 'shortages' => $shortages];
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function releaseMaterials(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);
            $released = $this->reservations->release($locked);
            $this->history->record($locked, 'release-materials', $locked->status, $locked->status, $actor, ['released_cartons' => (string) $released]);

            return $locked->refresh();
        });
    }

    /**
     * Confirmed → planned: gives the order its operations and a schedule.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function plan(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);
            $settings = ManufacturingSetting::forTenant($locked->tenant_id);
            OrderStateMachine::assert($locked->status, 'planned', $settings->allowed_transitions);

            $this->schedule($locked, $settings);
            $locked->update(['status' => 'planned']);

            $this->history->record($locked, 'plan', 'confirmed', 'planned', $actor, [
                'start' => $locked->planned_start?->toDateTimeString() ?? '', 'finish' => $locked->planned_finish?->toDateTimeString() ?? '',
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Confirmed or planned → in progress.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function start(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);
            $settings = ManufacturingSetting::forTenant($locked->tenant_id);
            $from = $locked->status;

            if (! in_array($from, ['confirmed', 'planned'], true)) {
                throw new ManufacturingRuleException($from === 'paused'
                    ? 'This order is paused; resume it instead of starting it.'
                    : "A manufacturing order that is {$from} cannot be started.");
            }

            OrderStateMachine::assert($from, 'in_progress', $settings->allowed_transitions);

            if ($from === 'confirmed') {
                $this->schedule($locked, $settings);
            }

            $locked->update(['status' => 'in_progress', 'actual_start' => $locked->actual_start ?? now()]);

            if ($locked->workOrders()->exists()) {
                $this->workOrders()->releaseFirst($locked);
            }

            $this->history->record($locked, 'start', $from, 'in_progress', $actor);
            event(new ProductionStarted($locked->tenant_id, $locked->id));

            return $locked->refresh();
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function pause(ProductionOrder $order, array $actor = [], ?string $reason = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $locked = $this->lock($order);
            OrderStateMachine::assert($locked->status, 'paused', ManufacturingSetting::forTenant($locked->tenant_id)->allowed_transitions);

            $this->workOrders()->pauseAll($locked, $actor['actor_id'] ?? null);
            $locked->update(['status' => 'paused']);
            $this->history->record($locked, 'pause', 'in_progress', 'paused', $actor, $reason === null ? null : ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function resume(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);

            if ($locked->status !== 'paused') {
                throw new ManufacturingRuleException("A manufacturing order that is {$locked->status} cannot be resumed; only a paused one can.");
            }

            OrderStateMachine::assert('paused', 'in_progress', ManufacturingSetting::forTenant($locked->tenant_id)->allowed_transitions);

            $locked->update(['status' => 'in_progress']);
            $this->history->record($locked, 'resume', 'paused', 'in_progress', $actor);

            return $locked->refresh();
        });
    }

    /**
     * Cancels the order and gives its reserved materials back. An order that has already
     * moved stock or run an operation cannot be cancelled: its output is dealt with by
     * completing it short (and scrapping what is lost) so that stock and ledger stay true.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function cancel(ProductionOrder $order, ?string $reason = null, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $reason, $actor) {
            $locked = $this->lock($order);
            $from = $locked->status;
            OrderStateMachine::assert($from, 'cancelled', ManufacturingSetting::forTenant($locked->tenant_id)->allowed_transitions);

            if ($locked->hasMovedStock() || $locked->workOrders()->whereIn('status', ['in_progress', 'paused', 'completed'])->exists()) {
                throw new ManufacturingRuleException('This order has already issued materials, produced output or run an operation, so it cannot be cancelled. Complete it with what was made and record the rest as scrap.');
            }

            $this->reservations->release($locked);
            $this->workOrders()->cancelAll($locked);
            $locked->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'reservation_status' => 'none']);

            $this->history->record($locked, 'cancel', $from, 'cancelled', $actor, $reason === null ? null : ['reason' => $reason]);
            event(new ProductionOrderCancelled($locked->tenant_id, $locked->id, ['reason' => $reason]));

            return $locked->refresh();
        });
    }

    /**
     * Cancelled → draft, only if the company allows it. Nothing happened while it was cancelled, so
     * it starts again from scratch (its history stays).
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function reopen(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);
            $settings = ManufacturingSetting::forTenant($locked->tenant_id);

            if (! $settings->allow_reopen_cancelled) {
                throw new ManufacturingRuleException('Reopening cancelled orders is switched off in the manufacturing settings.');
            }

            OrderStateMachine::assert($locked->status, 'draft', $settings->allowed_transitions);

            $locked->components()->delete();
            $locked->workOrders()->delete();
            $locked->update(['status' => 'draft', 'cancellation_reason' => null, 'reservation_status' => 'none', 'bom_revision_id' => null]);

            $this->history->record($locked, 'reopen', 'cancelled', 'draft', $actor);

            return $locked->refresh();
        });
    }

    /**
     * Closes the order. Whatever is still to be made is made now (unless `produce_remaining` is false),
     * the work orders and quality checks must be done, what the finished goods did not carry is written
     * off to variance, reservations are released, and a backorder is raised for any shortfall the
     * company wants to keep making.
     *
     * @param  array{produce_remaining?: bool, create_backorder?: ?bool, batch_no?: ?string, exp_date?: ?string, mfg_date?: ?string, idempotency_key?: ?string}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @return array{order: ProductionOrder, backorder: ?ProductionOrder, replayed: bool}
     */
    public function complete(ProductionOrder $order, array $input = [], array $actor = []): array
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $locked = $this->lock($order);
            $settings = ManufacturingSetting::forTenant($locked->tenant_id);
            $backorderId = null;

            $outcome = $this->idempotency->once($locked, 'complete', $input['idempotency_key'] ?? null, function () use ($locked, $input, $actor, $settings, &$backorderId) {
                OrderStateMachine::assert($locked->status, 'completed', $settings->allowed_transitions);

                if ($locked->status === 'draft') {
                    $this->confirm($locked, $actor, reserve: false);
                    $locked->refresh();
                }

                if (in_array($locked->status, ['confirmed', 'planned'], true)) {
                    $this->start($locked, $actor);
                    $locked->refresh();
                }

                $from = $locked->status;

                $open = $locked->workOrders()->whereIn('status', WorkOrder::OPEN)->count();

                if ($open > 0) {
                    throw new ManufacturingRuleException("Complete the work orders first: {$open} still open.");
                }

                if (($input['produce_remaining'] ?? true) && $locked->remainingQty() > 0) {
                    $this->recording->produce($locked, [
                        'qty' => $locked->remainingQty(), 'batch_no' => $input['batch_no'] ?? null,
                        'exp_date' => $input['exp_date'] ?? null, 'mfg_date' => $input['mfg_date'] ?? null,
                    ], $actor);
                    $locked->refresh();
                }

                $this->assertCanComplete($locked);

                $remaining = $locked->remainingQty();
                $wantBackorder = false;

                if ($remaining > 0) {
                    $wantBackorder = match ($settings->backorder_policy) {
                        'always' => true,
                        'never' => false,
                        default => $input['create_backorder'] ?? throw new ManufacturingRuleException(
                            "{$remaining} carton(s) were not made. Say whether to keep making them: send create_backorder true (raise a backorder) or false (close the order short).",
                            ['remaining_qty_cartons' => $remaining],
                        ),
                    };
                }

                $settlement = $this->costing->settlement($locked);
                $variance = $this->posting->postVariance($locked, $settlement['amount'], $actor['actor_id'] ?? null);
                $this->reservations->release($locked);

                $locked->update([
                    'status' => 'completed',
                    'variance_cost' => $settlement['amount'],
                    'variance_journal_entry_id' => $variance?->id,
                    'reservation_status' => 'none',
                    'actual_finish' => now(),
                    'completed_by' => $actor['actor_id'] ?? null,
                    'completed_at' => now(),
                ]);

                $this->history->record($locked, 'complete', $from, 'completed', $actor, [
                    'produced' => (string) $locked->produced_qty_cartons, 'rejected' => (string) $locked->rejected_qty_cartons,
                    'remaining' => (string) $remaining, 'variance' => number_format($settlement['amount'], 2, '.', ''),
                ], 'APPROVE');

                if ($wantBackorder) {
                    $backorderId = $this->createBackorder($locked, $actor)->id;
                }

                event(new ProductionOrderCompleted($locked->tenant_id, $locked->id));

                return ['backorder_id' => $backorderId];
            });

            $backorderId = $outcome['result']['backorder_id'] ?? null;

            return [
                'order' => $locked->refresh(),
                'backorder' => $backorderId ? ProductionOrder::query()->find($backorderId) : null,
                'replayed' => $outcome['replayed'],
            ];
        });
    }

    /**
     * Raises a new draft order for what a completed order did not make.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function createBackorder(ProductionOrder $order, array $actor = []): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = $this->lock($order);

            if ($locked->status !== 'completed') {
                throw new ManufacturingRuleException('A backorder can only be raised from a completed order.');
            }

            if ($locked->remainingQty() <= 0) {
                throw new ManufacturingRuleException('Everything planned on this order was made, so there is nothing to backorder.');
            }

            if ($locked->backorders()->exists()) {
                throw new ManufacturingRuleException('This order already has a backorder.');
            }

            $settings = ManufacturingSetting::forTenant($locked->tenant_id);
            $remaining = $locked->remainingQty();
            $bom = Bom::query()->findOrFail($locked->bom_id);
            $share = $locked->output_qty_cartons > 0 ? $remaining / $locked->output_qty_cartons : 1.0;

            $backorder = ProductionOrder::create([
                'tenant_id' => $locked->tenant_id,
                'number' => $this->numbers->next($locked->tenant_id, 'mo', $settings->mo_prefix, $settings->number_padding),
                'bom_id' => $locked->bom_id,
                'warehouse_id' => $locked->warehouse_id,
                'parent_order_id' => $locked->id,
                'source_type' => 'backorder',
                'source_id' => $locked->id,
                'status' => 'draft',
                'priority' => $locked->priority,
                'assigned_to' => $locked->assigned_to,
                'runs' => max(1, (int) ceil($remaining / max(1, $bom->output_qty_cartons))),
                'output_qty_cartons' => $remaining,
                'production_date' => now()->toDateString(),
                'batch_no' => 'MO-'.now()->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)),
                'exp_date' => $locked->exp_date,
                'conversion_cost' => round((float) $locked->conversion_cost * $share, 2),
                'notes' => $locked->notes,
                'created_by' => $actor['actor_id'] ?? $locked->created_by,
            ]);

            $this->history->record($locked, 'create-backorder', 'completed', 'completed', $actor, ['backorder' => (string) $backorder->number, 'qty_cartons' => (string) $remaining]);
            $this->history->record($backorder, 'create', null, 'draft', $actor, ['backorder_of' => (string) $locked->number], 'INSERT');

            return $backorder;
        });
    }

    /**
     * Gives the order its work orders (when its recipe has a routing) and its planned dates.
     */
    private function schedule(ProductionOrder $order, ManufacturingSetting $settings): void
    {
        $bom = Bom::query()->findOrFail($order->bom_id);

        if ($bom->routing_id !== null && $settings->auto_create_work_orders) {
            $finish = $this->workOrders()->generateAndSchedule($order, $bom);
            $order->update(['planned_finish' => $finish]);
            $order->forceFill($this->costing->estimate($order->refresh(), $bom, $settings))->save();

            return;
        }

        $start = $order->planned_start ?? now();
        $order->update(['planned_start' => $start, 'planned_finish' => Carbon::parse($start)->addDays($settings->lead_time_days)]);
    }

    /**
     * Work orders and quality checks must be settled before an order can close.
     */
    private function assertCanComplete(ProductionOrder $order): void
    {
        if ($order->produced_qty_cartons + $order->rejected_qty_cartons <= 0) {
            throw new ManufacturingRuleException('Nothing has been produced on this order. Cancel it instead.');
        }

        $open = $order->workOrders()->whereIn('status', WorkOrder::OPEN)->count();

        if ($open > 0) {
            throw new ManufacturingRuleException("Complete the work orders first: {$open} still open.");
        }

        $blocking = app(QualityService::class)->blockingForOrder($order);

        if ($blocking->isNotEmpty()) {
            throw new ManufacturingRuleException('Mandatory quality checks have not passed.', ['quality_checks' => $blocking->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status])->all()]);
        }
    }

    private function lock(ProductionOrder $order): ProductionOrder
    {
        return ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function workOrders(): WorkOrderService
    {
        return app(WorkOrderService::class);
    }
}
