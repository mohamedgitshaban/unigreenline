<?php

namespace Modules\Recap\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Recap\Http\Requests\GenerateExpiredRecapsRequest;
use Modules\Recap\Http\Requests\RejectRecapRequest;
use Modules\Recap\Http\Requests\StoreRecapRequest;
use Modules\Recap\Http\Requests\UpdateRecapRequest;
use Modules\Recap\Http\Resources\RecapResource;
use Modules\Recap\Models\Recap;
use Modules\Recap\Services\CreateRecapService;
use Modules\Recap\Services\DeleteRecapService;
use Modules\Recap\Services\ExpiredRecapGenerator;
use Modules\Recap\Services\RecapApprovalService;
use Modules\Recap\Services\UpdateRecapService;

class RecapController extends Controller
{
    use Exportable, Filterable, Sortable;

    private const RELATIONS = ['warehouse', 'lines.product', 'createdBy', 'approvedBy'];

    public function __construct(
        private readonly CreateRecapService $createRecap,
        private readonly UpdateRecapService $updateRecap,
        private readonly DeleteRecapService $deleteRecap,
        private readonly RecapApprovalService $recapApproval,
        private readonly ExpiredRecapGenerator $expiredGenerator,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Recap::class);

        $query = Recap::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['id', 'notes', 'warehouse.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['ID', 'Date', 'Warehouse', 'Reason', 'Status', 'Total Value', 'Notes'],
            fn (Recap $r) => [$r->id, $r->recap_date->toDateString(), $r->warehouse?->name, $r->reason, $r->status, $r->total_value, $r->notes],
            'recaps',
        )) {
            return $export;
        }

        return RecapResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, Recap $recap)
    {
        abort_unless($recap->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('view', $recap);

        return new RecapResource($recap->load(self::RELATIONS));
    }

    /**
     * Batches that have passed their expiry date and still hold stock —
     * what a write-off recap would pick up.
     */
    public function expiredBatches(Request $request)
    {
        $this->authorize('viewAny', Recap::class);

        $request->validate(['warehouse_id' => ['sometimes', 'string']]);

        $batches = InventoryBatch::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->query('warehouse_id'), fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->where('qty_cartons', '>', 0)
            ->whereDate('exp_date', '<', now()->toDateString())
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->orderBy('exp_date')
            ->get();

        return response()->json(['data' => $batches->map(fn (InventoryBatch $batch) => [
            'batch_id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'product' => ['id' => $batch->product->id, 'name' => $batch->product->name, 'sku' => $batch->product->sku],
            'warehouse' => ['id' => $batch->warehouse->id, 'name' => $batch->warehouse->name],
            'exp_date' => $batch->exp_date->toDateString(),
            'qty_cartons' => $batch->qty_cartons,
            'cost_per_carton' => $batch->cost_per_carton,
            'value' => round($batch->qty_cartons * (float) $batch->cost_per_carton, 2),
        ])->values()]);
    }

    public function store(StoreRecapRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['created_by'] = $request->user()->id;

        $recap = $this->createRecap->create($data, $this->actor($request));

        return (new RecapResource($recap->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Drafts one recap per warehouse for all expired stock (optionally one
     * warehouse). 200 with an empty list when nothing new has expired.
     */
    public function generateFromExpired(GenerateExpiredRecapsRequest $request)
    {
        $recaps = $this->expiredGenerator->generate(
            $request->user()->tenant_id,
            $request->validated('warehouse_id'),
            $request->user()->id,
            $this->actor($request),
        );

        return RecapResource::collection($recaps->each->load(self::RELATIONS))->response()->setStatusCode($recaps->isEmpty() ? 200 : 201);
    }

    public function update(UpdateRecapRequest $request, Recap $recap)
    {
        abort_unless($recap->tenant_id === $request->user()->tenant_id, 404);

        $recap = $this->updateRecap->update($recap, $request->validated(), $this->actor($request));

        return new RecapResource($recap->load(self::RELATIONS));
    }

    public function destroy(Request $request, Recap $recap)
    {
        abort_unless($recap->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('delete', $recap);

        $this->deleteRecap->delete($recap, $this->actor($request));

        return response()->noContent();
    }

    public function approve(Request $request, Recap $recap)
    {
        abort_unless($recap->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('approve', $recap);

        $recap = $this->recapApproval->approve($recap, $this->actor($request));

        return new RecapResource($recap->load(self::RELATIONS));
    }

    public function reject(RejectRecapRequest $request, Recap $recap)
    {
        $recap = $this->recapApproval->reject($recap, $request->validated('reason'), $this->actor($request));

        return new RecapResource($recap->load(self::RELATIONS));
    }

    /**
     * @return array{actor_id: string, actor_name: string, ip_address: ?string}
     */
    private function actor(Request $request): array
    {
        return [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'ip_address' => $request->ip(),
        ];
    }
}
