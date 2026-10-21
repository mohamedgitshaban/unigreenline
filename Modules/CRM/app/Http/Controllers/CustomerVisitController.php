<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Http\Requests\StoreCustomerVisitRequest;
use Modules\CRM\Http\Requests\UpdateCustomerVisitRequest;
use Modules\CRM\Http\Resources\CustomerVisitResource;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerVisit;

class CustomerVisitController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = [
        'customer',
        'customer.salesRep',
        'customer.salesRep.roles.permissions',
        'customer.salesRep.permissions',
        'rep',
        'rep.roles.permissions',
        'rep.permissions',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', CustomerVisit::class);

        $query = CustomerVisit::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->visibleTo($request->user());

        $query->with(self::RELATIONS);

        $query->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->filled('rep_id'), fn ($q) => $q->where('rep_id', $request->query('rep_id')));

        $this->applyFilters($request, $query, ['type', 'notes', 'customer.name', 'rep.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Customer', 'Rep', 'Visit Date', 'Type', 'Outcome', 'Next Visit'],
            fn (CustomerVisit $v) => [$v->id, $v->customer_id, $v->rep_id, $v->visit_date->toDateString(), $v->type, $v->outcome, $v->next_visit?->toDateString()],
            'visits',
        )) {
            return $export;
        }

        $visits = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return CustomerVisitResource::collection($visits);
    }

    /**
     * A customer's visits as a paginated list (GET /customers/{id}/visits).
     */
    public function forCustomer(Request $request, string $id)
    {
        $customer = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $customer);

        $query = CustomerVisit::query()
            ->where('customer_id', $customer->id)
            ->visibleTo($request->user())
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['type', 'notes', 'rep.name']);

        $this->applySorting($request, $query);

        $visits = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return CustomerVisitResource::collection($visits);
    }

    public function show(Request $request, string $id)
    {
        $visit = CustomerVisit::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $visit);

        return new CustomerVisitResource($visit);
    }

    public function store(StoreCustomerVisitRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['rep_id'] ??= $request->user()->id;

        $visit = CustomerVisit::create($data);

        $this->recordAudit($request, 'crm', 'CustomerVisit', $visit, 'INSERT', null, [
            'customer_id' => $visit->customer_id, 'rep_id' => $visit->rep_id, 'type' => $visit->type, 'notes' => $visit->notes,
        ]);

        return (new CustomerVisitResource($visit))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerVisitRequest $request, CustomerVisit $visit)
    {
        $this->updateRecord($request, $visit, $request->validated(), 'crm', 'CustomerVisit');

        return new CustomerVisitResource($visit->load(self::RELATIONS));
    }

    public function destroy(Request $request, CustomerVisit $visit)
    {
        return $this->destroyRecord($request, $visit, 'crm', 'CustomerVisit');
    }
}
