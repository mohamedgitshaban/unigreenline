<?php

namespace Modules\Sales\Services;

use Modules\Sales\Models\Invoice;

/**
 * AR aging (spec §10.7) — not in §6's endpoint table, added to fill that
 * gap. Lives in Sales, not Accounting, because it reads Invoice and
 * Customer directly rather than the ledger; Accounting already depends on
 * nothing outside itself, and putting this there would make it depend on
 * Sales for no benefit.
 */
class ArAgingReportService
{
    /** @var array<string, int> bucket => max days overdue (last one is unbounded) */
    private const BUCKETS = ['current' => 0, 'days_1_30' => 30, 'days_31_60' => 60, 'days_61_90' => 90];

    public function generate(string $tenantId): array
    {
        $invoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['outstanding', 'partial', 'overdue'])
            ->with('customer')
            ->get();

        $rows = $invoices->groupBy('customer_id')->map(function ($customerInvoices) {
            $buckets = ['current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_90_plus' => 0.0];

            foreach ($customerInvoices as $invoice) {
                $buckets[$this->bucketFor($invoice)] += (float) $invoice->balance;
            }

            $customer = $customerInvoices->first()->customer;

            return [
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name,
                'current' => $this->money($buckets['current']),
                'days_1_30' => $this->money($buckets['days_1_30']),
                'days_31_60' => $this->money($buckets['days_31_60']),
                'days_61_90' => $this->money($buckets['days_61_90']),
                'days_90_plus' => $this->money($buckets['days_90_plus']),
                'total' => $this->money(array_sum($buckets)),
            ];
        })->values();

        return [
            'as_of' => now()->toDateString(),
            'customers' => $rows->all(),
            'grand_total' => $this->money($invoices->sum(fn (Invoice $i) => (float) $i->balance)),
        ];
    }

    private function bucketFor(Invoice $invoice): string
    {
        $dueDate = $invoice->due_date;
        $overdueDays = ($dueDate && $dueDate->isPast()) ? (int) $dueDate->diffInDays(now()) : 0;

        foreach (self::BUCKETS as $bucket => $maxDays) {
            if ($overdueDays <= $maxDays) {
                return $bucket;
            }
        }

        return 'days_90_plus';
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
