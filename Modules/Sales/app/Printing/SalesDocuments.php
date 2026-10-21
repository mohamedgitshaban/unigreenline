<?php

namespace Modules\Sales\Printing;

use Modules\Core\Printing\PrintableDocument;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/**
 * The paper behind a sale: the invoice, the order, the delivery note and the receipt for a payment.
 */
class SalesDocuments
{
    public function invoice(Invoice $invoice): PrintableDocument
    {
        $invoice->loadMissing(['tenant', 'customer', 'collections', 'salesOrder.salesRep', 'salesOrder.lines.product']);
        $order = $invoice->salesOrder;
        $money = PrintableDocument::money(...);

        $totals = [['label' => 'Subtotal', 'value' => $money($invoice->subtotal)]];

        if ($order && (float) $order->invoice_discount > 0) {
            $totals[] = ['label' => 'Invoice discount', 'value' => '- '.$money($order->invoice_discount)];
        }

        array_push(
            $totals,
            ['label' => 'Tax', 'value' => $money($invoice->tax_amount)],
            ['label' => 'Total', 'value' => $money($invoice->total), 'strong' => true],
            ['label' => 'Paid', 'value' => $money($invoice->paid)],
            ['label' => 'Balance due', 'value' => $money($invoice->balance), 'strong' => true],
        );

        return new PrintableDocument(
            type: 'Invoice',
            number: $invoice->id,
            company: $invoice->tenant->name,
            meta: [
                'Issued' => $invoice->issued_date->toDateString(),
                'Due' => $invoice->due_date?->toDateString(),
                'Order' => $order?->id,
                'Sales rep' => $order?->salesRep?->name,
                'Payment' => $order?->pay_type,
            ],
            parties: [$this->customerParty('Bill to', $invoice->customer)],
            tables: array_values(array_filter([
                $this->orderLines($order, true),
                $invoice->collections->isEmpty() ? null : [
                    'title' => 'Payments received',
                    'columns' => [['key' => 'date', 'label' => 'Date'], ['key' => 'method', 'label' => 'Method'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right']],
                    'rows' => $invoice->collections->sortBy('payment_date')->map(fn (Collection $c) => ['date' => $c->payment_date->toDateString(), 'method' => $c->method, 'reference' => $c->reference, 'amount' => $money($c->amount)])->values()->all(),
                ],
            ])),
            totals: $totals,
            notes: $order?->notes,
            signatures: ['Prepared by', 'Customer'],
            status: $invoice->status,
        );
    }

    public function salesOrder(SalesOrder $order): PrintableDocument
    {
        $order->loadMissing(['tenant', 'customer', 'salesRep', 'warehouse', 'lines.product', 'lines.warehouse']);
        $money = PrintableDocument::money(...);

        return new PrintableDocument(
            type: 'Sales order',
            number: $order->id,
            company: $order->tenant->name,
            meta: ['Ordered' => $order->order_date?->toDateString(), 'Sales rep' => $order->salesRep?->name, 'Payment' => $order->pay_type, 'Due' => $order->due_date?->toDateString()],
            parties: [$this->customerParty('Customer', $order->customer), ['title' => 'Ships from', 'lines' => [$order->warehouse?->name, $order->warehouse?->city]]],
            tables: [$this->orderLines($order, true)],
            totals: [
                ['label' => 'Subtotal', 'value' => $money($order->subtotal)],
                ['label' => 'Tax', 'value' => $money($order->tax_amount)],
                ['label' => 'Total', 'value' => $money($order->total), 'strong' => true],
            ],
            notes: $order->notes,
            signatures: ['Prepared by', 'Approved by'],
            status: $order->status,
        );
    }

    /**
     * What goes to the customer with the goods: quantities and batches, no prices.
     */
    public function delivery(Delivery $delivery): PrintableDocument
    {
        $delivery->loadMissing(['tenant', 'customer', 'salesOrder.lines.product', 'salesOrder.warehouse']);

        return new PrintableDocument(
            type: 'Delivery note',
            number: $delivery->id,
            company: $delivery->tenant->name,
            meta: ['Delivery date' => $delivery->delivery_date?->toDateString(), 'Driver' => $delivery->driver, 'Order' => $delivery->so_id, 'Invoice' => $delivery->invoice_id],
            parties: [$this->customerParty('Deliver to', $delivery->customer), ['title' => 'From', 'lines' => [$delivery->salesOrder?->warehouse?->name, $delivery->salesOrder?->warehouse?->city]]],
            tables: [$this->orderLines($delivery->salesOrder, false)],
            notes: $delivery->notes,
            signatures: ['Prepared by', 'Driver', 'Received by (customer)'],
            status: $delivery->status,
        );
    }

    public function collection(Collection $collection): PrintableDocument
    {
        $collection->loadMissing(['tenant', 'customer', 'invoice', 'collectedBy']);
        $money = PrintableDocument::money(...);

        return new PrintableDocument(
            type: 'Payment receipt',
            number: $collection->id,
            company: $collection->tenant->name,
            meta: ['Date' => $collection->payment_date->toDateString(), 'Method' => $collection->method, 'Reference' => $collection->reference, 'Invoice' => $collection->invoice_id, 'Received by' => $collection->collectedBy?->name],
            parties: [$this->customerParty('Received from', $collection->customer)],
            tables: [[
                'title' => null,
                'columns' => [['key' => 'description', 'label' => 'Description'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right']],
                'rows' => [['description' => "Payment of invoice {$collection->invoice_id}", 'amount' => $money($collection->amount)]],
            ]],
            totals: array_values(array_filter([
                ['label' => 'Amount received', 'value' => $money($collection->amount), 'strong' => true],
                $collection->invoice ? ['label' => 'Invoice balance after this payment', 'value' => $money($collection->invoice->balance)] : null,
            ])),
            notes: $collection->notes,
            signatures: ['Cashier', 'Customer'],
        );
    }

    /**
     * @return array{title: string, lines: array<int, string|null>}
     */
    private function customerParty(string $title, ?Customer $customer): array
    {
        return ['title' => $title, 'lines' => [
            $customer?->name,
            collect([$customer?->address, $customer?->area, $customer?->city, $customer?->governorate])->filter()->implode(', '),
            $customer?->phone,
            $customer?->email,
        ]];
    }

    /**
     * @return array{title: ?string, columns: array<int, array{key: string, label: string, align?: string}>, rows: array<int, array<string, mixed>>}
     */
    private function orderLines(?SalesOrder $order, bool $withPrices): array
    {
        $money = PrintableDocument::money(...);
        $columns = array_values(array_filter([
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'batch', 'label' => 'Batch'],
            ['key' => 'qty', 'label' => 'Qty', 'align' => 'right'],
            ['key' => 'free', 'label' => 'Free', 'align' => 'right'],
            $withPrices ? ['key' => 'price', 'label' => 'Unit price', 'align' => 'right'] : null,
            $withPrices ? ['key' => 'discount', 'label' => 'Disc. %', 'align' => 'right'] : null,
            $withPrices ? ['key' => 'subtotal', 'label' => 'Amount', 'align' => 'right'] : null,
        ]));

        return [
            'title' => null,
            'columns' => $columns,
            'rows' => ($order?->lines ?? collect())->map(fn (SalesOrderLine $line) => [
                'product' => trim($line->product?->name.($line->product?->sku ? " ({$line->product->sku})" : '').($line->is_gift ? ' — free gift' : '')),
                'batch' => $line->batch_no,
                'qty' => "{$line->qty} {$line->unit}",
                'free' => $line->free_qty > 0 ? (string) $line->free_qty : '',
                'price' => $money($line->unit_price),
                'discount' => (float) $line->discount_pct > 0 ? rtrim(rtrim(number_format((float) $line->discount_pct, 2), '0'), '.') : '',
                'subtotal' => $money($line->subtotal),
            ])->all(),
        ];
    }
}
