<?php

namespace Modules\Sales\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;

/**
 * Invoices and collections: what was billed, what was collected, and what is still owed and how late it is.
 */
class InvoicesSection implements DashboardSection
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Invoices and collections';
    }

    public function permission(): ?string
    {
        return 'invoices.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $invoices = fn () => $this->scope->constrainThrough(Invoice::query()->where('invoices.tenant_id', $user->tenant_id)->where('invoices.status', '!=', 'void'), $user, 'salesOrder', 'sales_rep_id');
        $collections = fn () => $this->scope->constrainThrough(Collection::query()->where('collections.tenant_id', $user->tenant_id), $user, 'invoice.salesOrder', 'sales_rep_id');
        $previous = $period->previous();
        $between = fn ($query, DashboardPeriod $in, string $column) => $query->whereBetween($column, [$in->from->toDateString(), $in->to->toDateString()]);

        $billed = (float) $between($invoices(), $period, 'issued_date')->sum('total');
        $billedBefore = (float) $between($invoices(), $previous, 'issued_date')->sum('total');
        $collected = (float) $between($collections(), $period, 'payment_date')->sum('amount');
        $collectedBefore = (float) $between($collections(), $previous, 'payment_date')->sum('amount');

        $open = fn () => $invoices()->whereIn('invoices.status', ['outstanding', 'partial', 'overdue']);
        $outstanding = (float) $open()->sum('balance');
        $overdue = (float) $invoices()->where('invoices.status', 'overdue')->sum('balance');

        $today = now()->toDateString();
        $aging = $open()->selectRaw(
            'coalesce(sum(case when due_date is null or due_date >= ? then balance end), 0) as current_amount,'
            .'coalesce(sum(case when due_date < ? and due_date >= ? then balance end), 0) as d30,'
            .'coalesce(sum(case when due_date < ? and due_date >= ? then balance end), 0) as d60,'
            .'coalesce(sum(case when due_date < ? then balance end), 0) as d90',
            [$today, $today, now()->subDays(30)->toDateString(), now()->subDays(30)->toDateString(), now()->subDays(60)->toDateString(), now()->subDays(60)->toDateString()]
        )->first();

        $collectedByDay = $between($collections(), $period, 'payment_date')->selectRaw('payment_date as day, sum(amount) as value')->groupBy('payment_date')->pluck('value', 'day')->map(fn ($v) => (float) $v);

        $overdueList = $invoices()->where('invoices.status', 'overdue')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->orderBy('invoices.due_date')->limit(5)
            ->get(['invoices.id', 'customers.name as customer', 'invoices.due_date', 'invoices.balance']);

        return [
            'kpis' => [
                Widgets::kpi('billed', 'Invoiced', $billed, $billedBefore, 'currency'),
                Widgets::kpi('collected', 'Collected', $collected, $collectedBefore, 'currency'),
                Widgets::kpi('outstanding', 'Outstanding receivables', $outstanding, null, 'currency'),
                Widgets::kpi('overdue', 'Overdue', $overdue, null, 'currency'),
                Widgets::kpi('collection_rate', 'Collected vs invoiced', $billed > 0 ? round($collected / $billed * 100, 1) : 0.0, null, 'percent'),
            ],
            'charts' => [
                Widgets::chart('collections_by_day', 'Collections per day', 'bar', Widgets::daily($period, $collectedByDay), 'currency'),
                Widgets::chart('receivables_aging', 'Receivables by age', 'bar', [
                    ['label' => 'Not due', 'value' => (float) $aging->current_amount],
                    ['label' => '1-30 days late', 'value' => (float) $aging->d30],
                    ['label' => '31-60 days late', 'value' => (float) $aging->d60],
                    ['label' => 'Over 60 days late', 'value' => (float) $aging->d90],
                ], 'currency'),
            ],
            'tables' => [
                Widgets::table('overdue_invoices', 'Oldest overdue invoices', [['key' => 'customer', 'label' => 'Customer'], ['key' => 'due_date', 'label' => 'Due'], ['key' => 'balance', 'label' => 'Balance']],
                    $overdueList->map(fn ($row) => ['id' => $row->id, 'customer' => $row->customer, 'due_date' => $row->due_date, 'balance' => round((float) $row->balance, 2)])->all()),
            ],
        ];
    }
}
