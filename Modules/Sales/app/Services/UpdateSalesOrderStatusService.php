<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Services\FefoStockService;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\CartonCalculator;

/**
 * Drives the sales order status lifecycle (spec §5.1, §5.4, §6):
 *  - draft/picking → invoiced or delivered deducts stock (idempotent via
 *    stock_deducted) and auto-creates the invoice (idempotent — skipped if
 *    one already exists for this order) with its journal entry
 *  - → delivered additionally creates/updates the one delivery row for
 *    this order
 * Everything for one transition is a single atomic operation.
 */
class UpdateSalesOrderStatusService
{
    /** @var array<string, array<int, string>> */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['picking', 'invoiced', 'delivered', 'cancelled'],
        'picking' => ['invoiced', 'delivered', 'cancelled'],
        'invoiced' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly FefoStockService $fefoStock,
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function transition(SalesOrder $order, string $newStatus, array $actor = []): SalesOrder
    {
        $oldStatus = $order->status;

        if (! in_array($newStatus, self::ALLOWED_TRANSITIONS[$oldStatus] ?? [], true)) {
            throw new InvalidStatusTransitionException($oldStatus, $newStatus);
        }

        return DB::transaction(function () use ($order, $oldStatus, $newStatus, $actor) {
            if (in_array($newStatus, ['invoiced', 'delivered'], true)) {
                $this->deductStockIfNeeded($order, $actor);
                $this->createInvoiceIfNeeded($order, $actor);
            }

            if ($newStatus === 'delivered') {
                $this->upsertDelivery($order);
            }

            $order->update(['status' => $newStatus]);

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'SalesOrder',
                'entity_id' => $order->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $order->tenant_id,
                'warehouse_id' => $order->warehouse_id,
                'prev_values' => ['status' => $oldStatus],
                'new_values' => ['status' => $newStatus],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $order->fresh(['lines', 'invoices', 'delivery']);
        });
    }

    private function deductStockIfNeeded(SalesOrder $order, array $actor): void
    {
        if ($order->stock_deducted) {
            return;
        }

        $lines = $order->lines()->with('product')->get()->map(fn ($line) => [
            'product_id' => $line->product_id,
            'warehouse_id' => $order->warehouse_id,
            'cartons_needed' => CartonCalculator::cartonsNeeded(
                $line->qty, $line->free_qty, $line->unit, $line->product->carton_qty
            ),
        ])->all();

        $this->fefoStock->deduct($lines);

        $order->update(['stock_deducted' => true]);
    }

    private function createInvoiceIfNeeded(SalesOrder $order, array $actor): void
    {
        if ($order->invoices()->exists()) {
            return;
        }

        $invoice = Invoice::create([
            'tenant_id' => $order->tenant_id,
            'so_id' => $order->id,
            'customer_id' => $order->customer_id,
            'issued_date' => now()->toDateString(),
            'due_date' => $order->due_date,
            'subtotal' => $order->subtotal,
            'tax_amount' => $order->tax_amount,
            'total' => $order->total,
            'paid' => 0,
            'balance' => $order->total,
            'status' => 'outstanding',
        ]);

        // A journal line with a zero amount is meaningless and violates the
        // debit-xor-credit CHECK constraint — e.g. a tax-exempt invoice has
        // no VAT Payable line at all, not a $0 one.
        $lines = array_values(array_filter([
            ['account_code' => '1200', 'debit' => (float) $invoice->total],
            ['account_code' => '4100', 'credit' => (float) $invoice->subtotal],
            ['account_code' => '2300', 'credit' => (float) $invoice->tax_amount],
        ], fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0));

        if ($lines !== []) {
            $this->journalPosting->post(
                $order->tenant_id,
                $invoice->id,
                "Invoice for sales order {$order->id}",
                $lines,
                $actor['actor_id'] ?? null,
            );
        }

        if ($order->pay_type === 'credit') {
            $order->customer()->increment('balance', $invoice->total);
        }

        $this->auditLog->record([
            'module' => 'sales',
            'entity_type' => 'Invoice',
            'entity_id' => $invoice->id,
            'operation' => 'INSERT',
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $order->tenant_id,
            'new_values' => ['so_id' => $order->id, 'total' => (string) $invoice->total],
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }

    private function upsertDelivery(SalesOrder $order): void
    {
        $invoice = $order->invoices()->latest()->first();

        Delivery::query()->updateOrCreate(
            ['so_id' => $order->id],
            [
                'tenant_id' => $order->tenant_id,
                'invoice_id' => $invoice?->id,
                'customer_id' => $order->customer_id,
                'delivery_date' => now()->toDateString(),
                'status' => 'delivered',
                'delivered_at' => now(),
            ]
        );
    }
}
