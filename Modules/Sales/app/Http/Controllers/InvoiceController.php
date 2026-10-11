<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ImportsSpreadsheets;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Models\Customer;
use Modules\Sales\Http\Resources\InvoiceResource;
use Modules\Sales\Http\Resources\SalesOrderLineResource;
use Modules\Sales\Imports\InvoiceImport;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\VoidInvoiceService;

class InvoiceController extends Controller
{
    use Exportable, Filterable, ImportsSpreadsheets, Sortable;

    private const RELATIONS = [
        'salesOrder',
        'salesOrder.customer',
        'salesOrder.customer.salesRep',
        'salesOrder.customer.salesRep.roles.permissions',
        'salesOrder.customer.salesRep.permissions',
        'salesOrder.warehouse',
        'salesOrder.warehouse.manager',
        'salesOrder.warehouse.manager.roles.permissions',
        'salesOrder.warehouse.manager.permissions',
        'salesOrder.salesRep',
        'salesOrder.salesRep.roles.permissions',
        'salesOrder.salesRep.permissions',
        'customer',
        'customer.salesRep',
        'customer.salesRep.roles.permissions',
        'customer.salesRep.permissions',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas('salesOrder', fn ($q) => $q->where('sales_rep_id', $request->user()->id))
            );

        $query->with(self::RELATIONS)->withCount('collections')->addSelect(['lines_count' => self::linesCount()]);

        $this->applyFilters($request, $query, ['id', 'so_id', 'customer.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, ['sales.export', 'accounting.export'], $query,
            ['ID', 'Sales Order', 'Customer', 'Issued Date', 'Due Date', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance', 'Status'],
            fn (Invoice $i) => [$i->id, $i->so_id, $i->customer_id, $i->issued_date->toDateString(), $i->due_date?->toDateString(), $i->subtotal, $i->tax_amount, $i->total, $i->paid, $i->balance, $i->status],
            'invoices',
        )) {
            return $export;
        }

        $invoices = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, string $id)
    {
        $invoice = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->withCount('collections')
            ->addSelect(['lines_count' => self::linesCount()])
            ->findOrFail($id);

        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice);
    }

    /**
     * The invoice's lines — mirrored from its sales order, there is no
     * invoice_lines table — as their own paginated list.
     */
    public function lines(Request $request, string $id)
    {
        $invoice = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('salesOrder')
            ->findOrFail($id);

        $this->authorize('view', $invoice);

        $query = SalesOrderLine::query()
            ->where('so_id', $invoice->so_id)
            ->with(['product.category', 'product.supplier']);

        $this->applyFilters($request, $query, ['batch_no', 'product.name', 'product.sku']);

        $this->applySorting($request, $query);

        $lines = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return SalesOrderLineResource::collection($lines);
    }

    /**
     * A sales order's invoices as a paginated list (GET /sales-orders/{id}/invoices).
     */
    public function forSalesOrder(Request $request, string $id)
    {
        $salesOrder = SalesOrder::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $salesOrder);

        return $this->paginatedList($request, Invoice::query()->where('so_id', $salesOrder->id));
    }

    /**
     * A customer's invoices as a paginated list (GET /customers/{id}/invoices).
     */
    public function forCustomer(Request $request, string $id)
    {
        $customer = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $customer);

        $query = Invoice::query()
            ->where('customer_id', $customer->id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->whereHas('salesOrder', fn ($q) => $q->where('sales_rep_id', $request->user()->id))
            );

        return $this->paginatedList($request, $query);
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    private function paginatedList(Request $request, Builder $query): AnonymousResourceCollection
    {
        $query->with(self::RELATIONS)
            ->withCount('collections')
            ->addSelect(['lines_count' => self::linesCount()]);

        $this->applyFilters($request, $query, ['id']);

        $this->applySorting($request, $query);

        $invoices = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    /**
     * Invoices have no lines relation of their own — count the sales
     * order's lines they mirror.
     *
     * @return Builder<SalesOrderLine>
     */
    private static function linesCount(): Builder
    {
        return SalesOrderLine::query()
            ->selectRaw('count(*)')
            ->whereColumn('sales_order_lines.so_id', 'invoices.so_id');
    }

    public function import(Request $request)
    {
        $this->authorize('create', Invoice::class);

        return $this->runImport($request, new InvoiceImport($request->user()));
    }

    public function importTemplate(Request $request)
    {
        $this->authorize('create', Invoice::class);

        return $this->templateResponse(new InvoiceImport($request->user()), 'invoices');
    }

    /**
     * Invoices are never deleted; "delete" means void (reversing journal
     * entry, balance given back to the customer).
     */
    public function void(Request $request, string $id, VoidInvoiceService $voidInvoice)
    {
        $invoice = Invoice::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('void', $invoice);

        $voidInvoice->void($invoice, [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return $this->show($request, $id);
    }
}
