<?php

namespace Modules\Analytics\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales grouped by customer, item, sales rep, category, sub-class, brand or supplier: how many orders, how many
 * lines and how much was sold (line price after the line discount, before the invoice discount and VAT). Only
 * orders that were invoiced or delivered count as sales.
 */
class SalesBreakdownService
{
    public const GROUPS = ['customer', 'item', 'rep', 'category', 'subclass', 'brand', 'supplier'];

    /**
     * @param  array<int, string>|null  $visibleRepIds  Restricts the figures to these sales reps' orders, or null for everyone.
     * @return Collection<int, object{key: ?string, name: string, orders: int, lines: int, amount: string}>
     */
    public function generate(string $tenantId, string $groupBy, ?string $from, ?string $to, ?string $warehouseId, ?array $visibleRepIds): Collection
    {
        $query = DB::table('sales_order_lines as l')
            ->join('sales_orders as so', 'so.id', '=', 'l.so_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('so.tenant_id', $tenantId)
            ->whereIn('so.status', ['invoiced', 'delivered'])
            ->when($from, fn (Builder $q, string $date) => $q->whereDate('so.order_date', '>=', $date))
            ->when($to, fn (Builder $q, string $date) => $q->whereDate('so.order_date', '<=', $date))
            ->when($warehouseId, fn (Builder $q, string $id) => $q->where('so.warehouse_id', $id))
            ->when($visibleRepIds !== null, fn (Builder $q) => $q->whereIn('so.sales_rep_id', $visibleRepIds));

        $this->groupBy($query, $groupBy);

        return $query
            ->selectRaw('count(distinct so.id) as orders, count(*) as `lines`, coalesce(sum(l.subtotal), 0) as amount')
            ->orderByDesc('amount')
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => (object) [
                'key' => $row->grp_key,
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'lines' => (int) $row->lines,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
            ]);
    }

    private function groupBy(Builder $query, string $groupBy): void
    {
        match ($groupBy) {
            'customer' => $query->join('customers as c', 'c.id', '=', 'so.customer_id')
                ->selectRaw('c.id as grp_key, c.name as name')->groupBy('c.id', 'c.name'),
            'item' => $query->selectRaw("p.id as grp_key, concat(p.name, ' (', p.sku, ')') as name")->groupBy('p.id', 'p.name', 'p.sku'),
            'rep' => $query->leftJoin('users as u', 'u.id', '=', 'so.sales_rep_id')
                ->selectRaw("u.id as grp_key, coalesce(u.name, '(no sales rep)') as name")->groupBy('u.id', 'u.name'),
            'category' => $query->join('product_categories as pc', 'pc.id', '=', 'p.category_id')
                ->leftJoin('product_categories as parent', 'parent.id', '=', 'pc.parent_id')
                ->selectRaw('coalesce(parent.id, pc.id) as grp_key, coalesce(parent.name, pc.name) as name')->groupByRaw('coalesce(parent.id, pc.id), coalesce(parent.name, pc.name)'),
            'subclass' => $query->join('product_categories as pc', 'pc.id', '=', 'p.category_id')
                ->selectRaw('pc.id as grp_key, pc.name as name')->groupBy('pc.id', 'pc.name'),
            'brand' => $query->selectRaw("p.brand as grp_key, coalesce(p.brand, '(no brand)') as name")->groupBy('p.brand'),
            'supplier' => $query->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
                ->selectRaw("s.id as grp_key, coalesce(s.name, '(no supplier)') as name")->groupBy('s.id', 's.name'),
        };
    }
}
