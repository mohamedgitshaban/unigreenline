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
use Modules\Manufacturing\Http\Resources\BomResource;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Services\SaveBomService;

class BomController extends Controller
{
    use Exportable, Filterable, ManagesRecords, Sortable;

    private const RELATIONS = ['product', 'lines.component'];

    public function __construct(private readonly SaveBomService $saveBom) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Bom::class);

        $query = Bom::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS);

        $this->applyFilters($request, $query, ['name', 'notes', 'product.name', 'product.sku']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'manufacturing.export', $query,
            ['ID', 'Name', 'Finished Product', 'Output Cartons', 'Conversion Cost', 'Components', 'Active'],
            fn (Bom $b) => [$b->id, $b->name, $b->product?->name, $b->output_qty_cartons, $b->conversion_cost, $b->lines->count(), $b->active ? 'Yes' : 'No'],
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
