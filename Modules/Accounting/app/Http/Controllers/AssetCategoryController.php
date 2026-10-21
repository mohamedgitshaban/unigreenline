<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\StoreAssetCategoryRequest;
use Modules\Accounting\Http\Resources\AssetCategoryResource;
use Modules\Accounting\Models\AssetCategory;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;

/**
 * Asset models: how each kind of fixed asset is booked and depreciated by default.
 */
class AssetCategoryController extends Controller
{
    use Filterable, ManagesRecords, Sortable;

    public function index(Request $request)
    {
        $query = AssetCategory::query()->where('tenant_id', $request->user()->tenant_id)->withCount('assets');

        $this->applyFilters($request, $query, ['name', 'description']);
        $this->applySorting($request, $query);

        return AssetCategoryResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function show(Request $request, string $id)
    {
        return new AssetCategoryResource(AssetCategory::query()->where('tenant_id', $request->user()->tenant_id)->withCount('assets')->findOrFail($id));
    }

    public function store(StoreAssetCategoryRequest $request)
    {
        $category = AssetCategory::create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);

        $this->recordAudit($request, 'accounting', 'AssetCategory', $category, 'INSERT', null, ['name' => $category->name]);

        return (new AssetCategoryResource($category->refresh()))->response()->setStatusCode(201);
    }

    public function update(StoreAssetCategoryRequest $request, AssetCategory $category)
    {
        $this->updateRecord($request, $category, $request->validated(), 'accounting', 'AssetCategory');

        return new AssetCategoryResource($category->loadCount('assets'));
    }

    public function destroy(Request $request, AssetCategory $category)
    {
        return $this->destroyRecord($request, $category, 'accounting', 'AssetCategory');
    }
}
