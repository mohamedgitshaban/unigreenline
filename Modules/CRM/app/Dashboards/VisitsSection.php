<?php

namespace Modules\CRM\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\CustomerVisit;

/**
 * Field visits: how many, how they went, who is visiting most, and what is planned next.
 */
class VisitsSection implements DashboardSection
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'visits';
    }

    public function label(): string
    {
        return 'Visits';
    }

    public function permission(): ?string
    {
        return 'visits.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $visits = fn (DashboardPeriod $in) => CustomerVisit::query()->where('customer_visits.tenant_id', $user->tenant_id)->visibleTo($user)
            ->whereBetween('customer_visits.visit_date', [$in->from->toDateString(), $in->to->toDateString()]);

        $count = (clone $visits($period))->count();
        $positive = (clone $visits($period))->where('outcome', 'positive')->count();

        $byDay = $visits($period)->selectRaw('visit_date as day, count(*) as total')->groupBy('visit_date')->pluck('total', 'day');
        $byOutcome = $visits($period)->selectRaw("coalesce(outcome, 'not recorded') as outcome, count(*) as total")->groupBy('outcome')->pluck('total', 'outcome');

        $byRep = $visits($period)->join('users', 'users.id', '=', 'customer_visits.rep_id')
            ->selectRaw('users.id, users.name, count(*) as visits')->groupBy('users.id', 'users.name')->orderByDesc('visits')->limit(10)->get();

        $planned = CustomerVisit::query()->where('customer_visits.tenant_id', $user->tenant_id)->visibleTo($user)
            ->whereNotNull('next_visit')->whereBetween('next_visit', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->join('customers', 'customers.id', '=', 'customer_visits.customer_id')->orderBy('next_visit')->limit(5)
            ->get(['customer_visits.id', 'customers.name as customer', 'customer_visits.next_visit']);

        $tables = [Widgets::table('planned_visits', 'Visits planned this week', [['key' => 'customer', 'label' => 'Customer'], ['key' => 'next_visit', 'label' => 'Date']],
            $planned->map(fn ($row) => ['id' => $row->id, 'customer' => $row->customer, 'next_visit' => $row->next_visit->toDateString()])->all())];

        if ($byRep->count() > 1 || ! $this->scope->isTeamScoped($user)) {
            $tables[] = Widgets::table('visits_by_rep', 'Visits per rep', [['key' => 'name', 'label' => 'Rep'], ['key' => 'visits', 'label' => 'Visits']],
                $byRep->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'visits' => (int) $row->visits])->all());
        }

        return [
            'kpis' => [
                Widgets::kpi('visits', 'Visits', $count, $visits($period->previous())->count()),
                Widgets::kpi('positive_visits', 'Positive outcomes', $count > 0 ? round($positive / $count * 100, 1) : 0.0, null, 'percent'),
            ],
            'charts' => [
                Widgets::chart('visits_by_day', 'Visits per day', 'bar', Widgets::daily($period, $byDay->map(fn ($v) => (int) $v))),
                Widgets::chart('visits_by_outcome', 'Visit outcomes', 'donut', $byOutcome->map(fn ($total, $label) => ['label' => $label, 'value' => (int) $total])->values()->all()),
            ],
            'tables' => $tables,
        ];
    }
}
