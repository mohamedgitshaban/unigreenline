<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Customer;
use Modules\Sales\Exceptions\InvalidCollectionAmountException;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;

/**
 * Takes one payment from a customer and spreads it over his open invoices, oldest due date first (invoices with
 * no due date after the dated ones). Each invoice touched gets its own collection, so receipts, the journal and
 * the aging report work exactly as for a payment made against one invoice. A payment larger than the customer
 * owes is refused, like a payment larger than one invoice's balance.
 */
class CreateCustomerCollectionService
{
    public function __construct(private readonly CreateCollectionService $createCollection) {}

    /**
     * @param  array{amount: float|int|string, method: string, reference?: ?string, payment_date?: ?string, notes?: ?string, collected_by?: ?string, actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $data
     * @return array<int, Collection> One per invoice paid, in the order they were paid.
     */
    public function create(Customer $customer, array $data): array
    {
        return DB::transaction(function () use ($customer, $data) {
            $invoices = Invoice::query()
                ->where('tenant_id', $customer->tenant_id)
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['outstanding', 'partial', 'overdue'])
                ->where('balance', '>', 0)
                ->orderByRaw('due_date is null')
                ->orderBy('due_date')
                ->orderBy('issued_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $owed = round((float) $invoices->sum('balance'), 2);
            $remaining = round((float) $data['amount'], 2);

            if ($remaining > $owed) {
                throw new InvalidCollectionAmountException('The amount is more than this customer owes ('.number_format($owed, 2, '.', '').').');
            }

            $collections = [];

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, round((float) $invoice->balance, 2));

                $collections[] = $this->createCollection->create([
                    'invoice_id' => $invoice->id,
                    'amount' => $take,
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'payment_date' => $data['payment_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'collected_by' => $data['collected_by'] ?? null,
                    'actor_id' => $data['actor_id'] ?? null,
                    'actor_name' => $data['actor_name'] ?? null,
                    'ip_address' => $data['ip_address'] ?? null,
                ]);

                $remaining = round($remaining - $take, 2);
            }

            return $collections;
        });
    }
}
