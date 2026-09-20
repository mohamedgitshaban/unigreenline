<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class SalesOrderIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_only_sees_their_own_orders(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $otherRep = User::factory()->recycle($tenant)->create();

        $ownOrder = SalesOrder::factory()->recycle($tenant)->create(['sales_rep_id' => $rep->id]);
        $othersOrder = SalesOrder::factory()->recycle($tenant)->create(['sales_rep_id' => $otherRep->id]);

        $response = $this->getJson('/api/v1/sales-orders');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ownOrder->id));
        $this->assertFalse($ids->contains($othersOrder->id));
    }

    public function test_sales_manager_sees_every_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $manager = User::factory()->recycle($tenant)->create();
        $manager->assignRole('Sales Manager');
        Sanctum::actingAs($manager);

        SalesOrder::factory()->recycle($tenant)->count(3)->create();

        $response = $this->getJson('/api/v1/sales-orders');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/sales-orders');

        $response->assertUnauthorized();
    }
}
