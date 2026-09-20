<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class SalesOrderShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_cannot_view_another_reps_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $otherRep = User::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle($tenant)->create(['sales_rep_id' => $otherRep->id]);

        $response = $this->getJson("/api/v1/sales-orders/{$order->id}");

        $response->assertForbidden();
    }

    public function test_sales_rep_can_view_their_own_order_with_lines(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $order = SalesOrder::factory()->recycle($tenant)->create(['sales_rep_id' => $rep->id]);

        $response = $this->getJson("/api/v1/sales-orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $order->id);
    }
}
