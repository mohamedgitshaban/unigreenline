<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Events\WorkOrderCompleted;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Models\WorkOrder;
use Modules\Manufacturing\Models\WorkOrderTimeLog;

/**
 * The shop floor: an order's operations as work orders, scheduled on work centers, started,
 * paused, resumed and completed by the people doing them. An operation can only start once
 * the one it depends on is completed; completing it records what it processed and rejected,
 * costs the time it took and issues the materials that belong to it.
 */
class WorkOrderService
{
    public function __construct(
        private readonly WorkCenterScheduler $scheduler,
        private readonly NumberSequenceService $numbers,
        private readonly QualityService $quality,
        private readonly ManufacturingPostingService $posting,
        private readonly ProductionRecordingService $recording,
        private readonly OrderHistoryService $history,
    ) {}

    /**
     * Creates the order's work orders from its routing and books them on their work centers, each
     * after the one it depends on. Returns when the last one ends. An order that already has work
     * orders is re-scheduled instead; operations already started keep their place.
     */
    public function generateAndSchedule(ProductionOrder $order, Bom $bom): Carbon
    {
        $settings = ManufacturingSetting::forTenant($order->tenant_id);

        if (! $order->workOrders()->exists()) {
            $this->generate($order, $bom, $settings);
        }

        $order->load('workOrders.workCenter');
        $earliest = Carbon::parse($order->planned_start ?? now());
        $earliest = $earliest->lt(now()) ? now() : $earliest;
        $ends = [];
        $last = $earliest->copy();

        foreach ($order->workOrders->sortBy('sequence') as $workOrder) {
            if (! $workOrder->isOpen() || in_array($workOrder->status, ['in_progress', 'paused'], true)) {
                $ends[$workOrder->id] = $workOrder->scheduled_end ?? $earliest;
                $last = $ends[$workOrder->id]->gt($last) ? $ends[$workOrder->id]->copy() : $last;

                continue;
            }

            $center = $this->scheduler->lockedCenter($workOrder->work_center_id);
            $start = isset($ends[$workOrder->depends_on_id]) ? $ends[$workOrder->depends_on_id]->copy() : $earliest->copy();
            $start = $start->lt($earliest) ? $earliest->copy() : $start;

            [$from, $to] = $this->scheduler->find($center, $workOrder->planned_minutes, $start, $workOrder->id);
            $workOrder->update(['scheduled_start' => $from, 'scheduled_end' => $to]);

            $ends[$workOrder->id] = $to;
            $last = $to->gt($last) ? $to->copy() : $last;
        }

        $order->update(['planned_start' => $order->workOrders->min('scheduled_start') ?? $order->planned_start]);

        return $last;
    }

    /**
     * Makes the operations that can begin (nothing before them) ready.
     */
    public function releaseFirst(ProductionOrder $order): void
    {
        $order->workOrders()->where('status', 'pending')->whereNull('depends_on_id')->update(['status' => 'ready']);
    }

    public function pauseAll(ProductionOrder $order, ?string $userId = null): void
    {
        foreach ($order->workOrders()->where('status', 'in_progress')->lockForUpdate()->get() as $workOrder) {
            $this->closeLog($workOrder);
            $workOrder->update(['status' => 'paused', 'actual_minutes' => $this->loggedMinutes($workOrder)]);
        }
    }

    public function cancelAll(ProductionOrder $order): void
    {
        $order->workOrders()->whereIn('status', WorkOrder::OPEN)->update(['status' => 'cancelled']);
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function start(WorkOrder $workOrder, array $actor = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $actor) {
            $order = $this->lockOrder($workOrder);

            if (in_array($order->status, ['confirmed', 'planned'], true)) {
                app(ProductionOrderLifecycleService::class)->start($order, $actor);
                $order->refresh();
            }

            $this->assertOrderRunning($order, 'start an operation');
            $locked = $this->lockWorkOrder($workOrder);

            if ($locked->status !== 'ready') {
                throw new ManufacturingRuleException($locked->status === 'pending'
                    ? "Operation {$locked->name} cannot start yet: the operation before it is not completed."
                    : "Operation {$locked->name} is {$locked->status} and cannot be started.");
            }

            WorkOrderTimeLog::create(['work_order_id' => $locked->id, 'user_id' => $actor['actor_id'] ?? null, 'started_at' => now()]);
            $locked->update([
                'status' => 'in_progress',
                'actual_start' => $locked->actual_start ?? now(),
                'assigned_to' => $locked->assigned_to ?? ($actor['actor_id'] ?? null),
            ]);

            $this->history->record($order, 'start-work-order', $order->status, $order->status, $actor, ['work_order' => $locked->number]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function pause(WorkOrder $workOrder, array $actor = [], ?string $reason = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $actor, $reason) {
            $order = $this->lockOrder($workOrder);
            $locked = $this->lockWorkOrder($workOrder);

            if ($locked->status !== 'in_progress') {
                throw new ManufacturingRuleException("Operation {$locked->name} is {$locked->status}; only one in progress can be paused.");
            }

            $this->closeLog($locked);
            $locked->update(['status' => 'paused', 'actual_minutes' => $this->loggedMinutes($locked), 'notes' => $reason ? trim($locked->notes."\nPaused: {$reason}") : $locked->notes]);
            $this->history->record($order, 'pause-work-order', $order->status, $order->status, $actor, ['work_order' => $locked->number]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function resume(WorkOrder $workOrder, array $actor = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $actor) {
            $order = $this->lockOrder($workOrder);
            $this->assertOrderRunning($order, 'resume an operation');
            $locked = $this->lockWorkOrder($workOrder);

            if ($locked->status !== 'paused') {
                throw new ManufacturingRuleException("Operation {$locked->name} is {$locked->status}; only a paused one can be resumed.");
            }

            WorkOrderTimeLog::create(['work_order_id' => $locked->id, 'user_id' => $actor['actor_id'] ?? null, 'started_at' => now()]);
            $locked->update(['status' => 'in_progress']);
            $this->history->record($order, 'resume-work-order', $order->status, $order->status, $actor, ['work_order' => $locked->number]);

            return $locked->refresh();
        });
    }

    /**
     * Finishes the operation. Cartons processed and rejected default to everything that reached it
     * (and none rejected); they can never be more than the operation before it passed on.
     *
     * @param  array{qty_processed?: int, qty_rejected?: int, notes?: ?string, failure_reason?: ?string, reject_reason?: ?string}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function complete(WorkOrder $workOrder, array $input = [], array $actor = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $input, $actor) {
            $order = $this->lockOrder($workOrder);
            $this->assertOrderRunning($order, 'complete an operation');
            $locked = $this->lockWorkOrder($workOrder);

            if (! in_array($locked->status, ['in_progress', 'paused'], true)) {
                throw new ManufacturingRuleException("Operation {$locked->name} is {$locked->status}; start it before completing it.");
            }

            $blocking = $this->quality->blockingFor($locked);

            if ($blocking->isNotEmpty()) {
                throw new ManufacturingRuleException("Quality checks on operation {$locked->name} have not passed.", ['quality_checks' => $blocking->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status])->all()]);
            }

            $upstream = $locked->depends_on_id !== null
                ? (int) WorkOrder::query()->whereKey($locked->depends_on_id)->value('qty_processed')
                : $locked->qty_planned;

            $rejected = (int) ($input['qty_rejected'] ?? 0);
            $processed = (int) ($input['qty_processed'] ?? ($upstream - $rejected));

            if ($processed < 0 || $rejected < 0 || $processed + $rejected > $upstream) {
                throw new ManufacturingRuleException("Operation {$locked->name} can process at most {$upstream} carton(s) — what the operation before it passed on.");
            }

            $this->closeLog($locked);
            $minutes = $this->loggedMinutes($locked);
            $center = WorkCenter::query()->findOrFail($locked->work_center_id);
            $hours = $minutes / 60;
            $labor = round($hours * (float) $center->labor_cost_per_hour, 2);
            $machine = round($hours * (float) $center->cost_per_hour, 2);

            $locked->update([
                'status' => 'completed',
                'qty_processed' => $processed,
                'qty_rejected' => $rejected,
                'actual_minutes' => $minutes,
                'actual_end' => now(),
                'labor_cost' => $labor,
                'machine_cost' => $machine,
                'notes' => $input['notes'] ?? $locked->notes,
                'failure_reason' => $input['failure_reason'] ?? null,
            ]);

            $settings = ManufacturingSetting::forTenant($order->tenant_id);

            if ($rejected > 0) {
                $this->recording->recordWorkOrderRejects($order, $locked, $rejected, $input['reject_reason'] ?? 'Rejected in '.$locked->name, $actor);
            }

            // The materials that belong to this operation are issued with what it processed.
            $this->recording->issueForOutput($order, $settings, $processed + $rejected, $actor, $locked->id);

            $this->posting->postOperationCost($order, round($labor + $machine, 2), $locked->name, $actor['actor_id'] ?? null);
            $order->update(['actual_operation_cost' => (float) WorkOrder::query()->where('production_order_id', $order->id)->where('status', 'completed')->sum(DB::raw('labor_cost + machine_cost'))]);

            WorkOrder::query()->where('depends_on_id', $locked->id)->where('status', 'pending')->update(['status' => 'ready']);

            $this->history->record($order, 'complete-work-order', $order->status, $order->status, $actor, [
                'work_order' => $locked->number, 'processed' => (string) $processed, 'rejected' => (string) $rejected, 'minutes' => (string) $minutes,
            ]);
            event(new WorkOrderCompleted($order->tenant_id, $order->id, ['work_order_id' => $locked->id, 'number' => $locked->number]));

            return $locked->refresh();
        });
    }

    /**
     * Moves an operation to a new start. With `auto_shift` it takes the first free slot at or after
     * the requested time; without, the requested time must be free or the move is refused.
     *
     * @param  array{scheduled_start: string, auto_shift?: bool}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function reschedule(WorkOrder $workOrder, array $input, array $actor = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $input, $actor) {
            $order = $this->lockOrder($workOrder);
            $locked = $this->lockWorkOrder($workOrder);

            if (! in_array($locked->status, ['pending', 'ready'], true)) {
                throw new ManufacturingRuleException("Operation {$locked->name} is {$locked->status} and can no longer be rescheduled.");
            }

            $center = $this->scheduler->lockedCenter($locked->work_center_id);
            $requested = Carbon::parse($input['scheduled_start']);

            if ($locked->depends_on_id !== null) {
                $predecessorEnd = WorkOrder::query()->whereKey($locked->depends_on_id)->value('scheduled_end');

                if ($predecessorEnd !== null && $requested->lt(Carbon::parse($predecessorEnd))) {
                    throw new ManufacturingRuleException('An operation cannot start before the one it depends on has finished ('.Carbon::parse($predecessorEnd)->toDateTimeString().').');
                }
            }

            [$from, $to] = $this->scheduler->find($center, $locked->planned_minutes, $requested, $locked->id);

            if (! ($input['auto_shift'] ?? false) && ! $from->equalTo($requested)) {
                $conflicts = $this->scheduler->conflicts($center, $requested, $requested->copy()->addMinutes(max(1, $locked->planned_minutes)), $locked->id);

                throw new ManufacturingRuleException(
                    "Work center {$center->name} cannot take this operation at {$requested->toDateTimeString()}: ".($conflicts->isNotEmpty() ? 'it is fully booked then.' : 'it is not working then.').' The first free slot starts '.$from->toDateTimeString().'.',
                    ['next_free_start' => $from->toDateTimeString(), 'conflicts' => $conflicts->map(fn ($w) => ['work_order_id' => $w->id, 'number' => $w->number, 'from' => $w->scheduled_start->toDateTimeString(), 'to' => $w->scheduled_end->toDateTimeString()])->values()->all()],
                );
            }

            $locked->update(['scheduled_start' => $from, 'scheduled_end' => $to]);
            $this->history->record($order, 'reschedule-work-order', $order->status, $order->status, $actor, ['work_order' => $locked->number, 'start' => $from->toDateTimeString()]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function assign(WorkOrder $workOrder, ?string $userId, array $actor = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $userId, $actor) {
            $order = $this->lockOrder($workOrder);
            $locked = $this->lockWorkOrder($workOrder);

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new ManufacturingRuleException("Operation {$locked->name} is {$locked->status}; its operator can no longer change.");
            }

            $locked->update(['assigned_to' => $userId]);
            $this->history->record($order, 'assign-work-order', $order->status, $order->status, $actor, ['work_order' => $locked->number, 'operator' => (string) $userId]);

            return $locked->refresh();
        });
    }

    /**
     * How far along an order is, from 0 to 100: the share of its planned operation time that is done,
     * or the share of its planned output made when it has no operations.
     */
    public function progress(ProductionOrder $order): float
    {
        $workOrders = $order->relationLoaded('workOrders') ? $order->workOrders : $order->workOrders()->get();
        $workOrders = $workOrders->where('status', '!=', 'cancelled');

        if ($workOrders->isEmpty()) {
            return $order->output_qty_cartons > 0 ? round(min(100, ($order->produced_qty_cartons + $order->rejected_qty_cartons) / $order->output_qty_cartons * 100), 1) : 0.0;
        }

        $total = max(1, $workOrders->sum('planned_minutes'));

        return round($workOrders->where('status', 'completed')->sum('planned_minutes') / $total * 100, 1);
    }

    private function generate(ProductionOrder $order, Bom $bom, ManufacturingSetting $settings): void
    {
        $snapshot = $order->bomRevision?->snapshot['operations'] ?? null;
        $operations = collect($snapshot ?? $bom->routing()->first()->operations->map(fn ($o) => [
            'id' => $o->id, 'sequence' => $o->sequence, 'name' => $o->name, 'work_center_id' => $o->work_center_id,
            'setup_minutes' => $o->setup_minutes, 'run_minutes' => (string) $o->run_minutes, 'cleanup_minutes' => $o->cleanup_minutes,
            'depends_on_id' => $o->depends_on_id,
        ])->all())->sortBy('sequence')->values();

        $lineOperations = collect($order->bomRevision?->snapshot['lines'] ?? [])->pluck('operation_id', 'component_product_id');
        $byOperation = [];
        $previous = null;

        foreach ($operations as $operation) {
            $center = WorkCenter::query()->where('tenant_id', $order->tenant_id)->findOrFail($operation['work_center_id']);
            $minutes = (int) ceil($operation['setup_minutes'] + (float) $operation['run_minutes'] * $order->output_qty_cartons * (100 / max(1, (float) $center->efficiency_pct)) + $operation['cleanup_minutes']);
            // An operation with no times of its own takes the company's default duration.
            $minutes = $minutes > 0 ? $minutes : $settings->default_operation_minutes;
            $explicit = $operation['depends_on_id'] ?? null;
            $dependsOn = $explicit !== null ? ($byOperation[$explicit] ?? null) : $previous;

            $workOrder = WorkOrder::create([
                'tenant_id' => $order->tenant_id,
                'production_order_id' => $order->id,
                'routing_operation_id' => $operation['id'],
                'number' => $this->numbers->next($order->tenant_id, 'wo', $settings->work_order_prefix, $settings->number_padding),
                'sequence' => $operation['sequence'],
                'name' => $operation['name'],
                'work_center_id' => $center->id,
                'depends_on_id' => $dependsOn,
                'status' => 'pending',
                'qty_planned' => $order->output_qty_cartons,
                'planned_minutes' => $minutes,
                'assigned_to' => $order->assigned_to,
            ]);

            $byOperation[$operation['id']] = $workOrder->id;
            $previous = $workOrder->id;

            foreach ($order->components as $component) {
                if (($lineOperations[$component->product_id] ?? null) === $operation['id']) {
                    $component->update(['work_order_id' => $workOrder->id]);
                }
            }

            $this->quality->generateForWorkOrder($workOrder->load('productionOrder.bom'));
        }
    }

    private function lockOrder(WorkOrder $workOrder): ProductionOrder
    {
        return ProductionOrder::query()->whereKey($workOrder->production_order_id)->lockForUpdate()->firstOrFail();
    }

    private function lockWorkOrder(WorkOrder $workOrder): WorkOrder
    {
        return WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
    }

    private function assertOrderRunning(ProductionOrder $order, string $what): void
    {
        if ($order->status !== 'in_progress') {
            throw new ManufacturingRuleException("A manufacturing order that is {$order->status} cannot {$what}.");
        }
    }

    private function closeLog(WorkOrder $workOrder): void
    {
        $log = WorkOrderTimeLog::query()->where('work_order_id', $workOrder->id)->whereNull('ended_at')->lockForUpdate()->first();

        if ($log !== null) {
            $log->update(['ended_at' => now(), 'minutes' => (int) round($log->started_at->diffInSeconds(now(), true) / 60)]);
        }
    }

    private function loggedMinutes(WorkOrder $workOrder): int
    {
        return (int) WorkOrderTimeLog::query()->where('work_order_id', $workOrder->id)->sum('minutes');
    }
}
