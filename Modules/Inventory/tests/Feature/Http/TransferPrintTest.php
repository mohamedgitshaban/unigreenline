<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class TransferPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transfer_prints_as_a_slip_with_both_warehouses_and_no_prices(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create(['name' => 'Nile Vet Supplies']);
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        $from = Warehouse::factory()->recycle($tenant)->create(['name' => 'Main Depot', 'city' => 'Cairo', 'manager_name' => 'Omar']);
        $to = Warehouse::factory()->recycle($tenant)->create(['name' => 'Alex Branch', 'city' => 'Alexandria']);
        $from->users()->attach($user);
        $to->users()->attach($user);
        $product = Product::factory()->recycle($tenant)->create(['name' => 'Amoxicillin', 'sku' => 'AMX-1']);
        $transfer = Transfer::factory()->recycle([$tenant, $product, $from])->create(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'batch_no' => 'B-12', 'qty_cartons' => 15, 'notes' => 'Fragile', 'created_by' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->get("/api/v1/transfers/{$transfer->id}/print")->assertOk();

        $response->assertSee('Stock transfer')->assertSee('Nile Vet Supplies')->assertSee('Main Depot')->assertSee('Alex Branch')->assertSee('Manager: Omar')
            ->assertSee('Amoxicillin (AMX-1)')->assertSee('B-12')->assertSee('15')->assertSee('Fragile')->assertSee('Received by')->assertSee($user->name);
        $response->assertDontSee('cost', false)->assertDontSee('price', false);

        $this->getJson("/api/v1/transfers/{$transfer->id}/print?format=json")->assertOk()->assertJsonPath('data.parties.1.lines.0', 'Alex Branch');
    }

    public function test_another_companys_transfer_is_not_found_and_a_rep_may_not_print(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $foreign = Transfer::factory()->create();
        Sanctum::actingAs(tap(User::factory()->recycle($tenant)->create(), fn (User $u) => $u->assignRole('Administrator')));

        $this->get("/api/v1/transfers/{$foreign->id}/print")->assertNotFound();

        Sanctum::actingAs(tap(User::factory()->recycle($tenant)->create(), fn (User $u) => $u->assignRole('Sales Rep')));
        $this->get("/api/v1/transfers/{$foreign->id}/print")->assertForbidden();
    }
}
