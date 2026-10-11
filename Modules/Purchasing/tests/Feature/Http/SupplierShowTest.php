<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class SupplierShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchasing_user_can_view_a_supplier(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $record = Supplier::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/suppliers/{$record->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
    }

    public function test_a_supplier_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $record = Supplier::factory()->create();

        $response = $this->getJson("/api/v1/suppliers/{$record->id}");

        $response->assertNotFound();
    }
}
