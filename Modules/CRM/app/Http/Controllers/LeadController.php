<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\CRM\Http\Requests\StoreLeadRequest;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Models\Lead;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $leads = Lead::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest()
            ->paginate($request->integer('per_page', 15));

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
