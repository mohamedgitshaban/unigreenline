<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Http\Requests\StoreCustomerVisitRequest;
use Modules\CRM\Http\Resources\CustomerVisitResource;
use Modules\CRM\Models\CustomerVisit;

class CustomerVisitController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', CustomerVisit::class);

        $query = CustomerVisit::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with([
            'customer',
            'customer.salesRep',
            'customer.salesRep.roles.permissions',
            'customer.salesRep.permissions',
            'rep',
            'rep.roles.permissions',
            'rep.permissions',
        ]);

        $this->applyFilters($request, $query, ['type', 'notes', 'customer.name', 'rep.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'crm.export', $query,
            ['ID', 'Customer', 'Rep', 'Visit Date', 'Type', 'Outcome', 'Next Visit'],
            fn (CustomerVisit $v) => [$v->id, $v->customer_id, $v->rep_id, $v->visit_date->toDateString(), $v->type, $v->outcome, $v->next_visit?->toDateString()],
            'visits',
        )) {
            return $export;
        }

        $visits = $query->paginate($request->integer('per_page', 15))->withQueryString();

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
