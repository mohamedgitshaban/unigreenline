<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Http\Requests\StoreLeadRequest;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Models\Lead;

class LeadController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $query = Lead::query()->where('tenant_id', $request->user()->tenant_id);

        $query->with([
            'assignedTo',
            'assignedTo.roles.permissions',
            'assignedTo.permissions',
        ]);

        $this->applyFilters($request, $query, ['name', 'contact', 'phone', 'email', 'source', 'notes']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'crm.export', $query,
            ['ID', 'Name', 'Type', 'Contact', 'Phone', 'Email', 'Source', 'Status', 'Value'],
            fn (Lead $l) => [$l->id, $l->name, $l->type, $l->contact, $l->phone, $l->email, $l->source, $l->status, $l->value],
            'leads',
        )) {
            return $export;
        }

        $leads = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return LeadResource::collection($leads);
    }

    public function store(StoreLeadRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $lead = Lead::create($data);

        return (new LeadResource($lead))->response()->setStatusCode(201);
    }
}
