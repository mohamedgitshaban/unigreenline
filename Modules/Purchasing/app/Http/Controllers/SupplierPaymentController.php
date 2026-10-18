<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Purchasing\Http\Requests\StoreSupplierPaymentRequest;
use Modules\Purchasing\Http\Resources\SupplierPaymentResource;
use Modules\Purchasing\Models\SupplierPayment;
use Modules\Purchasing\Services\RecordSupplierPaymentService;

/**
 * Supplier payments are append-only: there is no edit or delete — a wrong
 * payment is corrected with a manual journal entry and a note.
 */
class SupplierPaymentController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['supplier', 'createdBy'];

    public function index(Request $request)
    {
        $query = SupplierPayment::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'reference', 'notes', 'supplier.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Date', 'Supplier', 'Amount', 'Method', 'Reference', 'Purchase Order'],
            fn (SupplierPayment $p) => [$p->id, $p->payment_date->toDateString(), $p->supplier?->name, $p->amount, $p->method, $p->reference, $p->purchase_order_id],
            'supplier-payments',
        )) {
            return $export;
        }

        return SupplierPaymentResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, SupplierPayment $supplierPayment)
    {
        abort_unless($supplierPayment->tenant_id === $request->user()->tenant_id, 404);

        return new SupplierPaymentResource($supplierPayment->load(self::RELATIONS));
    }

    public function store(StoreSupplierPaymentRequest $request, RecordSupplierPaymentService $recordPayment)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;

        $payment = $recordPayment->record($data, [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return (new SupplierPaymentResource($payment->load(self::RELATIONS)))->response()->setStatusCode(201);
    }
}
