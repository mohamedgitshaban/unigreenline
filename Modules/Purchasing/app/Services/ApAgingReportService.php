<?php

namespace Modules\Purchasing\Services;

use Modules\Purchasing\Models\Supplier;

/**
 * AP "aging" (spec §10.7) — not a true date-bucketed report like AR's.
 * purchase_orders has no due_date or payment tracking (suppliers.balance
 * only ever moves via PO receipt — see the "not built" note in
 * purchasing.md), so there's no per-transaction age to bucket by. This is
 * simply every supplier's current outstanding balance, sorted by amount
 * owed. Revisit once a supplier-payment flow exists.
 */
class ApAgingReportService
{
    public function generate(string $tenantId): array
    {
        $suppliers = Supplier::query()
            ->where('tenant_id', $tenantId)
            ->where('balance', '<', 0)
            ->orderBy('balance')
            ->get();

        $rows = $suppliers->map(fn (Supplier $supplier) => [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'balance' => $supplier->balance,
        ])->values();

        return [
            'as_of' => now()->toDateString(),
            'suppliers' => $rows->all(),
            'grand_total_owed' => number_format(abs((float) $suppliers->sum(fn (Supplier $s) => (float) $s->balance)), 2, '.', ''),
        ];
    }
}
