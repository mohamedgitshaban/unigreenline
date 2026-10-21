<?php

namespace Modules\Recap\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Recap\Models\Recap;
use Tests\TestCase;

class RecapPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_write_off_prints_with_values_only_for_the_administrator(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create(['name' => 'Nile Vet Supplies']);
        $warehouse = Warehouse::factory()->recycle($tenant)->create(['name' => 'Main Depot']);
        $batch = InventoryBatch::factory()->recycle([$tenant, $warehouse])->create(['batch_no' => 'B-OLD', 'qty_cartons' => 20, 'cost_per_carton' => 50]);
        $recap = Recap::factory()->recycle([$tenant, $warehouse])->create(['reason' => 'expired', 'total_value' => 500]);
        $recap->lines()->create(['batch_id' => $batch->id, 'product_id' => $batch->product_id, 'batch_no' => $batch->batch_no, 'exp_date' => $batch->exp_date, 'qty_cartons' => 10, 'cost_per_carton' => 50, 'line_value' => 500]);

        $manager = User::factory()->recycle($tenant)->create();
        $manager->assignRole('Warehouse Manager');
        $warehouse->users()->attach($manager);
        Sanctum::actingAs($manager);
        $this->get("/api/v1/recaps/{$recap->id}/print")->assertOk()->assertSee('Stock write-off')->assertSee('Main Depot')->assertSee('B-OLD')->assertSee('expired')->assertDontSee('Total value')->assertDontSee('500.00');

        $admin = User::factory()->recycle($tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);
        $this->get("/api/v1/recaps/{$recap->id}/print")->assertOk()->assertSee('Total value')->assertSee('500.00');

        $this->get('/api/v1/recaps/'.Recap::factory()->create()->id.'/print')->assertNotFound();
    }
}
