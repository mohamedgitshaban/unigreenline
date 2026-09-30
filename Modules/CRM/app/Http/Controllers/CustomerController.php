<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Services\AuditLogService;
use Modules\CRM\Http\Requests\StoreCustomerRequest;
use Modules\CRM\Http\Requests\UpdateCustomerRequest;
use Modules\CRM\Http\Resources\CustomerResource;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;

class CustomerController extends Controller
{
    use Exportable;

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Customer::class);

        $query = Customer::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when(
                $request->user()->hasRole('Sales Rep'),
                fn ($query) => $query->where('sales_rep_id', $request->user()->id)
            );

        if ($export = $this->exportIfRequested(
            $request, 'crm.export', $query,
            ['ID', 'Name', 'Type', 'Classification', 'Phone', 'Email', 'City', 'Credit Limit', 'Balance', 'Status'],
            fn (Customer $c) => [$c->id, $c->name, $c->type, $c->classification, $c->phone, $c->email, $c->city, $c->credit_limit, $c->balance, $c->status],
            'customers',
        )) {
            return $export;
        }

        $customers = $query->paginate($request->integer('per_page', 15));

        return CustomerResource::collection($customers);
    }

    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);

        $customer->load('visits');
        $customer->setAttribute('orders', SalesOrder::query()->where('customer_id', $customer->id)->get());
        $customer->setAttribute('invoices', Invoice::query()->where('customer_id', $customer->id)->get());

        return new CustomerResource($customer);
    }

    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        if ($request->user()->hasRole('Sales Rep')) {
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

        return (new CustomerResource($customer))->response()->setStatusCode(201);
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

        return new CustomerResource($customer);
    }
}
