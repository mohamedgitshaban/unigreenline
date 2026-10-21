<?php

namespace Modules\CRM\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;

/**
 * Customers: how many, how many are new, how they split, and which have no owner yet.
 */
class CustomersSection implements DashboardSection
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function permission(): ?string
    {
        return 'customers.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $customers = fn () => $this->scope->constrain(Customer::query()->where('customers.tenant_id', $user->tenant_id), $user, 'sales_rep_id');
        $created = fn (DashboardPeriod $in) => $customers()->whereBetween('customers.created_at', [$in->from, $in->to])->count();

        $byClassification = $customers()->selectRaw('classification, count(*) as total')->groupBy('classification')->orderBy('classification')->pluck('total', 'classification');
        $byType = $customers()->selectRaw('type, count(*) as total')->groupBy('type')->orderByDesc('total')->pluck('total', 'type');

        $byRep = $customers()->join('users', 'users.id', '=', 'customers.sales_rep_id')
            ->selectRaw('users.id, users.name, count(*) as customers')->groupBy('users.id', 'users.name')->orderByDesc('customers')->limit(10)->get();

        $tables = [];

        if ($byRep->count() > 1 || ! $this->scope->isTeamScoped($user)) {
            $tables[] = Widgets::table('customers_by_rep', 'Customers per rep', [['key' => 'name', 'label' => 'Rep'], ['key' => 'customers', 'label' => 'Customers']],
                $byRep->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'customers' => (int) $row->customers])->all());
        }

        return [
            'kpis' => array_values(array_filter([
                Widgets::kpi('customers', 'Customers', $customers()->count()),
                Widgets::kpi('active_customers', 'Active customers', $customers()->where('status', 'active')->count()),
                Widgets::kpi('new_customers', 'New customers', $created($period), $created($period->previous())),
                $this->scope->isTeamScoped($user) ? null : Widgets::kpi('unassigned_customers', 'Customers with no sales rep', $customers()->whereNull('sales_rep_id')->count()),
            ])),
            'charts' => [
                Widgets::chart('customers_by_classification', 'By classification', 'donut', $byClassification->map(fn ($total, $label) => ['label' => $label, 'value' => (int) $total])->values()->all()),
                Widgets::chart('customers_by_type', 'By type', 'bar', $byType->map(fn ($total, $label) => ['label' => $label, 'value' => (int) $total])->values()->all()),
            ],
            'tables' => $tables,
        ];
    }
}
