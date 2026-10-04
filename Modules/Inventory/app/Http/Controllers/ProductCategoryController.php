<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Inventory\Http\Requests\StoreProductCategoryRequest;
use Modules\Inventory\Http\Requests\UpdateProductCategoryRequest;
use Modules\Inventory\Http\Resources\ProductCategoryResource;
use Modules\Inventory\Models\ProductCategory;

class ProductCategoryController extends Controller
{
    use Exportable, Filterable, Sortable;

    public function index(Request $request)
    {
        $this->authorize('viewAny', ProductCategory::class);

        $query = ProductCategory::query()->where('tenant_id', $request->user()->tenant_id);

        $this->applyFilters($request, $query, ['name', 'code', 'description']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'inventory.export', $query,
            ['ID', 'Name', 'Code', 'Description', 'Active'],
            fn (ProductCategory $c) => [$c->id, $c->name, $c->code, $c->description, $c->active ? 'Yes' : 'No'],
            'categories',
        )) {
            return $export;
        }

        $categories = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return ProductCategoryResource::collection($categories);
    }

    public function store(StoreProductCategoryRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;
        $category = ProductCategory::create($data);

        return new ProductCategoryResource($category);
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category)
    {
        $category->update($request->validated());

        return new ProductCategoryResource($category);
    }
}
