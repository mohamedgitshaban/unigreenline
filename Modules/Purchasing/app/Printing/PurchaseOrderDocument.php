<?php

namespace Modules\Purchasing\Printing;

use Modules\Core\Printing\PrintableDocument;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;

/**
 * The purchase order as it is sent to the supplier.
 */
class PurchaseOrderDocument
{
    public function build(PurchaseOrder $order): PrintableDocument
    {
        $order->loadMissing(['tenant', 'supplier', 'warehouse', 'createdBy', 'lines.product']);
        $money = PrintableDocument::money(...);
        $priced = $order->status !== 'pending_pricing';

        return new PrintableDocument(
            type: 'Purchase order',
            number: $order->id,
            company: $order->tenant->name,
            meta: [
                'Ordered' => $order->order_date?->toDateString(), 'Expected' => $order->expected_date?->toDateString(),
                'Received' => $order->received_date?->toDateString(), 'Prepared by' => $order->createdBy?->name,
            ],
            parties: [
                ['title' => 'Supplier', 'lines' => [$order->supplier?->name, collect([$order->supplier?->city, $order->supplier?->country])->filter()->implode(', '), $order->supplier?->contact, $order->supplier?->phone, $order->supplier?->email]],
                ['title' => 'Deliver to', 'lines' => [$order->warehouse?->name, collect([$order->warehouse?->address, $order->warehouse?->city])->filter()->implode(', '), $order->warehouse?->phone]],
            ],
            tables: [[
                'title' => null,
                'columns' => [
                    ['key' => 'product', 'label' => 'Product'], ['key' => 'qty', 'label' => 'Cartons', 'align' => 'right'],
                    ['key' => 'cost', 'label' => 'Cost / carton', 'align' => 'right'], ['key' => 'total', 'label' => 'Amount', 'align' => 'right'],
                ],
                'rows' => $order->lines->map(fn (PurchaseOrderLine $line) => [
                    'product' => trim($line->product?->name.($line->product?->sku ? " ({$line->product->sku})" : '')),
                    'qty' => (string) $line->qty_cartons,
                    'cost' => $priced ? $money($line->cost_per_carton) : '—',
                    'total' => $priced ? $money($line->total) : '—',
                ])->all(),
            ]],
            totals: $priced ? [
                ['label' => 'Subtotal', 'value' => $money($order->subtotal)],
                ['label' => 'Tax', 'value' => $money($order->tax_amount)],
                ['label' => 'Total', 'value' => $money($order->total), 'strong' => true],
            ] : [],
            notes: $order->notes,
            signatures: ['Prepared by', 'Approved by', 'Supplier'],
            status: $order->status,
        );
    }
}
