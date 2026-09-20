<?php

namespace Modules\Inventory\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Console\Commands\GenerateExpiryAlerts;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class GenerateExpiryAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_broadcast_notification_for_a_batch_within_the_critical_window(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(10), 'qty_cartons' => 20]);

        $this->artisan('inventory:generate-expiry-alerts')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'type' => 'expiry_alert',
            'link_module' => 'inventory',
            'link_id' => $batch->id,
            'user_id' => null,
        ]);
    }

    public function test_does_not_alert_for_a_batch_outside_the_critical_window(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(200), 'qty_cartons' => 20]);

        $this->artisan('inventory:generate-expiry-alerts');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_does_not_alert_for_an_exhausted_batch(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(5), 'qty_cartons' => 0]);

        $this->artisan('inventory:generate-expiry-alerts');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_running_it_twice_does_not_duplicate_the_alert(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(10), 'qty_cartons' => 20]);

        $this->artisan('inventory:generate-expiry-alerts');
        $this->artisan('inventory:generate-expiry-alerts');

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_critical_window_constant_matches_the_documented_30_days(): void
    {
        $this->assertSame(30, GenerateExpiryAlerts::CRITICAL_EXPIRY_DAYS);
    }
}
