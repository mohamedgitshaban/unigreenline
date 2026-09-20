<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sales\Http\Resources\InvoiceResource;
use Modules\Sales\Models\Invoice;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas('salesOrder', fn ($q) => $q->where('sales_rep_id', $request->user()->id))
            )
            ->latest('issued_date')
            ->paginate($request->integer('per_page', 15));

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['salesOrder.lines', 'collections']));
    }
}
