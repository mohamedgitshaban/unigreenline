<?php

namespace Modules\Inventory\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class GenerateLowStockAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_below_its_minimum_across_all_warehouses_is_announced_once(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create(['name' => 'Ivermectin', 'min_stock_cartons' => 10, 'lead_time_days' => 14]);
        $this->stockIn($tenant, $product, 4);
        $this->stockIn($tenant, $product, 3);

        $this->artisan('inventory:generate-low-stock-alerts')->expectsOutput('Created 1 low-stock alert(s).')->assertSuccessful();
        $this->artisan('inventory:generate-low-stock-alerts')->expectsOutput('Created 0 low-stock alert(s).')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::query()->firstOrFail();
        $this->assertSame($tenant->id, $notification->tenant_id);
        $this->assertSame($product->id, $notification->link_id);
        $this->assertStringContainsString('7 carton(s) left, below its minimum of 10', $notification->body);
        $this->assertStringContainsString('about 14 day(s)', $notification->body);
    }

    public function test_a_product_is_announced_again_once_its_last_alert_has_been_read(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create(['min_stock_cartons' => 10]);
        $this->stockIn($tenant, $product, 2);

        $this->artisan('inventory:generate-low-stock-alerts')->assertSuccessful();
        Notification::query()->update(['unread' => false]);
        $this->artisan('inventory:generate-low-stock-alerts')->expectsOutput('Created 1 low-stock alert(s).')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_products_that_are_fine_inactive_without_a_minimum_or_only_in_gift_stock_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $fine = Product::factory()->recycle($tenant)->create(['min_stock_cartons' => 10]);
        $this->stockIn($tenant, $fine, 10);
        $inactive = Product::factory()->recycle($tenant)->create(['min_stock_cartons' => 10, 'active' => false]);
        $noMinimum = Product::factory()->recycle($tenant)->create(['min_stock_cartons' => 0]);
        $giftOnly = Product::factory()->recycle($tenant)->create(['min_stock_cartons' => 10]);
        $this->stockIn($tenant, $giftOnly, 50, ['is_free_gift' => true, 'cost_per_carton' => 0]);

        $this->artisan('inventory:generate-low-stock-alerts')->expectsOutput('Created 1 low-stock alert(s).')->assertSuccessful();

        $this->assertSame([$giftOnly->id], Notification::query()->pluck('link_id')->all());
        $this->assertNotNull($inactive);
        $this->assertNotNull($noMinimum);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stockIn(Tenant $tenant, Product $product, int $cartons, array $attributes = []): void
    {
        InventoryBatch::factory()->recycle([$tenant, $product, Warehouse::factory()->recycle($tenant)->create()])->withQtyCartons($cartons)->create($attributes);
    }
}
