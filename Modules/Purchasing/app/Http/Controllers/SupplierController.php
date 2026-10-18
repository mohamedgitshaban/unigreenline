<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ImportsSpreadsheets;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Purchasing\Http\Requests\StoreSupplierRequest;
use Modules\Purchasing\Http\Requests\UpdateSupplierRequest;
use Modules\Purchasing\Http\Requests\UploadSupplierContractRequest;
use Modules\Purchasing\Http\Resources\SupplierResource;
use Modules\Purchasing\Imports\SupplierImport;
use Modules\Purchasing\Models\Supplier;

class SupplierController extends Controller
{
    use Exportable, Filterable, ImportsSpreadsheets, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        $query = Supplier::query()->where('tenant_id', $request->user()->tenant_id);

        $this->applyFilters($request, $query, ['name', 'contact', 'email', 'phone', 'country', 'city']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Name', 'Country', 'City', 'Pay Terms', 'Currency', 'Balance', 'Status'],
            fn (Supplier $s) => [$s->id, $s->name, $s->country, $s->city, $s->pay_terms, $s->currency, $s->balance, $s->status],
            'suppliers',
        )) {
            return $export;
        }

        $suppliers = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    public function show(Request $request, string $id)
    {
        $supplier = Supplier::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $supplier);

        return new SupplierResource($supplier);
    }

    public function store(StoreSupplierRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $supplier = Supplier::create($data);

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    /**
     * A replaced or removed contract file is deleted once the change is saved.
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $oldContract = $supplier->contract_data;

        $supplier->update($request->validated());

        if ($oldContract !== null && $oldContract !== $supplier->contract_data) {
            Storage::delete($oldContract);
        }

        return new SupplierResource($supplier);
    }

    /**
     * Step one of attaching a contract: store the file and hand back its
     * path, which the client then sends as `contract_data` on create/update.
     */
    public function uploadContract(UploadSupplierContractRequest $request)
    {
        $contract = $request->file('contract');
        $path = $contract->store(Supplier::contractDirectory($request->user()->tenant_id));

        return response()->json(['data' => [
            'contract_data' => $path,
            'original_name' => $contract->getClientOriginalName(),
            'mime_type' => $contract->getMimeType(),
            'size' => $contract->getSize(),
        ]], 201);
    }

    public function downloadContract(Request $request, string $id)
    {
        $supplier = Supplier::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $supplier);

        abort_if($supplier->contract_data === null || ! Storage::exists($supplier->contract_data), 404, 'This supplier has no contract.');

        return Storage::download($supplier->contract_data, "contract-{$supplier->id}.".pathinfo($supplier->contract_data, PATHINFO_EXTENSION));
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        return $this->destroyRecord($request, $supplier, 'purchasing', 'Supplier');
    }

    public function import(Request $request)
    {
        $this->authorize('create', Supplier::class);

        return $this->runImport($request, new SupplierImport($request->user()));
    }

    public function importTemplate(Request $request)
    {
        $this->authorize('create', Supplier::class);

        return $this->templateResponse(new SupplierImport($request->user()), 'suppliers');
    }
}
