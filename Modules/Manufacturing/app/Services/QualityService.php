<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Events\QualityCheckFailed;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\QualityAlert;
use Modules\Manufacturing\Models\QualityCheck;
use Modules\Manufacturing\Models\QualityCheckDefinition;
use Modules\Manufacturing\Models\WorkOrder;

/**
 * Quality control around production. A *definition* says what to check, where in the
 * process and what passes; a *check* is one inspection of one thing. Checks are raised
 * from the definitions when an order is confirmed (final and order-level in-process
 * checks) and when its work orders are created (checks tied to an operation); incoming
 * material inspections are raised by hand. A failed check opens an alert, and a
 * mandatory check that is pending or failed stops the order from completing.
 */
class QualityService
{
    /**
     * Raises the order-level checks (not tied to an operation) that apply to the finished product.
     */
    public function generateForOrder(ProductionOrder $order): void
    {
        $productId = $order->bom->product_id;

        QualityCheckDefinition::query()
            ->where('tenant_id', $order->tenant_id)->where('active', true)
            ->whereIn('point', ['in_process', 'final'])
            ->whereNull('routing_operation_id')
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $productId))
            ->get()
            ->each(fn (QualityCheckDefinition $definition) => $this->raise($definition, $order, null, $productId));
    }

    /**
     * Raises the checks tied to the operation a work order runs.
     */
    public function generateForWorkOrder(WorkOrder $workOrder): void
    {
        if ($workOrder->routing_operation_id === null) {
            return;
        }

        $order = $workOrder->productionOrder;

        QualityCheckDefinition::query()
            ->where('tenant_id', $workOrder->tenant_id)->where('active', true)
            ->where('routing_operation_id', $workOrder->routing_operation_id)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $order->bom->product_id))
            ->get()
            ->each(fn (QualityCheckDefinition $definition) => $this->raise($definition, $order, $workOrder, $order->bom->product_id));
    }

    /**
     * An inspection raised by hand, e.g. of incoming material before it is used.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): QualityCheck
    {
        $definition = isset($data['definition_id'])
            ? QualityCheckDefinition::query()->where('tenant_id', $tenantId)->findOrFail($data['definition_id'])
            : null;

        return QualityCheck::create([
            'tenant_id' => $tenantId,
            'definition_id' => $definition?->id,
            'name' => $data['name'] ?? $definition?->name ?? throw new ManufacturingRuleException('Give the check a name or base it on a definition.'),
            'point' => $data['point'] ?? $definition?->point ?? 'incoming',
            'type' => $data['type'] ?? $definition?->type ?? 'pass_fail',
            'min_value' => $data['min_value'] ?? $definition?->min_value,
            'max_value' => $data['max_value'] ?? $definition?->max_value,
            'mandatory' => $data['mandatory'] ?? $definition?->mandatory ?? true,
            'production_order_id' => $data['production_order_id'] ?? null,
            'work_order_id' => $data['work_order_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
        ]);
    }

    /**
     * Records the result of an inspection. A measured check passes when the value is inside its
     * limits; a pass/fail one takes the inspector's word. A failure opens an alert.
     *
     * @param  array{result?: string, measured_value?: float|string|null, defect_class?: ?string, rejected_cartons?: int, notes?: ?string, severity?: string, corrective_action?: ?string}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function record(QualityCheck $check, array $input, array $actor = []): QualityCheck
    {
        return DB::transaction(function () use ($check, $input, $actor) {
            $locked = QualityCheck::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new ManufacturingRuleException('This check already has a result. Raise a new check to inspect again.');
            }

            $passed = $locked->type === 'measure'
                ? $this->withinLimits($locked, $input['measured_value'] ?? throw new ManufacturingRuleException('Enter the measured value.'))
                : (($input['result'] ?? null) === 'passed' ? true : (($input['result'] ?? null) === 'failed' ? false : throw new ManufacturingRuleException('Say whether the check passed or failed.')));

            $locked->update([
                'status' => $passed ? 'passed' : 'failed',
                'measured_value' => $input['measured_value'] ?? null,
                'defect_class' => $passed ? null : ($input['defect_class'] ?? null),
                'rejected_cartons' => $passed ? 0 : (int) ($input['rejected_cartons'] ?? 0),
                'notes' => $input['notes'] ?? null,
                'checked_by' => $actor['actor_id'] ?? null,
                'checked_at' => now(),
            ]);

            if (! $passed) {
                QualityAlert::create([
                    'tenant_id' => $locked->tenant_id,
                    'quality_check_id' => $locked->id,
                    'production_order_id' => $locked->production_order_id,
                    'product_id' => $locked->product_id,
                    'severity' => $input['severity'] ?? 'medium',
                    'description' => "{$locked->name} failed".($locked->defect_class ? " ({$locked->defect_class})" : '').'.',
                    'corrective_action' => $input['corrective_action'] ?? null,
                    'created_by' => $actor['actor_id'] ?? null,
                ]);

                event(new QualityCheckFailed($locked->tenant_id, $locked->production_order_id, ['quality_check_id' => $locked->id, 'name' => $locked->name]));
            }

            return $locked->refresh();
        });
    }

    /**
     * Closes an alert once the cause has been dealt with.
     *
     * @param  array{corrective_action?: ?string}  $input
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function resolveAlert(QualityAlert $alert, array $input, array $actor = []): QualityAlert
    {
        return DB::transaction(function () use ($alert, $input, $actor) {
            $locked = QualityAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'resolved') {
                throw new ManufacturingRuleException('This alert is already resolved.');
            }

            $locked->update([
                'status' => 'resolved',
                'corrective_action' => $input['corrective_action'] ?? $locked->corrective_action,
                'resolved_by' => $actor['actor_id'] ?? null,
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Mandatory checks of a work order whose latest inspection has not passed.
     *
     * @return \Illuminate\Support\Collection<int, QualityCheck>
     */
    public function blockingFor(WorkOrder $workOrder)
    {
        return $this->notPassed(QualityCheck::query()->where('work_order_id', $workOrder->id));
    }

    /**
     * Mandatory checks of an order (its own and its operations') whose latest inspection has not passed.
     * A failed check is superseded by a later check of the same kind that passes.
     *
     * @return \Illuminate\Support\Collection<int, QualityCheck>
     */
    public function blockingForOrder(ProductionOrder $order)
    {
        return $this->notPassed(QualityCheck::query()->where('production_order_id', $order->id));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<QualityCheck>  $query
     * @return \Illuminate\Support\Collection<int, QualityCheck>
     */
    private function notPassed($query)
    {
        return $query->where('mandatory', true)->orderBy('id')->get()
            ->groupBy(fn (QualityCheck $c) => ($c->definition_id ?? $c->name).'|'.($c->work_order_id ?? ''))
            ->map(fn ($group) => $group->last())
            ->filter(fn (QualityCheck $c) => $c->status !== 'passed')
            ->values();
    }

    private function raise(QualityCheckDefinition $definition, ProductionOrder $order, ?WorkOrder $workOrder, string $productId): QualityCheck
    {
        return QualityCheck::query()->firstOrCreate(
            ['definition_id' => $definition->id, 'production_order_id' => $order->id, 'work_order_id' => $workOrder?->id],
            [
                'tenant_id' => $order->tenant_id,
                'name' => $definition->name,
                'point' => $definition->point,
                'type' => $definition->type,
                'min_value' => $definition->min_value,
                'max_value' => $definition->max_value,
                'mandatory' => $definition->mandatory,
                'product_id' => $productId,
            ],
        );
    }

    private function withinLimits(QualityCheck $check, float|string $value): bool
    {
        $value = (float) $value;

        return ($check->min_value === null || $value >= (float) $check->min_value)
            && ($check->max_value === null || $value <= (float) $check->max_value);
    }
}
