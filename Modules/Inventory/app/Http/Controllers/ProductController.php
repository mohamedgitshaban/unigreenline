<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Http\Requests\StoreProductRequest;
use Modules\Inventory\Http\Requests\UpdateProductRequest;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Product;

class ProductController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->paginate($request->integer('per_page', 15));

        return ProductResource::collection($products);
    }

    public function show(Product $product)
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load('batches'));
    }

    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();
        $data['tenant_id'] = $request->user()->tenant_id;

        $product = Product::create($data);

        $this->auditLog->record([
            'module' => 'inventory',
            'entity_type' => 'Product',
            'entity_id' => $product->id,
            'operation' => 'INSERT',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'new_values' => $product->only(['name', 'sku']),
            'ip_address' => $request->ip(),
        ]);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $data = $request->validated();
        $prevValues = $product->only(array_keys($data));

        $product->update($data);

        $this->auditLog->record([
            'module' => 'inventory',
            'entity_type' => 'Product',
            'entity_id' => $product->id,
            'operation' => 'UPDATE',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'tenant_id' => $request->user()->tenant_id,
            'prev_values' => $prevValues,
            'new_values' => $product->only(array_keys($data)),
            'ip_address' => $request->ip(),
        ]);

        return new ProductResource($product);
    }
}
