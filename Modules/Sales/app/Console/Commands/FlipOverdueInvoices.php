<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Notification;
use Modules\Core\Services\AuditLogService;
use Modules\Sales\Models\Invoice;

/**
 * Flips outstanding/partial invoices to overdue once their due_date has
 * passed (spec §5.4) — the prototype never built this; it was seed-data
 * only. Meant to run daily.
 */
class FlipOverdueInvoices extends Command
{
    protected $signature = 'invoices:flip-overdue';

    protected $description = 'Flip outstanding/partial invoices past their due_date to overdue';

    public function handle(AuditLogService $auditLog): int
    {
        $invoices = Invoice::query()
            ->whereIn('status', ['outstanding', 'partial'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->get();

        foreach ($invoices as $invoice) {
            DB::transaction(function () use ($invoice, $auditLog) {
                $prevStatus = $invoice->status;

                $invoice->update(['status' => 'overdue']);

                Notification::create([
                    'tenant_id' => $invoice->tenant_id,
                    'user_id' => null,
                    'type' => 'invoice_overdue',
                    'title' => 'Invoice overdue',
                    'body' => "Invoice {$invoice->id} (customer {$invoice->customer_id}) is now overdue.",
                    'link_module' => 'sales',
                    'link_id' => $invoice->id,
                    'unread' => true,
                ]);

                $auditLog->record([
                    'module' => 'sales',
                    'entity_type' => 'Invoice',
                    'entity_id' => $invoice->id,
                    'operation' => 'UPDATE',
                    'tenant_id' => $invoice->tenant_id,
                    'prev_values' => ['status' => $prevStatus],
                    'new_values' => ['status' => 'overdue'],
                ]);
            });
        }

        $this->info("Flipped {$invoices->count()} invoice(s) to overdue.");

        return self::SUCCESS;
    }
}
