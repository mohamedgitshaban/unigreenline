<?php

namespace Modules\CRM\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\CustomerCall;

/**
 * The call log: how many calls, how they ended, who is calling most, and who is due a follow-up.
 */
class CallsSection implements DashboardSection
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'calls';
    }

    public function label(): string
    {
        return 'Calls';
    }

    public function permission(): ?string
    {
        return 'calls.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $calls = fn (DashboardPeriod $in) => CustomerCall::query()->where('customer_calls.tenant_id', $user->tenant_id)->visibleTo($user)
            ->whereBetween('customer_calls.called_at', [$in->from, $in->to]);

        $count = (clone $calls($period))->count();
        $answered = (clone $calls($period))->where('outcome', 'answered')->count();
        $minutes = (int) round((clone $calls($period))->sum('duration_seconds') / 60);

        $byDay = $calls($period)->selectRaw('date(called_at) as day, count(*) as total')->groupBy('day')->pluck('total', 'day');
        $byOutcome = $calls($period)->selectRaw("coalesce(outcome, 'not recorded') as outcome, count(*) as total")->groupBy('outcome')->pluck('total', 'outcome');

        $byRep = $calls($period)->join('users', 'users.id', '=', 'customer_calls.rep_id')
            ->selectRaw('users.id, users.name, count(*) as calls, sum(duration_seconds) as seconds')->groupBy('users.id', 'users.name')->orderByDesc('calls')->limit(10)->get();

        $followUps = CustomerCall::query()->where('customer_calls.tenant_id', $user->tenant_id)->visibleTo($user)
            ->whereNotNull('next_follow_up')->whereBetween('next_follow_up', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->join('customers', 'customers.id', '=', 'customer_calls.customer_id')->orderBy('next_follow_up')->limit(5)
            ->get(['customer_calls.id', 'customers.name as customer', 'customer_calls.next_follow_up', 'customer_calls.subject']);

        $tables = [Widgets::table('call_follow_ups', 'Follow-ups due this week', [['key' => 'customer', 'label' => 'Customer'], ['key' => 'next_follow_up', 'label' => 'Date'], ['key' => 'subject', 'label' => 'About']],
            $followUps->map(fn ($row) => ['id' => $row->id, 'customer' => $row->customer, 'next_follow_up' => $row->next_follow_up->toDateString(), 'subject' => $row->subject])->all())];

        if ($byRep->count() > 1 || ! $this->scope->isTeamScoped($user)) {
            $tables[] = Widgets::table('calls_by_rep', 'Calls per rep', [['key' => 'name', 'label' => 'Rep'], ['key' => 'calls', 'label' => 'Calls'], ['key' => 'minutes', 'label' => 'Minutes']],
                $byRep->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'calls' => (int) $row->calls, 'minutes' => (int) round((int) $row->seconds / 60)])->all());
        }

        return [
            'kpis' => [
                Widgets::kpi('calls', 'Calls', $count, $calls($period->previous())->count()),
                Widgets::kpi('answered_rate', 'Answered', $count > 0 ? round($answered / $count * 100, 1) : 0.0, null, 'percent'),
                Widgets::kpi('talk_time', 'Talk time', $minutes, null, 'count', 'minutes'),
            ],
            'charts' => [
                Widgets::chart('calls_by_day', 'Calls per day', 'bar', Widgets::daily($period, $byDay->map(fn ($v) => (int) $v))),
                Widgets::chart('calls_by_outcome', 'How calls ended', 'donut', $byOutcome->map(fn ($total, $label) => ['label' => $label, 'value' => (int) $total])->values()->all()),
            ],
            'tables' => $tables,
        ];
    }
}
