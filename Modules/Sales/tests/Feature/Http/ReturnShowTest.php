<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Sales\Models\ReturnRecord;
use Tests\TestCase;

class ReturnShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_manager_can_view_a_return(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $record = ReturnRecord::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/returns/{$record->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
    }

    public function test_a_return_from_another_tenant_is_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $record = ReturnRecord::factory()->create();

        $response = $this->getJson("/api/v1/returns/{$record->id}");

        $response->assertNotFound();
    }
}
