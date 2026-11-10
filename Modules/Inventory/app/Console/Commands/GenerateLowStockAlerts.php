<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Notification;
use Modules\Inventory\Models\Product;

/**
 * Creates a notification for each active product whose stock, summed over every warehouse, has fallen below its
 * minimum (`min_stock_cartons`). A product is only announced again once its last alert has been read, so running
 * this daily does not repeat itself. Gift stock does not count: it is not sold.
 */
class GenerateLowStockAlerts extends Command
{
    protected $signature = 'inventory:generate-low-stock-alerts';

    protected $description = 'Create a notification for each product whose stock is below its minimum';

    public function handle(): int
    {
        $alreadyAnnounced = Notification::query()
            ->where('type', 'low_stock_alert')
            ->where('link_module', 'inventory')
            ->where('unread', true)
            ->pluck('link_id');

        $products = Product::query()
            ->where('active', true)
            ->where('min_stock_cartons', '>', 0)
            ->whereNotIn('id', $alreadyAnnounced)
            ->addSelect(['on_hand' => fn ($query) => $query
                ->selectRaw('coalesce(sum(inventory_batches.qty_cartons), 0)')
                ->from('inventory_batches')
                ->whereColumn('inventory_batches.product_id', 'products.id')
                ->where('inventory_batches.is_free_gift', false)])
            ->get()
            ->filter(fn (Product $product) => (int) $product->on_hand < $product->min_stock_cartons);

        foreach ($products as $product) {
            $leadTime = $product->lead_time_days !== null ? " Restocking takes about {$product->lead_time_days} day(s)." : '';

            Notification::create([
                'tenant_id' => $product->tenant_id,
                'user_id' => null,
                'type' => 'low_stock_alert',
                'title' => 'Stock below minimum',
                'body' => "{$product->name} has {$product->on_hand} carton(s) left, below its minimum of {$product->min_stock_cartons}.{$leadTime}",
                'link_module' => 'inventory',
                'link_entity' => 'products',
                'link_id' => $product->id,
                'unread' => true,
            ]);
        }

        $this->info("Created {$products->count()} low-stock alert(s).");

        return self::SUCCESS;
    }
}
