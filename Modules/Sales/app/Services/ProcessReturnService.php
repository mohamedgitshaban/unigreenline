<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Exceptions\ReturnBatchNotFoundException;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Support\CartonCalculator;

/**
 * Records a return and moves stock accordingly (spec §5.9):
 *  - "Purchase Return" (going back to the supplier) always deducts from the
 *    matching batch — stock physically leaves, regardless of `restocked`.
 *  - The four customer-facing types (Sales Return/Damaged/Expired
 *    Return/Wrong Item) only move stock when `restocked` is true, adding
 *    back to the matching batch — false means it's written off, not
 *    returned to sellable stock.
 * Neither direction ever fabricates a batch: the returns table has no
 * expiry field, so the matching batch must already exist.
 * Every return is also posted to the ledger and moves the customer's or
 * supplier's balance — see postToLedger().
 */
class ProcessReturnService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly JournalPostingService $journalPosting,
    ) {}

    /**
     * @param  array{
     *     tenant_id: string, invoice_id?: ?string, customer_id?: ?string, supplier_id?: ?string,
     *     product_id?: ?string, warehouse_id?: ?string, batch_no?: ?string, type: string,
     *     qty: int, unit: string, amount: float, restocked?: bool, reason?: ?string, return_date?: ?string,
     *     actor_id?: ?string, actor_name?: ?string, ip_address?: ?string,
     * }  $data
     */
    public function process(array $data): ReturnRecord
    {
        $restocked = $data['restocked'] ?? false;

        return DB::transaction(function () use ($data, $restocked) {
            $stockValue = 0.0;

            if ($data['type'] === 'Purchase Return') {
                $stockValue = $this->deductFromBatch($data);
            } elseif ($restocked) {
                $stockValue = $this->addToBatch($data);
            }

            $return = ReturnRecord::create([
                'tenant_id' => $data['tenant_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'batch_no' => $data['batch_no'] ?? null,
                'type' => $data['type'],
                'qty' => $data['qty'],
                'unit' => $data['unit'],
                'amount' => $data['amount'],
                'restocked' => $restocked,
                'reason' => $data['reason'] ?? null,
                'status' => 'completed',
                'return_date' => $data['return_date'] ?? now()->toDateString(),
            ]);

            $this->postToLedger($return, $data, $stockValue);

            $this->auditLog->record([
                'module' => 'sales',
                'entity_type' => 'ReturnRecord',
                'entity_id' => $return->id,
                'operation' => 'INSERT',
                'user_id' => $data['actor_id'] ?? null,
                'user_name' => $data['actor_name'] ?? null,
                'tenant_id' => $data['tenant_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'new_values' => ['type' => $data['type'], 'qty' => $data['qty'], 'restocked' => $restocked],
                'ip_address' => $data['ip_address'] ?? null,
            ]);

            return $return;
        });
    }

    /**
     * The accounting side of a return (`amount` is the credit-note value, net of tax):
     *  - customer-facing: Dr Sales Revenue / Cr Accounts Receivable, and the customer's
     *    balance drops by the same amount (floored at zero); if the goods went back
     *    into stock, Dr Inventory / Cr Cost of Goods Sold for what they cost;
     *  - purchase return: Dr Accounts Payable / Cr Inventory, and what we owe the
     *    supplier drops by the amount.
     *
     * @param  array<string, mixed>  $data
     */
    private function postToLedger(ReturnRecord $return, array $data, float $stockValue): void
    {
        $amount = round((float) $data['amount'], 2);
        $lines = [];

        if ($data['type'] === 'Purchase Return') {
            $lines = [
                ['account_code' => '2100', 'debit' => $amount],
                ['account_code' => '1300', 'credit' => $amount],
            ];

            if ($amount > 0 && $return->supplier_id !== null) {
                Supplier::query()->whereKey($return->supplier_id)->increment('balance', $amount);
            }
        } else {
            $lines = [
                ['account_code' => '4100', 'debit' => $amount],
                ['account_code' => '1200', 'credit' => $amount],
            ];

            if ($stockValue > 0) {
                $lines[] = ['account_code' => '1300', 'debit' => round($stockValue, 2)];
                $lines[] = ['account_code' => '5100', 'credit' => round($stockValue, 2)];
            }

            if ($amount > 0 && $return->customer_id !== null) {
                $customer = Customer::query()->find($return->customer_id);
                $customer?->forceFill(['balance' => max(0, (float) $customer->balance - $amount)])->save();
            }
        }

        $lines = array_values(array_filter($lines, fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0));

        if ($lines === []) {
            return;
        }

        $this->journalPosting->post(
            $return->tenant_id,
            $return->id,
            "{$return->type} {$return->id}",
            $lines,
            $data['actor_id'] ?? null,
            $return->return_date,
        );
    }

    private function cartonsFor(array $data): int
    {
        $product = Product::findOrFail($data['product_id']);

        return CartonCalculator::cartonsNeeded($data['qty'], 0, $data['unit'], $product->carton_qty);
    }

    /**
     * @return float Cost of the cartons that left.
     */
    private function deductFromBatch(array $data): float
    {
        $cartons = $this->cartonsFor($data);

        $batch = InventoryBatch::query()
            ->where('tenant_id', $data['tenant_id'])
            ->where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('batch_no', $data['batch_no'])
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            throw new ReturnBatchNotFoundException($data['product_id'], $data['warehouse_id'], $data['batch_no']);
        }

        if ($batch->freeCartons() < $cartons) {
            throw new InsufficientStockException([[
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['warehouse_id'],
                'needed' => $cartons,
                'available' => $batch->freeCartons(),
            ]]);
        }

        $value = $cartons * (float) $batch->cost_per_carton;

        $batch->decrement('qty_cartons', $cartons);

        if ($batch->fresh()->qty_cartons === 0) {
            $batch->delete();
        }

        Warehouse::find($data['warehouse_id'])?->recalculateStockValue();

        return $value;
    }

    /**
     * @return float Cost of the cartons that came back.
     */
    private function addToBatch(array $data): float
    {
        $cartons = $this->cartonsFor($data);

        $batch = InventoryBatch::query()
            ->where('tenant_id', $data['tenant_id'])
            ->where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('batch_no', $data['batch_no'])
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            throw new ReturnBatchNotFoundException($data['product_id'], $data['warehouse_id'], $data['batch_no']);
        }

        $batch->increment('qty_cartons', $cartons);

        Warehouse::find($data['warehouse_id'])?->recalculateStockValue();

        return $cartons * (float) $batch->cost_per_carton;
    }
}
