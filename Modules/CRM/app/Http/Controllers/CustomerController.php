<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ImportsSpreadsheets;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\Core\Support\DataScope;
use Modules\CRM\Exceptions\DuplicateCustomerException;
use Modules\CRM\Http\Requests\StoreCustomerRequest;
use Modules\CRM\Http\Requests\UpdateCustomerRequest;
use Modules\CRM\Http\Resources\CustomerResource;
use Modules\CRM\Imports\CustomerImport;
use Modules\CRM\Models\Customer;
use Modules\CRM\Services\CustomerDuplicateFinder;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;

class CustomerController extends Controller
{
    use Exportable, Filterable, ImportsSpreadsheets, ManagesRecords, Sortable;

    private const RELATIONS = [
        'salesRep',
        'salesRep.roles.permissions',
        'salesRep.permissions',
    ];

    public function __construct(private readonly AuditLogService $auditLog, private readonly DataScope $scope, private readonly CustomerDuplicateFinder $duplicates) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Customer::class);

        $query = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $this->scope->constrain($query, $request->user(), 'sales_rep_id');

        $this->withRelationsAndCounts($query, $request->user());

        $this->applyFilters($request, $query, ['name', 'phone', 'email', 'governorate', 'province', 'city', 'area', 'address']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Name', 'Type', 'Classification', 'Phone', 'Email', 'City', 'Credit Limit', 'Balance', 'Status'],
            fn (Customer $c) => [$c->id, $c->name, $c->type, $c->classification, $c->phone, $c->email, $c->city, $c->credit_limit, $c->balance, $c->status],
            'customers',
        )) {
            return $export;
        }

        $customers = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function show(Request $request, string $id)
    {
        $customer = $this->findForTenant($request, $id);

        $this->authorize('view', $customer);

        return new CustomerResource($customer);
    }

    /**
     * Customers that look like the one being typed in (GET /customers/check-duplicate), and whose they are.
     */
    public function checkDuplicate(Request $request)
    {
        $this->authorize('create', Customer::class);

        $matches = $this->duplicates->matches($request->user()->tenant_id, $request->only(['name', 'phone', 'email', 'city']));

        return response()->json(['data' => [
            'has_duplicates' => $matches->isNotEmpty(),
            'can_override' => $this->duplicates->canOverride($matches, $request->user()),
            'duplicates' => $this->duplicates->present($matches, $request->user()),
        ]]);
    }

    /**
     * Gives the customer to another user, or takes it for yourself (POST /customers/{id}/assign).
     * A user who sees everything can give it to anyone or to no one; everyone else only to themselves or their team.
     */
    public function assign(Request $request, string $id)
    {
        $customer = $this->findForTenant($request, $id);

        $this->authorize('assign', $customer);

        $data = $request->validate([
            'sales_rep_id' => ['present', 'nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $target = $data['sales_rep_id'];

        if ($target === null && $this->scope->isTeamScoped($request->user())) {
            throw ValidationException::withMessages(['sales_rep_id' => 'Choose yourself or a member of your team.']);
        }

        if ($target !== null && ! $this->scope->canAssignTo($request->user(), $target)) {
            throw ValidationException::withMessages(['sales_rep_id' => 'You can only assign customers to yourself or a member of your team.']);
        }

        $previous = $customer->sales_rep_id;
        $customer->update(['sales_rep_id' => $target]);

        if ($previous !== $target) {
            $this->auditLog->record([
                'module' => 'crm',
                'entity_type' => 'Customer',
                'entity_id' => $customer->id,
                'operation' => 'UPDATE',
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'tenant_id' => $request->user()->tenant_id,
                'prev_values' => ['sales_rep_id' => $previous],
                'new_values' => ['sales_rep_id' => $target],
                'ip_address' => $request->ip(),
            ]);
        }

        return new CustomerResource($this->findForTenant($request, $customer->id));
    }

    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();
        unset($data['allow_duplicate']);
        $data['tenant_id'] = $request->user()->tenant_id;

        $matches = $this->duplicates->matches($data['tenant_id'], $data);

        if ($matches->isNotEmpty() && ! ($request->boolean('allow_duplicate') && $this->duplicates->canOverride($matches, $request->user()))) {
            throw new DuplicateCustomerException($this->duplicates->present($matches, $request->user()), $this->duplicates->canOverride($matches, $request->user()));
        }
        if ($this->scope->isTeamScoped($request->user())) {
            $data['sales_rep_id'] ??= $request->user()->id;
        }

        $customer = Customer::create($data);

        $this->auditLog->record([
            'module' => 'crm',
            'entity_type' => 'Customer',
            'entity_id' => $customer->id,
            'operation' => 'INSERT',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'new_values' => ['name' => $customer->name, 'type' => $customer->type],
            'ip_address' => $request->ip(),
        ]);

        return (new CustomerResource($this->findForTenant($request, $customer->id)))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $data = $request->validated();
        $prevValues = $customer->only(array_keys($data));

        $customer->update($data);

        $this->auditLog->record([
            'module' => 'crm',
            'entity_type' => 'Customer',
            'entity_id' => $customer->id,
            'operation' => 'UPDATE',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'prev_values' => $prevValues,
            'new_values' => $customer->only(array_keys($data)),
            'ip_address' => $request->ip(),
        ]);

        return new CustomerResource($this->findForTenant($request, $customer->id));
    }

    private function findForTenant(Request $request, string $id): Customer
    {
        $query = Customer::query()->where('tenant_id', $request->user()->tenant_id);

        return $this->withRelationsAndCounts($query, $request->user())->findOrFail($id);
    }

    /**
     * orders/invoices aren't relations on Customer (see CustomerResource),
     * so their counts are subqueries. A team-scoped user's counts match what
     * GET /customers/{id}/orders and /invoices return to them.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function withRelationsAndCounts(Builder $query, User $user): Builder
    {
        $ordersCount = SalesOrder::query()
            ->selectRaw('count(*)')
            ->whereColumn('sales_orders.customer_id', 'customers.id')
            ->visibleTo($user);

        $invoicesCount = Invoice::query()
            ->selectRaw('count(*)')
            ->whereColumn('invoices.customer_id', 'customers.id')
            ->tap(fn ($query) => $this->scope->constrainThrough($query, $user, 'salesOrder', 'sales_rep_id'));

        return $query->with(self::RELATIONS)
            ->withCount('visits')
            ->addSelect(['orders_count' => $ordersCount, 'invoices_count' => $invoicesCount]);
    }

    public function destroy(Request $request, Customer $customer)
    {
        return $this->destroyRecord($request, $customer, 'crm', 'Customer');
    }

    public function import(Request $request)
    {
        $this->authorize('create', Customer::class);

        return $this->runImport($request, new CustomerImport($request->user()));
    }

    public function importTemplate(Request $request)
    {
        $this->authorize('create', Customer::class);

        return $this->templateResponse(new CustomerImport($request->user()), 'customers');
    }
}
