<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Sales\Http\Resources\InvoiceResource;
use Modules\Sales\Models\Invoice;

class InvoiceController extends Controller
{
    use Exportable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas('salesOrder', fn ($q) => $q->where('sales_rep_id', $request->user()->id))
            )
            ->latest('issued_date');

        if ($export = $this->exportIfRequested(
            $request, ['sales.export', 'accounting.export'], $query,
            ['ID', 'Sales Order', 'Customer', 'Issued Date', 'Due Date', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance', 'Status'],
            fn (Invoice $i) => [$i->id, $i->so_id, $i->customer_id, $i->issued_date->toDateString(), $i->due_date?->toDateString(), $i->subtotal, $i->tax_amount, $i->total, $i->paid, $i->balance, $i->status],
            'invoices',
        )) {
            return $export;
        }

        $invoices = $query->paginate($request->integer('per_page', 15));

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['salesOrder.lines', 'collections']));
    }
}
