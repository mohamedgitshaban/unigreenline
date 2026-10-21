<?php

namespace Modules\Inventory\Printing;

use Modules\Core\Printing\PrintableDocument;
use Modules\Inventory\Models\Transfer;

/**
 * The slip that travels with stock moved from one warehouse to another. No prices.
 */
class TransferDocument
{
    public function build(Transfer $transfer): PrintableDocument
    {
        $transfer->loadMissing(['tenant', 'product', 'fromWarehouse.manager', 'toWarehouse.manager', 'createdBy']);

        $place = fn ($warehouse) => ['title' => '', 'lines' => [$warehouse?->name, collect([$warehouse?->address, $warehouse?->city])->filter()->implode(', '), $warehouse?->manager_name ? "Manager: {$warehouse->manager_name}" : null, $warehouse?->phone]];

        return new PrintableDocument(
            type: 'Stock transfer',
            number: $transfer->id,
            company: $transfer->tenant->name,
            meta: ['Date' => $transfer->transfer_date?->toDateString(), 'Prepared by' => $transfer->createdBy?->name],
            parties: [['title' => 'From'] + $place($transfer->fromWarehouse), ['title' => 'To'] + $place($transfer->toWarehouse)],
            tables: [[
                'title' => null,
                'columns' => [['key' => 'product', 'label' => 'Product'], ['key' => 'batch', 'label' => 'Batch'], ['key' => 'qty', 'label' => 'Cartons', 'align' => 'right']],
                'rows' => [[
                    'product' => trim($transfer->product?->name.($transfer->product?->sku ? " ({$transfer->product->sku})" : '')),
                    'batch' => $transfer->batch_no,
                    'qty' => (string) $transfer->qty_cartons,
                ]],
            ]],
            notes: $transfer->notes,
            signatures: ['Sent by', 'Carrier', 'Received by'],
            status: $transfer->status,
        );
    }
}
