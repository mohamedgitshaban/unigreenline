<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\CRM\Http\Requests\ResolveComplaintRequest;
use Modules\CRM\Http\Requests\StoreComplaintRequest;
use Modules\CRM\Http\Resources\ComplaintResource;
use Modules\CRM\Models\Complaint;

class ComplaintController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Complaint::class);

        $complaints = Complaint::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('complaint_date')
            ->paginate($request->integer('per_page', 15));

        return ComplaintResource::collection($complaints);
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
}
