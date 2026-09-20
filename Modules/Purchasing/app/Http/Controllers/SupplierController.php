<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Purchasing\Http\Requests\StoreSupplierRequest;
use Modules\Purchasing\Http\Resources\SupplierResource;
use Modules\Purchasing\Models\Supplier;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = Supplier::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->paginate($request->integer('per_page', 15));

        return SupplierResource::collection($suppliers);
    }

    public function store(StoreSupplierRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $supplier = Supplier::create($data);

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }
}
