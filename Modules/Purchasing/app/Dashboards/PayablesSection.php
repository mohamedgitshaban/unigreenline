<?php

namespace Modules\Purchasing\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierPayment;

/**
 * What is owed to suppliers and what was paid. A supplier's balance is negative when the company owes them.
 */
class PayablesSection implements DashboardSection
{
    public function key(): string
    {
        return 'payables';
    }

    public function label(): string
    {
        return 'Supplier payables';
    }

    public function permission(): ?string
    {
        return 'suppliers.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $suppliers = fn () => Supplier::query()->where('suppliers.tenant_id', $user->tenant_id);
        $payments = fn (DashboardPeriod $in) => SupplierPayment::query()->where('supplier_payments.tenant_id', $user->tenant_id)
            ->whereBetween('payment_date', [$in->from->toDateString(), $in->to->toDateString()]);

        $owed = abs((float) $suppliers()->where('balance', '<', 0)->sum('balance'));
        $paid = (float) $payments($period)->sum('amount');
        $paidBefore = (float) $payments($period->previous())->sum('amount');
        $paidByDay = $payments($period)->selectRaw('payment_date as day, sum(amount) as total')->groupBy('payment_date')->pluck('total', 'day')->map(fn ($v) => (float) $v);

        return [
            'kpis' => [
                Widgets::kpi('payables', 'Owed to suppliers', $owed, null, 'currency'),
                Widgets::kpi('supplier_payments', 'Paid to suppliers', $paid, $paidBefore, 'currency'),
                Widgets::kpi('suppliers_owed', 'Suppliers owed money', $suppliers()->where('balance', '<', 0)->count()),
            ],
            'charts' => [
                Widgets::chart('payments_by_day', 'Payments per day', 'bar', Widgets::daily($period, $paidByDay), 'currency'),
            ],
            'tables' => [
                Widgets::table('largest_payables', 'Largest amounts owed', [['key' => 'name', 'label' => 'Supplier'], ['key' => 'owed', 'label' => 'Owed']],
                    $suppliers()->where('balance', '<', 0)->orderBy('balance')->limit(5)->get(['id', 'name', 'balance'])->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'owed' => round(abs((float) $row->balance), 2)])->all()),
            ],
        ];
    }
}
