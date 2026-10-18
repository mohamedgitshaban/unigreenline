<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Manufacturing\Events\MrpPlanningCompleted;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Jobs\RunMrpPlanningJob;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\MrpRun;
use Modules\Manufacturing\Models\ProductionOrder;
use Throwable;

/**
 * Creates and runs planning runs. A run is recorded when it is asked for (what was asked, who asked)
 * and then executed — straight away or on the queue. Execution claims the run with one atomic update, so a
 * job that is delivered twice, or a retry, cannot plan the same run twice; a run that failed stays failed
 * (with a reason) and a new run is asked for instead.
 */
class MrpRunService
{
    public function __construct(
        private readonly MrpPlanner $planner,
        private readonly NumberSequenceService $numbers,
        private readonly ProductionOrderLifecycleService $lifecycle,
    ) {}

    /**
     * Records a run. Only one run per warehouse may be waiting or running at a time.
     *
     * @param  array{warehouse_id?: ?string, horizon_days?: ?int}  $inputs
     */
    public function create(string $tenantId, array $inputs, ?string $userId): MrpRun
    {
        return DB::transaction(function () use ($tenantId, $inputs, $userId) {
            // The settings row is the lock that serialises people asking for runs.
            $settings = ManufacturingSetting::query()->where('tenant_id', $tenantId)->lockForUpdate()->first() ?? ManufacturingSetting::forTenant($tenantId);
            $warehouseId = $inputs['warehouse_id'] ?? $settings->default_warehouse_id
                ?? throw new ManufacturingRuleException('Choose the warehouse to plan, or set a default one in the manufacturing settings.');

            $active = MrpRun::query()->where('tenant_id', $tenantId)->whereIn('status', ['queued', 'running'])
                ->where('inputs->warehouse_id', $warehouseId)->exists();

            if ($active) {
                throw new ManufacturingRuleException('A planning run for this warehouse is already waiting or running.');
            }

            return MrpRun::create([
                'tenant_id' => $tenantId,
                'number' => $this->numbers->next($tenantId, 'mrp', $settings->mrp_prefix, $settings->number_padding),
                'status' => 'queued',
                'inputs' => ['warehouse_id' => $warehouseId, 'horizon_days' => $inputs['horizon_days'] ?? $settings->mrp_horizon_days],
                'requested_by' => $userId,
            ]);
        });
    }

    /**
     * Hands the run to the queue.
     */
    public function dispatch(MrpRun $run): void
    {
        RunMrpPlanningJob::dispatch($run->id);
    }

    /**
     * Runs it now. Returns the run in whatever state it ended in; never throws for a planning failure.
     */
    public function execute(string $runId): MrpRun
    {
        $claimed = MrpRun::query()->whereKey($runId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]);
        $run = MrpRun::query()->findOrFail($runId);

        if ($claimed === 0) {
            return $run;   // already taken, finished or failed: nothing to do, nothing duplicated
        }

        try {
            $stats = DB::transaction(function () use ($run) {
                $stats = $this->planner->plan($run);
                $stats += $this->handleCancelledDemand($run);

                return $stats;
            });

            $run->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now()]);
            event(new MrpPlanningCompleted($run->tenant_id, null, ['run_id' => $run->id, 'number' => $run->number, 'stats' => $stats]));
        } catch (Throwable $e) {
            Log::error('MRP planning run failed', ['run_id' => $run->id, 'tenant_id' => $run->tenant_id, 'exception' => $e]);

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e instanceof ManufacturingRuleException ? $e->getMessage() : 'Planning failed unexpectedly. The details are in the application log (run '.$run->number.').',
            ]);
        }

        return $run->refresh();
    }

    /**
     * Orders raised for a sales order that has since been cancelled. If the company's policy allows it,
     * the ones not yet started are cancelled; otherwise (and for the ones already under way) they are only counted.
     *
     * @return array{cancelled_orders: int, orphaned_orders: int}
     */
    private function handleCancelledDemand(MrpRun $run): array
    {
        $settings = ManufacturingSetting::forTenant($run->tenant_id);
        $orphans = ProductionOrder::query()
            ->where('tenant_id', $run->tenant_id)->where('warehouse_id', $run->inputs['warehouse_id'])
            ->where('source_type', 'sales_order')
            ->whereIn('status', ['draft', 'confirmed', 'planned', 'in_progress', 'paused'])
            ->whereIn('source_id', DB::table('sales_orders')->where('tenant_id', $run->tenant_id)->where('status', 'cancelled')->select('id'))
            ->get();

        $cancelled = 0;

        foreach ($orphans as $order) {
            if (! $settings->cancel_unstarted_on_demand_cancel || ! in_array($order->status, ['draft', 'confirmed', 'planned'], true) || $order->hasMovedStock()) {
                continue;
            }

            try {
                $this->lifecycle->cancel($order, 'The sales order it was raised for was cancelled.', ['actor_id' => $run->requested_by]);
                $cancelled++;
            } catch (ManufacturingRuleException) {
                // Stays open; it is counted as orphaned below.
            }
        }

        return ['cancelled_orders' => $cancelled, 'orphaned_orders' => $orphans->count() - $cancelled];
    }
}
