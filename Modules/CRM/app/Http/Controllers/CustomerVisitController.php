<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\CRM\Http\Requests\StoreCustomerVisitRequest;
use Modules\CRM\Http\Resources\CustomerVisitResource;
use Modules\CRM\Models\CustomerVisit;

class CustomerVisitController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', CustomerVisit::class);

        $visits = CustomerVisit::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('visit_date')
            ->paginate($request->integer('per_page', 15));

        return CustomerVisitResource::collection($visits);
    }

    public function store(StoreCustomerVisitRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['rep_id'] ??= $request->user()->id;

        $visit = CustomerVisit::create($data);

        return (new CustomerVisitResource($visit))->response()->setStatusCode(201);
    }
}
