<?php

namespace Modules\Inventory\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\WarehouseAccess;

/**
 * What is on the shelves of the warehouses the user may see: how much, where, what is running low and what is about to expire.
 */
class StockSection implements DashboardSection
{
    public function __construct(private readonly WarehouseAccess $access) {}

    public function key(): string
    {
        return 'stock';
    }

    public function label(): string
    {
        return 'Stock';
    }

    public function permission(): ?string
    {
        return 'warehouses.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $visible = $this->access->idsFor($user);
        $batches = fn () => InventoryBatch::query()->where('inventory_batches.tenant_id', $user->tenant_id)->where('qty_cartons', '>', 0)
            ->when($visible !== null, fn ($q) => $q->whereIn('inventory_batches.warehouse_id', $visible));

        $totals = $batches()->selectRaw('count(distinct product_id) as products, coalesce(sum(qty_cartons), 0) as cartons, coalesce(sum(reserved_cartons), 0) as reserved')->first();
        $today = today();

        $onHand = $batches()->selectRaw('product_id, sum(qty_cartons) as cartons')->groupBy('product_id');
        $low = Product::query()->where('products.tenant_id', $user->tenant_id)->where('active', true)->where('min_stock_cartons', '>', 0)
            ->leftJoinSub($onHand, 'stock', 'stock.product_id', '=', 'products.id')
            ->whereRaw('coalesce(stock.cartons, 0) < products.min_stock_cartons')
            ->selectRaw('products.id, products.name, products.sku, products.min_stock_cartons, coalesce(stock.cartons, 0) as cartons');

        $byWarehouse = $batches()->join('warehouses', 'warehouses.id', '=', 'inventory_batches.warehouse_id')
            ->selectRaw('warehouses.id, warehouses.name, sum(qty_cartons) as cartons')->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('cartons')->limit(10)->get();

        $byCategory = $batches()->join('products', 'products.id', '=', 'inventory_batches.product_id')->join('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->selectRaw('product_categories.name, sum(qty_cartons) as cartons')->groupBy('product_categories.name')->orderByDesc('cartons')->limit(8)->get();

        $expiring = $batches()->whereDate('exp_date', '>=', $today)->whereDate('exp_date', '<=', $today->copy()->addDays(30))
            ->join('products', 'products.id', '=', 'inventory_batches.product_id')->join('warehouses', 'warehouses.id', '=', 'inventory_batches.warehouse_id')
            ->orderBy('exp_date')->limit(5)->get(['inventory_batches.id', 'products.name as product', 'warehouses.name as warehouse', 'inventory_batches.batch_no', 'inventory_batches.exp_date', 'inventory_batches.qty_cartons']);

        $value = Warehouse::query()->where('tenant_id', $user->tenant_id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->sum('stock_value');

        return [
            'kpis' => array_values(array_filter([
                Widgets::kpi('cartons_on_hand', 'Cartons on hand', (int) $totals->cartons, null, 'cartons'),
                Widgets::kpi('available_cartons', 'Cartons available (not reserved)', (int) $totals->cartons - (int) $totals->reserved, null, 'cartons'),
                Widgets::kpi('products_in_stock', 'Products in stock', (int) $totals->products),
                $user->hasRole('Administrator') ? Widgets::kpi('stock_value', 'Stock value', (float) $value, null, 'currency') : null,
                Widgets::kpi('low_stock_products', 'Products below minimum stock', (clone $low)->get()->count()),
                Widgets::kpi('expired_batches', 'Expired batches still in stock', $batches()->whereDate('exp_date', '<', $today)->count()),
                Widgets::kpi('expiring_batches', 'Batches expiring within 30 days', $batches()->whereDate('exp_date', '>=', $today)->whereDate('exp_date', '<=', $today->copy()->addDays(30))->count()),
            ])),
            'charts' => [
                Widgets::chart('stock_by_warehouse', 'Cartons per warehouse', 'bar', $byWarehouse->map(fn ($row) => ['label' => $row->name, 'value' => (int) $row->cartons])->all(), 'cartons'),
                Widgets::chart('stock_by_category', 'Cartons per category', 'donut', $byCategory->map(fn ($row) => ['label' => $row->name, 'value' => (int) $row->cartons])->all(), 'cartons'),
            ],
            'tables' => [
                Widgets::table('low_stock', 'Products below minimum stock', [['key' => 'name', 'label' => 'Product'], ['key' => 'cartons', 'label' => 'On hand'], ['key' => 'minimum', 'label' => 'Minimum']],
                    $low->orderByRaw('products.min_stock_cartons - coalesce(stock.cartons, 0) desc')->limit(5)->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'cartons' => (int) $row->cartons, 'minimum' => (int) $row->min_stock_cartons])->all()),
                Widgets::table('expiring_soon', 'Expiring soonest', [['key' => 'product', 'label' => 'Product'], ['key' => 'warehouse', 'label' => 'Warehouse'], ['key' => 'batch_no', 'label' => 'Batch'], ['key' => 'exp_date', 'label' => 'Expires'], ['key' => 'qty_cartons', 'label' => 'Cartons']],
                    $expiring->map(fn ($row) => ['id' => $row->id, 'product' => $row->product, 'warehouse' => $row->warehouse, 'batch_no' => $row->batch_no, 'exp_date' => $row->exp_date->toDateString(), 'qty_cartons' => (int) $row->qty_cartons])->all()),
            ],
        ];
    }
}
