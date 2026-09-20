<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\StoreProductCategoryRequest;
use Modules\Inventory\Http\Resources\ProductCategoryResource;
use Modules\Inventory\Models\ProductCategory;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ProductCategory::class);

        $categories = ProductCategory::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->paginate($request->integer('per_page', 15));

        return ProductCategoryResource::collection($categories);
    }

    public function store(StoreProductCategoryRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $category = ProductCategory::create($data);

        return new ProductCategoryResource($category);
    }
}
