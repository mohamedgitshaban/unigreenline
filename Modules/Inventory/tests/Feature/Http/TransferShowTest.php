<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Transfer;
use Tests\TestCase;

class TransferShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_manager_can_view_a_transfer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $transfer = Transfer::factory()->recycle($tenant)->create(['created_by' => $user->id]);

        $response = $this->getJson("/api/v1/transfers/{$transfer->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $transfer->id);
        $response->assertJsonPath('data.from_warehouse.id', $transfer->from_warehouse_id);
        $response->assertJsonPath('data.to_warehouse.id', $transfer->to_warehouse_id);
        $response->assertJsonPath('data.created_by.id', $user->id);
    }

    public function test_a_transfer_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $transfer = Transfer::factory()->create();

        $response = $this->getJson("/api/v1/transfers/{$transfer->id}");

        $response->assertNotFound();
    }
}
