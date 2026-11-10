<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockMovement;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\ReturnRecord;
use Modules\Sales\Models\SalesOrderLine;

/**
 * A return made from an invoice: the caller ticks the invoice's lines and how many of each come back, and
 * everything else is worked out from the invoice itself. The credit is the price the customer actually paid for
 * those units (line price, line discount, a share of the invoice discount) plus the VAT charged on them. It is
 * limited to what was billed minus what was already returned, it reduces the invoice's balance and records the
 * returned total on the invoice, and each ticked line becomes one return record.
 */
class ProcessInvoiceReturnService
{
    public function __construct(private readonly ProcessReturnService $returns) {}

    /**
     * @param  array{type: string, restocked?: bool, reason?: ?string, return_date?: ?string, lines: array<int, array{line_id: string, qty: int, batch_no?: ?string}>, actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $data
     * @return array<int, ReturnRecord>
     */
    public function handle(Invoice $invoice, array $data): array
    {
        $order = $invoice->salesOrder;

        if ($invoice->status === 'void') {
            throw ValidationException::withMessages(['invoice' => 'A void invoice cannot take returns.']);
        }

        if ($order === null) {
            throw ValidationException::withMessages(['invoice' => 'This invoice has no lines to return against (it was imported). Record the return without an invoice instead.']);
        }

        $orderLines = $order->lines()->with('product')->get()->keyBy('id');
        $discountShare = (float) $order->subtotal > 0 ? 1 - ((float) $order->invoice_discount / (float) $order->subtotal) : 1.0;
        $restocked = (bool) ($data['restocked'] ?? false);

        return DB::transaction(function () use ($invoice, $order, $orderLines, $discountShare, $restocked, $data) {
            $records = [];

            foreach ($data['lines'] as $index => $requested) {
                /** @var SalesOrderLine|null $line */
                $line = $orderLines->get($requested['line_id']);

                if ($line === null) {
                    throw ValidationException::withMessages(["lines.{$index}.line_id" => 'This line does not belong to the invoice.']);
                }

                if ($line->is_gift) {
                    throw ValidationException::withMessages(["lines.{$index}.line_id" => 'A free-gift line has nothing to credit and cannot be returned.']);
                }

                $packsPerUnit = $line->base_qty ?? ($line->unit === 'Carton' ? $line->product->carton_qty : 1);
                $billedPacks = $line->qty * $packsPerUnit;
                $returnedPacks = $this->returnedPacks($line);
                $requestedPacks = $requested['qty'] * $packsPerUnit;

                if ($returnedPacks + $requestedPacks > $billedPacks) {
                    $left = intdiv($billedPacks - $returnedPacks, $packsPerUnit);

                    throw ValidationException::withMessages(["lines.{$index}.qty" => "Only {$left} of this line can still be returned."]);
                }

                $lineNet = (float) $line->unit_price * (1 - (float) $line->discount_pct / 100) * $requested['qty'];
                $amount = round($lineNet * $discountShare, 2);
                $tax = round($lineNet * (float) $line->product->tax_pct / 100, 2);

                $inCartons = $packsPerUnit === $line->product->carton_qty;
                $warehouseId = $line->warehouse_id ?? $order->warehouse_id;

                $records[] = $this->returns->process([
                    'tenant_id' => $invoice->tenant_id,
                    'invoice_id' => $invoice->id,
                    'so_line_id' => $line->id,
                    'customer_id' => $invoice->customer_id,
                    'product_id' => $line->product_id,
                    'warehouse_id' => $warehouseId,
                    'batch_no' => $restocked ? $this->batchNo($requested, $order->id, $line, $warehouseId, $index) : null,
                    'type' => $data['type'],
                    'qty' => $inCartons ? $requested['qty'] : $requestedPacks,
                    'unit' => $inCartons ? 'Carton' : 'Pack',
                    'amount' => $amount,
                    'tax_amount' => $tax,
                    'restocked' => $restocked,
                    'reason' => $data['reason'] ?? null,
                    'return_date' => $data['return_date'] ?? null,
                    'apply_to_invoice' => true,
                    'actor_id' => $data['actor_id'] ?? null,
                    'actor_name' => $data['actor_name'] ?? null,
                    'ip_address' => $data['ip_address'] ?? null,
                ]);
            }

            return $records;
        });
    }

    /**
     * Packs already sent back against this line, whatever unit each return was entered in.
     */
    private function returnedPacks(SalesOrderLine $line): int
    {
        return (int) ReturnRecord::query()
            ->where('so_line_id', $line->id)
            ->where('status', 'completed')
            ->get(['qty', 'unit'])
            ->sum(fn (ReturnRecord $return) => $return->qty * ($return->unit === 'Carton' ? $line->product->carton_qty : 1));
    }

    /**
     * The batch the goods go back into: the one named, otherwise the first batch the order took this product from.
     *
     * @param  array{batch_no?: ?string}  $requested
     */
    private function batchNo(array $requested, string $orderId, SalesOrderLine $line, string $warehouseId, int $index): string
    {
        $batchNo = $requested['batch_no'] ?? StockMovement::query()
            ->where('reference_type', 'sales_order')->where('reference_id', $orderId)->where('type', 'sale')
            ->where('product_id', $line->product_id)->where('warehouse_id', $warehouseId)->where('is_free_gift', false)
            ->orderBy('id')->value('batch_no');

        if ($batchNo === null) {
            throw ValidationException::withMessages(["lines.{$index}.batch_no" => 'Say which batch these goods go back into.']);
        }

        return $batchNo;
    }
}
