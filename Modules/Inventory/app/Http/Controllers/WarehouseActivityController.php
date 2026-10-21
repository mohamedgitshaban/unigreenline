<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Concerns\Exportable;
use Modules\Core\Http\Controllers\Concerns\Filterable;
use Modules\Core\Http\Controllers\Concerns\Sortable;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\WarehouseAccess;
use Modules\Sales\Support\CartonCalculator;

/**
 * A warehouse seen by the people who run it: who works there, what is on its way in (purchase orders),
 * what is due to go out (sales orders), the ledger of everything that came in and went out, and a
 * one-page overview. Every query is limited to the warehouses the caller may see, and none shows prices.
 */
class WarehouseActivityController extends Controller
{
    use Exportable, Filterable, Sortable;

    /** Purchase orders still to arrive. */
    private const OPEN_PURCHASE_STATUSES = ['draft', 'sent', 'pending', 'pending_pricing'];

    /** Sales orders that still need the warehouse: stock to pick, or goods to hand over. */
    private const OPEN_SALES_STATUSES = ['draft', 'picking', 'invoiced'];

    public function __construct(private readonly AuditLogService $auditLog, private readonly WarehouseAccess $access) {}

    public function users(Request $request, string $id)
    {
        $warehouse = $this->warehouse($request, $id);

        $users = $warehouse->users()->with('roles:id,name')->orderBy('name')->get();

        return response()->json(['data' => [
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
            'manager_id' => $warehouse->manager_id,
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name,
                'status' => $user->status,
                'is_manager' => $user->id === $warehouse->manager_id,
            ])->values(),
        ]]);
    }

    /**
     * Sets who works in the warehouse and, optionally, who manages it (PUT /warehouses/{id}/users).
     * The manager always works there too, so they are kept in the list and see the warehouse.
     */
    public function assignUsers(Request $request, string $id)
    {
        $warehouse = $this->warehouse($request, $id, 'update');
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['string', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'manager_id' => ['sometimes', 'nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        DB::transaction(function () use ($request, $warehouse, $data) {
            $previous = ['user_ids' => $warehouse->users()->pluck('users.id')->sort()->values()->all(), 'manager_id' => $warehouse->manager_id];

            if (array_key_exists('manager_id', $data)) {
                $warehouse->manager_id = $data['manager_id'];
                $warehouse->manager_name = $data['manager_id'] ? User::query()->whereKey($data['manager_id'])->value('name') : null;
            }

            $userIds = collect($data['user_ids'])->when($warehouse->manager_id, fn ($ids) => $ids->push($warehouse->manager_id))->unique()->sort()->values()->all();

            $warehouse->users()->sync($userIds);
            $warehouse->save();

            $this->auditLog->record([
                'module' => 'inventory',
                'entity_type' => 'Warehouse',
                'entity_id' => $warehouse->id,
                'operation' => 'UPDATE',
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'tenant_id' => $request->user()->tenant_id,
                'warehouse_id' => $warehouse->id,
                'prev_values' => $previous,
                'new_values' => ['user_ids' => $userIds, 'manager_id' => $warehouse->manager_id],
                'ip_address' => $request->ip(),
            ]);
        });

        return $this->users($request, $warehouse->id);
    }

    public function overview(Request $request, string $id)
    {
        $warehouse = $this->warehouse($request, $id);
        $stock = InventoryBatch::query()->where('warehouse_id', $warehouse->id)->where('qty_cartons', '>', 0);
        $today = today();

        $totals = (clone $stock)->selectRaw('count(distinct product_id) as products, count(*) as batches, coalesce(sum(qty_cartons), 0) as cartons, coalesce(sum(reserved_cartons), 0) as reserved')->first();

        $lowStock = Product::query()
            ->where('tenant_id', $warehouse->tenant_id)
            ->where('min_stock_cartons', '>', 0)
            ->whereRaw('coalesce((select sum(qty_cartons) from inventory_batches b where b.product_id = products.id and b.warehouse_id = ?), 0) < min_stock_cartons', [$warehouse->id])
            ->count();

        $movements = StockMovement::query()->where('warehouse_id', $warehouse->id)->where('type', '!=', 'opening_balance')->where('occurred_at', '>=', now()->subDays(7));
        $week = (clone $movements)->selectRaw('coalesce(sum(case when qty_cartons > 0 then qty_cartons end), 0) as received, coalesce(sum(case when qty_cartons < 0 then -qty_cartons end), 0) as issued')->first();

        $incoming = DB::table('purchase_orders')->where('warehouse_id', $warehouse->id)->whereIn('status', self::OPEN_PURCHASE_STATUSES);
        $outgoing = DB::table('sales_orders')->where('tenant_id', $warehouse->tenant_id)->whereIn('status', self::OPEN_SALES_STATUSES)
            ->where(fn ($query) => $query->where('warehouse_id', $warehouse->id)->orWhereExists(
                fn ($lines) => $lines->select(DB::raw(1))->from('sales_order_lines')->whereColumn('sales_order_lines.so_id', 'sales_orders.id')->where('sales_order_lines.warehouse_id', $warehouse->id)
            ));

        return response()->json(['data' => [
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name, 'city' => $warehouse->city, 'status' => $warehouse->status, 'manager_id' => $warehouse->manager_id, 'manager_name' => $warehouse->manager_name],
            'stock' => [
                'products' => (int) $totals->products,
                'batches' => (int) $totals->batches,
                'cartons' => (int) $totals->cartons,
                'reserved_cartons' => (int) $totals->reserved,
                'available_cartons' => (int) $totals->cartons - (int) $totals->reserved,
                'value' => $request->user()->hasRole('Administrator') ? $warehouse->stock_value : null,
            ],
            'alerts' => [
                'low_stock_products' => $lowStock,
                'expired_batches' => (clone $stock)->whereDate('exp_date', '<', $today)->count(),
                'expiring_within_30_days' => (clone $stock)->whereDate('exp_date', '>=', $today)->whereDate('exp_date', '<=', $today->copy()->addDays(30))->count(),
            ],
            'incoming' => [
                'open_purchase_orders' => (clone $incoming)->count(),
                'cartons_expected' => (int) DB::table('purchase_order_lines')->whereIn('po_id', (clone $incoming)->select('id'))->sum('qty_cartons'),
            ],
            'outgoing' => [
                'open_sales_orders' => (clone $outgoing)->count(),
                'to_prepare' => (clone $outgoing)->whereIn('status', ['draft', 'picking'])->count(),
                'to_deliver' => (clone $outgoing)->where('status', 'invoiced')->count(),
            ],
            'last_7_days' => ['received_cartons' => (int) $week->received, 'issued_cartons' => (int) $week->issued],
            'recent_movements' => StockMovementResource::collection(
                StockMovement::query()->where('warehouse_id', $warehouse->id)->with(['product:id,name,sku', 'user:id,name', 'counterpartWarehouse:id,name'])->orderByDesc('occurred_at')->orderByDesc('id')->limit(10)->get()
            )->resolve($request),
        ]]);
    }

    /**
     * Everything that came into and left the warehouse (GET /warehouses/{id}/movements).
     */
    public function movements(Request $request, string $id)
    {
        $warehouse = $this->warehouse($request, $id);

        return $this->movementList($request, StockMovement::query()->where('warehouse_id', $warehouse->id), 'warehouse-movements');
    }

    /**
     * A product's movements across the warehouses the caller may see (GET /products/{id}/movements).
     */
    public function productMovements(Request $request, string $id)
    {
        $product = Product::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorize('view', $product);

        $query = StockMovement::query()->where('product_id', $product->id);
        $visible = $this->access->idsFor($request->user());

        if ($visible !== null) {
            $query->whereIn('warehouse_id', $visible);
        }

        return $this->movementList($request, $query, 'product-movements');
    }

    /**
     * Purchase orders on their way to the warehouse (GET /warehouses/{id}/incoming), soonest expected first.
     * `?status=` narrows to given statuses (default: every order not yet received or cancelled), `?purchase_order_id=` to one order.
     */
    public function incoming(Request $request, string $id)
    {
        $warehouse = $this->warehouse($request, $id);

        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'purchase_order_id' => ['sometimes', 'string'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $statuses = isset($filters['status']) ? array_filter(explode(',', $filters['status'])) : self::OPEN_PURCHASE_STATUSES;

        $orders = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->where('po.tenant_id', $warehouse->tenant_id)
            ->where('po.warehouse_id', $warehouse->id)
            ->whereIn('po.status', $statuses)
            ->when($filters['purchase_order_id'] ?? null, fn ($query, $orderId) => $query->where('po.id', $orderId))
            ->when($filters['search'] ?? null, fn ($query, $term) => $query->where('s.name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))
            ->orderByRaw('po.expected_date is null, po.expected_date asc')
            ->orderBy('po.id')
            ->select(['po.id', 'po.status', 'po.order_date', 'po.expected_date', 'po.received_date', 'po.notes', 's.id as supplier_id', 's.name as supplier_name'])
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $lines = DB::table('purchase_order_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.po_id', $orders->pluck('id'))
            ->get(['l.po_id', 'l.product_id', 'p.name', 'p.sku', 'l.qty_cartons'])
            ->groupBy('po_id');

        return response()->json([
            'data' => $orders->getCollection()->map(fn ($order) => [
                'id' => $order->id,
                'status' => $order->status,
                'order_date' => $order->order_date,
                'expected_date' => $order->expected_date,
                'received_date' => $order->received_date,
                'notes' => $order->notes,
                'supplier' => ['id' => $order->supplier_id, 'name' => $order->supplier_name],
                'total_cartons' => (int) ($lines[$order->id] ?? collect())->sum('qty_cartons'),
                'lines' => ($lines[$order->id] ?? collect())->map(fn ($line) => [
                    'product' => ['id' => $line->product_id, 'name' => $line->name, 'sku' => $line->sku],
                    'qty_cartons' => (int) $line->qty_cartons,
                ])->values(),
            ])->values(),
            'meta' => $this->pagination($orders),
        ]);
    }

    /**
     * Sales orders that will take stock out of the warehouse (GET /warehouses/{id}/outgoing): the lines for this
     * warehouse only, oldest order first. `stage` is `to_prepare` while the order is a draft or being picked and
     * `to_deliver` once invoiced and waiting to be handed over.
     */
    public function outgoing(Request $request, string $id)
    {
        $warehouse = $this->warehouse($request, $id);

        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'sales_order_id' => ['sometimes', 'string'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $statuses = isset($filters['status']) ? array_filter(explode(',', $filters['status'])) : self::OPEN_SALES_STATUSES;

        $orders = DB::table('sales_orders as so')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('users as rep', 'rep.id', '=', 'so.sales_rep_id')
            ->where('so.tenant_id', $warehouse->tenant_id)
            ->whereIn('so.status', $statuses)
            ->where(fn ($query) => $query->where('so.warehouse_id', $warehouse->id)->orWhereExists(
                fn ($lines) => $lines->select(DB::raw(1))->from('sales_order_lines')->whereColumn('sales_order_lines.so_id', 'so.id')->where('sales_order_lines.warehouse_id', $warehouse->id)
            ))
            ->when($filters['sales_order_id'] ?? null, fn ($query, $orderId) => $query->where('so.id', $orderId))
            ->when($filters['search'] ?? null, fn ($query, $term) => $query->where('c.name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))
            ->orderBy('so.order_date')
            ->orderBy('so.id')
            ->select(['so.id', 'so.status', 'so.order_date', 'so.stock_deducted', 'so.notes', 'so.warehouse_id', 'c.id as customer_id', 'c.name as customer_name', 'rep.id as rep_id', 'rep.name as rep_name'])
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $lines = DB::table('sales_order_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.so_id', $orders->pluck('id'))
            ->get(['l.so_id', 'l.warehouse_id', 'l.product_id', 'p.name', 'p.sku', 'p.carton_qty', 'l.qty', 'l.unit', 'l.base_qty', 'l.free_qty', 'l.is_gift', 'l.batch_no'])
            ->groupBy('so_id');

        $orderWarehouse = $orders->getCollection()->pluck('warehouse_id', 'id');

        return response()->json([
            'data' => $orders->getCollection()->map(function ($order) use ($lines, $warehouse, $orderWarehouse) {
                $mine = ($lines[$order->id] ?? collect())
                    ->filter(fn ($line) => ($line->warehouse_id ?? $orderWarehouse[$order->id]) === $warehouse->id)
                    ->map(fn ($line) => [
                        'product' => ['id' => $line->product_id, 'name' => $line->name, 'sku' => $line->sku],
                        'qty' => (int) $line->qty,
                        'unit' => $line->unit,
                        'free_qty' => (int) $line->free_qty,
                        'is_gift' => (bool) $line->is_gift,
                        'batch_no' => $line->batch_no,
                        'cartons' => CartonCalculator::cartonsNeeded((int) $line->qty, (int) $line->free_qty, $line->unit, (int) $line->carton_qty, $line->base_qty !== null ? (int) $line->base_qty : null),
                    ])->values();

                return [
                    'id' => $order->id,
                    'status' => $order->status,
                    'stage' => $order->status === 'invoiced' ? 'to_deliver' : 'to_prepare',
                    'order_date' => $order->order_date,
                    'stock_deducted' => (bool) $order->stock_deducted,
                    'notes' => $order->notes,
                    'customer' => ['id' => $order->customer_id, 'name' => $order->customer_name],
                    'sales_rep' => $order->rep_id ? ['id' => $order->rep_id, 'name' => $order->rep_name] : null,
                    'total_cartons' => $mine->sum('cartons'),
                    'lines' => $mine,
                ];
            })->values(),
            'meta' => $this->pagination($orders),
        ]);
    }

    private function warehouse(Request $request, string $id, string $ability = 'view'): Warehouse
    {
        $warehouse = Warehouse::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);

        $this->authorize($ability, $warehouse);

        return $warehouse;
    }

    /**
     * @param  Builder<StockMovement>  $query
     */
    private function movementList(Request $request, Builder $query, string $export)
    {
        $filters = $request->validate([
            'product_id' => ['sometimes', 'string'],
            'warehouse_id' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
            'direction' => ['sometimes', Rule::in(['in', 'out'])],
            'batch_no' => ['sometimes', 'string', 'max:100'],
            'reference_type' => ['sometimes', 'string', 'max:40'],
            'reference_id' => ['sometimes', 'string', 'max:40'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query->where('tenant_id', $request->user()->tenant_id)
            ->with(['warehouse:id,name', 'product:id,name,sku', 'user:id,name', 'counterpartWarehouse:id,name'])
            ->when($filters['product_id'] ?? null, fn (Builder $q, $v) => $q->where('product_id', $v))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->whereIn('type', array_filter(explode(',', $v))))
            ->when($filters['direction'] ?? null, fn (Builder $q, $v) => $q->where('qty_cartons', $v === 'in' ? '>' : '<', 0))
            ->when($filters['batch_no'] ?? null, fn (Builder $q, $v) => $q->where('batch_no', $v))
            ->when($filters['reference_type'] ?? null, fn (Builder $q, $v) => $q->where('reference_type', $v))
            ->when($filters['reference_id'] ?? null, fn (Builder $q, $v) => $q->where('reference_id', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '>=', $request->date('from')->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '<=', $request->date('to')->endOfDay()));

        $this->applyFilters($request, $query, ['batch_no', 'product.name', 'product.sku']);

        $summary = (clone $query)->reorder()->toBase()->selectRaw('coalesce(sum(case when qty_cartons > 0 then qty_cartons end), 0) as total_in, coalesce(sum(case when qty_cartons < 0 then -qty_cartons end), 0) as total_out')->first();

        $this->applySorting($request, $query);

        if ($file = $this->exportIfRequested(
            $request, $query,
            ['Date', 'Warehouse', 'Product', 'SKU', 'Batch', 'Type', 'Cartons', 'Reference', 'By'],
            fn (StockMovement $m) => [$m->occurred_at->toDateTimeString(), $m->warehouse?->name, $m->product?->name, $m->product?->sku, $m->batch_no, StockMovement::TYPES[$m->type] ?? $m->type, $m->qty_cartons, trim("{$m->reference_type} {$m->reference_id}") ?: null, $m->user?->name],
            $export,
        )) {
            return $file;
        }

        return StockMovementResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString())->additional([
            'summary' => ['total_in' => (int) $summary->total_in, 'total_out' => (int) $summary->total_out, 'net' => (int) $summary->total_in - (int) $summary->total_out],
        ]);
    }

    /**
     * @return array<string, int|null>
     */
    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()];
    }
}
