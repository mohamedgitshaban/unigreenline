<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\CRM\Http\Requests\StoreCampaignRequest;
use Modules\CRM\Http\Resources\CampaignResource;
use Modules\CRM\Models\Campaign;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Campaign::class);

        $campaigns = Campaign::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('start_date')
            ->paginate($request->integer('per_page', 15));

        return CampaignResource::collection($campaigns);
    }

    public function store(StoreCampaignRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;

        $campaign = Campaign::create($data);

        return (new CampaignResource($campaign))->response()->setStatusCode(201);
    }

    public function end(Request $request, Campaign $campaign)
    {
        $this->authorize('end', $campaign);

        $campaign->update([
            'status' => 'completed',
            'end_date' => $campaign->end_date ?? now()->toDateString(),
        ]);

        return new CampaignResource($campaign);
    }
}
