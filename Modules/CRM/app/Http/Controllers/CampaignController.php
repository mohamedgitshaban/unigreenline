<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Http\Requests\StoreCampaignRequest;
use Modules\CRM\Http\Resources\CampaignResource;
use Modules\CRM\Models\Campaign;

class CampaignController extends Controller
{
    use Exportable, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Campaign::class);

        $query = Campaign::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'crm.export', $query,
            ['ID', 'Name', 'Type', 'Target', 'Discount', 'Start Date', 'End Date', 'Status', 'Reach', 'Revenue'],
            fn (Campaign $c) => [$c->id, $c->name, $c->type, $c->target, $c->discount, $c->start_date->toDateString(), $c->end_date?->toDateString(), $c->status, $c->reach, $c->revenue],
            'campaigns',
        )) {
            return $export;
        }

        $campaigns = $query->paginate($request->integer('per_page', 15));

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
