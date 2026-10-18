<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Carbon;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * Every ledger entry manufacturing makes, and nothing else posts for it.
 *
 * Two policies, chosen per order when it is confirmed (a setting):
 *  - cost on receipt (default): nothing is booked while an order is under way;
 *    when finished goods are received, their cost moves in one entry:
 *      Dr 1300 Inventory (finished goods and by-products) / Cr 1300 Inventory (materials used)
 *      / Cr 6400 Overhead Absorbed (labour, machine, overhead);
 *  - work in progress: each step is booked as it happens through 1350 —
 *      issue: Dr 1350 / Cr 1300 · operation done: Dr 1350 / Cr 6400 ·
 *      receipt: Dr 1300 / Cr 1350 (+ overhead applied: Dr 1350 / Cr 6400).
 * Either way, what the finished goods did not carry is settled at completion to
 * 5200 Manufacturing Variance, and material scrap that is expensed goes to 6300.
 *
 * The `ref` of every entry is the order id, so all of an order's entries can be found together.
 */
class ManufacturingPostingService
{
    public function __construct(private readonly JournalPostingService $journal) {}

    /**
     * Material issued to an order (work-in-progress policy only).
     */
    public function postIssue(ProductionOrder $order, float $value, ?string $userId = null): ?JournalEntry
    {
        if (! $order->wip_accounting || $value <= 0) {
            return null;
        }

        return $this->post($order, "Materials issued to {$this->label($order)}", [
            ['account_code' => '1350', 'debit' => $value],
            ['account_code' => '1300', 'credit' => $value],
        ], $userId);
    }

    /**
     * Material lost in production and charged to the period rather than to the product.
     */
    public function postExpensedScrap(ProductionOrder $order, float $value, ?string $userId = null): ?JournalEntry
    {
        if ($value <= 0) {
            return null;
        }

        return $this->post($order, "Production scrap on {$this->label($order)}", [
            ['account_code' => '6300', 'debit' => $value],
            ['account_code' => '1300', 'credit' => $value],
        ], $userId);
    }

    /**
     * Labour and machine time of a finished operation (work-in-progress policy only).
     */
    public function postOperationCost(ProductionOrder $order, float $value, string $operationName, ?string $userId = null): ?JournalEntry
    {
        if (! $order->wip_accounting || $value <= 0) {
            return null;
        }

        return $this->post($order, "Operation {$operationName} on {$this->label($order)}", [
            ['account_code' => '1350', 'debit' => $value],
            ['account_code' => '6400', 'credit' => $value],
        ], $userId);
    }

    /**
     * Finished goods (and by-products) received.
     *
     * @param  float  $value  Total cost moved into inventory by this receipt.
     * @param  float  $materialPortion  The part of `$value` that is material (cost-on-receipt policy).
     * @param  float  $overheadApplied  Overhead charged by this receipt (work-in-progress policy).
     */
    public function postReceipt(ProductionOrder $order, float $value, float $materialPortion, float $overheadApplied, ?string $userId = null): ?JournalEntry
    {
        if ($value <= 0 && $overheadApplied <= 0) {
            return null;
        }

        $lines = $order->wip_accounting
            ? [
                ['account_code' => '1350', 'debit' => $overheadApplied],
                ['account_code' => '6400', 'credit' => $overheadApplied],
                ['account_code' => '1300', 'debit' => $value],
                ['account_code' => '1350', 'credit' => $value],
            ]
            : [
                ['account_code' => '1300', 'debit' => $value],
                ['account_code' => '1300', 'credit' => $materialPortion],
                ['account_code' => '6400', 'credit' => round($value - $materialPortion, 2)],
            ];

        return $this->post($order, "Finished goods received from {$this->label($order)}", $lines, $userId);
    }

    /**
     * What the finished goods did not carry: a positive amount is a loss (Dr 5200), a negative one a gain.
     * Cost-on-receipt orders settle only the material that left inventory without reaching finished goods.
     */
    public function postVariance(ProductionOrder $order, float $amount, ?string $userId = null): ?JournalEntry
    {
        $amount = round($amount, 2);

        if (abs($amount) < 0.005) {
            return null;
        }

        $inventory = $order->wip_accounting ? '1350' : '1300';

        return $this->post($order, "Manufacturing variance on {$this->label($order)}", $amount > 0
            ? [['account_code' => '5200', 'debit' => $amount], ['account_code' => $inventory, 'credit' => $amount]]
            : [['account_code' => $inventory, 'debit' => -$amount], ['account_code' => '5200', 'credit' => -$amount]],
            $userId);
    }

    /**
     * @param  array<int, array{account_code: string, debit?: float, credit?: float}>  $lines
     */
    private function post(ProductionOrder $order, string $description, array $lines, ?string $userId): ?JournalEntry
    {
        $lines = array_values(array_filter($lines, fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0));

        if ($lines === []) {
            return null;
        }

        return $this->journal->post($order->tenant_id, $order->id, $description, $lines, $userId, Carbon::now());
    }

    private function label(ProductionOrder $order): string
    {
        return $order->number ?? $order->id;
    }
}
