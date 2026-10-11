<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Http\Requests\ResolveComplaintRequest;
use Modules\CRM\Http\Requests\StoreComplaintRequest;
use Modules\CRM\Http\Requests\UpdateComplaintRequest;
use Modules\CRM\Http\Resources\ComplaintResource;
use Modules\CRM\Models\Complaint;

class ComplaintController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = [
        'customer',
        'customer.salesRep',
        'customer.salesRep.roles.permissions',
        'customer.salesRep.permissions',
        'product',
        'product.category',
        'product.supplier',
        'assignedTo',
        'assignedTo.roles.permissions',
        'assignedTo.permissions',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Complaint::class);

        $query = Complaint::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'batch_no', 'type', 'description', 'customer.name', 'product.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'crm.export', $query,
            ['ID', 'Customer', 'Product', 'Type', 'Description', 'Priority', 'Status', 'Complaint Date', 'Resolved Date'],
            fn (Complaint $c) => [$c->id, $c->customer_id, $c->product_id, $c->type, $c->description, $c->priority, $c->status, $c->complaint_date->toDateString(), $c->resolved_date?->toDateString()],
            'complaints',
        )) {
            return $export;
        }

        $complaints = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return ComplaintResource::collection($complaints);
    }

    public function show(Request $request, string $id)
    {
        $complaint = Complaint::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $complaint);

        return new ComplaintResource($complaint);
    }

    public function store(StoreComplaintRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['complaint_date'] ??= now()->toDateString();

        $complaint = Complaint::create($data);

        return (new ComplaintResource($complaint))->response()->setStatusCode(201);
    }

    public function resolve(ResolveComplaintRequest $request, Complaint $complaint)
    {
        $complaint->update([
            'resolution' => $request->validated('resolution'),
            'status' => 'resolved',
            'resolved_date' => now()->toDateString(),
        ]);

        return new ComplaintResource($complaint);
    }

    public function update(UpdateComplaintRequest $request, Complaint $complaint)
    {
        $this->updateRecord($request, $complaint, $request->validated(), 'crm', 'Complaint');

        return new ComplaintResource($complaint->load(self::RELATIONS));
    }

    public function destroy(Request $request, Complaint $complaint)
    {
        return $this->destroyRecord($request, $complaint, 'crm', 'Complaint');
    }
}
