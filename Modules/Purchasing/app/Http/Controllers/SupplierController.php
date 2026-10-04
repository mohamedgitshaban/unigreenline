<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Purchasing\Http\Requests\StoreSupplierRequest;
use Modules\Purchasing\Http\Requests\UpdateSupplierRequest;
use Modules\Purchasing\Http\Resources\SupplierResource;
use Modules\Purchasing\Models\Supplier;

class SupplierController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        $query = Supplier::query()->where('tenant_id', $request->user()->tenant_id);

        $this->applyFilters($request, $query, ['name', 'contact', 'email', 'phone', 'country', 'city']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, ['purchasing.export', 'accounting.export'], $query,
            ['ID', 'Name', 'Country', 'City', 'Pay Terms', 'Currency', 'Balance', 'Status'],
            fn (Supplier $s) => [$s->id, $s->name, $s->country, $s->city, $s->pay_terms, $s->currency, $s->balance, $s->status],
            'suppliers',
        )) {
            return $export;
        }

        $suppliers = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    public function store(StoreSupplierRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $supplier = Supplier::create($data);

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }
}
