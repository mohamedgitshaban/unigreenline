<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\ImportsSpreadsheets;
use Modules\Core\Http\Controllers\Concerns\ManagesRecords;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Http\Requests\StoreProductRequest;
use Modules\Inventory\Http\Requests\UpdateProductRequest;
use Modules\Inventory\Http\Resources\InventoryBatchResource;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Imports\ProductImport;
use Modules\Inventory\Models\Product;

class ProductController extends Controller
{
    use Exportable, Filterable, ImportsSpreadsheets, ManagesRecords, Sortable;

    private const RELATIONS = ['category', 'supplier'];

    private const BATCH_RELATIONS = [
        'warehouse',
        'warehouse.manager',
        'warehouse.manager.roles.permissions',
        'warehouse.manager.permissions',
    ];

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Product::class);

        $query = Product::query()->where('tenant_id', $request->user()->tenant_id);

        $query->with(self::RELATIONS)->withCount('batches');

        $this->applyFilters($request, $query, ['name', 'sku', 'brand', 'category.name', 'supplier.name']);

        $this->applySorting($request, $query);

        if ($export = $this->exportIfRequested(
            $request, 'inventory.export', $query,
            ['ID', 'SKU', 'Name', 'Brand', 'Pack Unit', 'Carton Qty', 'Cost Price', 'Selling Price', 'Tax %', 'Active'],
            fn (Product $p) => [$p->id, $p->sku, $p->name, $p->brand, $p->pack_unit, $p->carton_qty, $p->cost_price, $p->selling_price, $p->tax_pct, $p->active ? 'Yes' : 'No'],
            'products',
        )) {
            return $export;
        }

        $products = $query->paginate($request->integer('per_page', 15))->withQueryString();

        return ProductResource::collection($products);
    }

    public function show(Request $request, string $id)
    {
        $product = $this->findForTenant($request, $id);

        $this->authorize('view', $product);

        return new ProductResource($product);
    }

    /**
     * A product's batches as their own paginated list, so a product with
     * many batches doesn't have to return all of them from show.
     */
    public function batches(Request $request, string $id)
    {
        $product = Product::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->authorize('view', $product);

        $query = $product->batches()->getQuery()->with(self::BATCH_RELATIONS);

        $this->applyFilters($request, $query, ['batch_no', 'warehouse.name']);

        $this->applySorting($request, $query);

        $batches = $query->paginate($request->integer('per_page', 10))->withQueryString();

        return InventoryBatchResource::collection($batches);
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

        return (new ProductResource($this->findForTenant($request, $product->id)))->response()->setStatusCode(201);
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

        return new ProductResource($this->findForTenant($request, $product->id));
    }

    private function findForTenant(Request $request, string $id): Product
    {
        return Product::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(self::RELATIONS)
            ->withCount('batches')
            ->findOrFail($id);
    }

    public function destroy(Request $request, Product $product)
    {
        return $this->destroyRecord($request, $product, 'inventory', 'Product');
    }

    public function import(Request $request)
    {
        $this->authorize('create', Product::class);

        return $this->runImport($request, new ProductImport($request->user()));
    }

    public function importTemplate(Request $request)
    {
        $this->authorize('create', Product::class);

        return $this->templateResponse(new ProductImport($request->user()), 'products');
    }
}
