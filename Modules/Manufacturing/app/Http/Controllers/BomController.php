<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Manufacturing\Http\Requests\StoreBomRequest;
use Modules\Manufacturing\Http\Requests\UpdateBomRequest;
use Modules\Inventory\Models\Product;
use Modules\Manufacturing\Http\Resources\BomResource;
use Modules\Manufacturing\Http\Resources\BomRevisionResource;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomRevision;
use Modules\Manufacturing\Services\BomLifecycleService;
use Modules\Manufacturing\Services\SaveBomService;
use Modules\Manufacturing\Support\BomStructure;

class BomController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = ['product', 'routing', 'lines.component', 'byProducts.product'];

    public function __construct(
        private readonly SaveBomService $saveBom,
        private readonly BomLifecycleService $lifecycle,
        private readonly BomStructure $structure,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Bom::class);

        $query = Bom::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['code', 'name', 'notes', 'product.name', 'product.sku']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, $query,
            ['Code', 'Name', 'Version', 'Status', 'Finished Product', 'Output Cartons', 'Conversion Cost', 'Components', 'Active'],
            fn (Bom $b) => [$b->code, $b->name, $b->version, $b->status, $b->product?->name, $b->output_qty_cartons, $b->conversion_cost, $b->lines->count(), $b->active ? 'Yes' : 'No'],
            'boms',
        )) {
            return $export;
        }

        return BomResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        $this->authorize('view', $bom);

        return new BomResource($bom->load(self::RELATIONS));
    }

    public function store(StoreBomRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $bom = $this->saveBom->create($data, $this->actor($request));

        return (new BomResource($bom->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateBomRequest $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        $bom = $this->saveBom->update($bom, $request->validated(), $this->actor($request));

        return new BomResource($bom->load(self::RELATIONS));
    }

    /**
     * A BOM that production orders were raised from can't be deleted (409);
     * retire it with `active: false` instead.
     */
    public function destroy(Request $request, Bom $bom)
    {
        return $this->destroyRecord($request, $bom, 'manufacturing', 'Bom');
    }

    /**
     * Draft → approved: new orders may be raised from it.
     */
    public function approve(Request $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        return new BomResource($this->lifecycle->approve($bom, $this->actor($request))->load(self::RELATIONS));
    }

    public function archive(Request $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        return new BomResource($this->lifecycle->archive($bom, $this->actor($request))->load(self::RELATIONS));
    }

    public function restore(Request $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        return new BomResource($this->lifecycle->restore($bom, $this->actor($request))->load(self::RELATIONS));
    }

    public function revisions(Request $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        return BomRevisionResource::collection(
            $bom->revisions()->getQuery()->with('createdBy')->orderByDesc('revision_no')->paginate($request->integer('per_page', 15))->withQueryString()
        );
    }

    public function revision(Request $request, Bom $bom, BomRevision $revision)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id && $revision->bom_id === $bom->id, 404);

        return new BomRevisionResource($revision->load('createdBy'));
    }

    /**
     * Cuts a new version of the approved recipe, with a note saying why.
     */
    public function createRevision(Request $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate(['change_note' => ['nullable', 'string', 'max:255']]);

        $revision = $this->lifecycle->revise($bom, $data['change_note'] ?? null, $this->actor($request));

        return (new BomRevisionResource($revision->load('createdBy')))->response()->setStatusCode(201);
    }

    /**
     * Everything needed to make `qty` cartons of the product — sub-assemblies
     * expanded through their own recipes, scrap allowances included.
     */
    public function components(Request $request, Bom $bom)
    {
        abort_unless($bom->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate(['qty' => ['sometimes', 'numeric', 'min:0.0001', 'max:1000000']]);
        $qty = (float) ($data['qty'] ?? $bom->output_qty_cartons);

        $rows = $this->structure->explode($bom, $qty);
        $products = Product::query()->whereIn('id', array_unique(array_column($rows, 'product_id')))->get(['id', 'name', 'sku'])->keyBy('id');

        return response()->json(['data' => [
            'bom_id' => $bom->id,
            'output_qty_cartons' => $qty,
            'components' => array_map(fn (array $row) => [
                'level' => $row['level'],
                'kind' => $row['kind'],
                'product' => ['id' => $row['product_id'], 'name' => $products[$row['product_id']]->name ?? null, 'sku' => $products[$row['product_id']]->sku ?? null],
                'parent_product_id' => $row['parent_product_id'],
                'qty_cartons' => $row['qty'],
                'required_cartons' => (int) ceil($row['qty'] - 1e-9),
                'optional' => $row['optional'],
                'bom_id' => $row['bom_id'],
            ], $rows),
        ]]);
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
