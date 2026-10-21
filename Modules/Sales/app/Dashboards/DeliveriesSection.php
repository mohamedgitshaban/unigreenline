<?php

namespace Modules\Sales\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\Sales\Models\Delivery;

/**
 * Deliveries: what is waiting to go out and what was handed over.
 */
class DeliveriesSection implements DashboardSection
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'deliveries';
    }

    public function label(): string
    {
        return 'Deliveries';
    }

    public function permission(): ?string
    {
        return 'deliveries.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $deliveries = fn () => $this->scope->constrainThrough(Delivery::query()->where('deliveries.tenant_id', $user->tenant_id), $user, 'salesOrder', 'sales_rep_id');
        $previous = $period->previous();
        $deliveredIn = fn (DashboardPeriod $in) => $deliveries()->where('status', 'delivered')->whereBetween('delivered_at', [$in->from, $in->to])->count();

        $pending = $deliveries()->whereIn('status', ['pending', 'preparing', 'out_for_delivery']);
        $byStatus = $deliveries()->selectRaw('status, count(*) as total')->whereIn('status', ['pending', 'preparing', 'out_for_delivery', 'failed'])->groupBy('status')->pluck('total', 'status');

        return [
            'kpis' => [
                Widgets::kpi('pending_deliveries', 'Waiting to be delivered', (clone $pending)->count()),
                Widgets::kpi('delivered', 'Delivered', $deliveredIn($period), $deliveredIn($previous)),
                Widgets::kpi('failed_deliveries', 'Failed deliveries', $deliveries()->where('status', 'failed')->count()),
            ],
            'charts' => [
                Widgets::chart('deliveries_by_status', 'Open deliveries by status', 'donut', $byStatus->map(fn ($total, $status) => ['label' => $status, 'value' => (int) $total])->values()->all()),
            ],
        ];
    }
}
