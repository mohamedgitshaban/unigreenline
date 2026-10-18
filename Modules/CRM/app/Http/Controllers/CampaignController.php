<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\CRM\Http\Requests\StoreCampaignRequest;
use Modules\CRM\Http\Requests\UpdateCampaignRequest;
use Modules\CRM\Http\Resources\CampaignResource;
use Modules\CRM\Models\Campaign;

class CampaignController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = [
        'createdBy',
        'createdBy.roles.permissions',
        'createdBy.permissions',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Campaign::class);

        $query = Campaign::query()
            ->where('tenant_id', $request->user()->tenant_id);

        $query->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['name', 'type', 'target', 'description']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Name', 'Type', 'Target', 'Discount', 'Start Date', 'End Date', 'Status', 'Reach', 'Revenue'],
            fn (Campaign $c) => [$c->id, $c->name, $c->type, $c->target, $c->discount, $c->start_date->toDateString(), $c->end_date?->toDateString(), $c->status, $c->reach, $c->revenue],
            'campaigns',
        )) {
            return $export;
        }

        $campaigns = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return CampaignResource::collection($campaigns);
    }

    public function show(Request $request, string $id)
    {
        $campaign = Campaign::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->findOrFail($id);

        $this->authorize('view', $campaign);

        return new CampaignResource($campaign);
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

    public function update(UpdateCampaignRequest $request, Campaign $campaign)
    {
        $this->updateRecord($request, $campaign, $request->validated(), 'crm', 'Campaign');

        return new CampaignResource($campaign->load(self::RELATIONS));
    }

    public function destroy(Request $request, Campaign $campaign)
    {
        return $this->destroyRecord($request, $campaign, 'crm', 'Campaign');
    }
}
