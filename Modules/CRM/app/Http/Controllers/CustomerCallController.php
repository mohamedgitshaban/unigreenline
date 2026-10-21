<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Http\Requests\StoreCustomerCallRequest;
use Modules\CRM\Http\Requests\UpdateCustomerCallRequest;
use Modules\CRM\Http\Resources\CustomerCallResource;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerCall;

/**
 * The call log: what a rep said to (or heard from) a customer on the phone. Each user sees the calls
 * they logged, those logged by everyone who reports to them, and those on customers they or their team own.
 */
class CustomerCallController extends Controller
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
        $this->authorize('viewAny', CustomerCall::class);

        $query = CustomerCall::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->visibleTo($request->user())
            ->with(self::RELATIONS);

        $query->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->filled('rep_id'), fn ($q) => $q->where('rep_id', $request->query('rep_id')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->query('direction')))
            ->when($request->filled('outcome'), fn ($q) => $q->where('outcome', $request->query('outcome')))
            ->when($request->filled('from'), fn ($q) => $q->where('called_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('called_at', '<=', $request->date('to')->endOfDay()));

        $this->applyFilters($request, $query, ['subject', 'message', 'customer.name', 'rep.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Customer', 'Rep', 'Called At', 'Direction', 'Outcome', 'Duration (s)', 'Subject', 'Message', 'Next Follow-up'],
            fn (CustomerCall $c) => [$c->id, $c->customer?->name, $c->rep?->name, $c->called_at->toDateTimeString(), $c->direction, $c->outcome, $c->duration_seconds, $c->subject, $c->message, $c->next_follow_up?->toDateString()],
            'calls',
        )) {
            return $export;
        }

        return CustomerCallResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    /**
     * A customer's calls as a paginated list (GET /customers/{id}/calls).
     */
    public function forCustomer(Request $request, string $id)
    {
        $customer = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $customer);

        $query = CustomerCall::query()
            ->where('customer_id', $customer->id)
            ->visibleTo($request->user())
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['subject', 'message', 'rep.name']);

        $this->applySorting($request, $query);

        return CustomerCallResource::collection($query->paginate($request->integer('per_page', 10))->withQueryString());
    }

    public function show(Request $request, string $id)
    {
        $call = CustomerCall::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $call);

        return new CustomerCallResource($call);
    }

    public function store(StoreCustomerCallRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['rep_id'] ??= $request->user()->id;
        $data['called_at'] ??= now();
        $data['direction'] ??= 'outbound';

        $call = CustomerCall::create($data);

        $this->recordAudit($request, 'crm', 'CustomerCall', $call, 'INSERT', null, [
            'customer_id' => $call->customer_id, 'rep_id' => $call->rep_id, 'subject' => $call->subject, 'message' => $call->message,
        ]);

        return (new CustomerCallResource($call->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerCallRequest $request, CustomerCall $call)
    {
        $this->updateRecord($request, $call, $request->validated(), 'crm', 'CustomerCall');

        return new CustomerCallResource($call->load(self::RELATIONS));
    }

    public function destroy(Request $request, CustomerCall $call)
    {
        return $this->destroyRecord($request, $call, 'crm', 'CustomerCall');
    }
}
