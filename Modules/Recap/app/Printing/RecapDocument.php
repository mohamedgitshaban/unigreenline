<?php

namespace Modules\Recap\Printing;

use Modules\Core\Models\User;
use Modules\Core\Printing\PrintableDocument;
use Modules\Recap\Models\Recap;
use Modules\Recap\Models\RecapLine;

/**
 * The slip for stock written off. Values are shown only to those who see batch costs.
 */
class RecapDocument
{
    public function build(Recap $recap, User $viewer): PrintableDocument
    {
        $recap->loadMissing(['tenant', 'warehouse', 'createdBy', 'approvedBy', 'lines.product', 'lines.batch']);
        $money = PrintableDocument::money(...);
        $withValues = $viewer->hasRole('Administrator');

        return new PrintableDocument(
            type: 'Stock write-off',
            number: $recap->id,
            company: $recap->tenant->name,
            meta: ['Date' => $recap->recap_date?->toDateString(), 'Reason' => $recap->reason, 'Prepared by' => $recap->createdBy?->name, 'Approved by' => $recap->approvedBy?->name],
            parties: [['title' => 'Warehouse', 'lines' => [$recap->warehouse?->name, $recap->warehouse?->city]]],
            tables: [[
                'title' => null,
                'columns' => array_values(array_filter([
                    ['key' => 'product', 'label' => 'Product'], ['key' => 'batch', 'label' => 'Batch'], ['key' => 'expires', 'label' => 'Expires'],
                    ['key' => 'qty', 'label' => 'Cartons', 'align' => 'right'], $withValues ? ['key' => 'value', 'label' => 'Value', 'align' => 'right'] : null,
                ])),
                'rows' => $recap->lines->map(fn (RecapLine $line) => [
                    'product' => trim($line->product?->name.($line->product?->sku ? " ({$line->product->sku})" : '')),
                    'batch' => $line->batch?->batch_no,
                    'expires' => $line->batch?->exp_date?->toDateString(),
                    'qty' => (string) $line->qty_cartons,
                    'value' => $money($line->line_value),
                ])->all(),
            ]],
            totals: $withValues ? [['label' => 'Total value', 'value' => $money($recap->total_value), 'strong' => true]] : [],
            notes: $recap->notes,
            signatures: ['Prepared by', 'Approved by'],
            status: $recap->status,
        );
    }
}
