<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
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

    public function test_sales_rep_can_view_their_own_order_and_page_through_its_lines(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $order = SalesOrder::factory()->recycle($tenant)->create(['sales_rep_id' => $rep->id]);
        SalesOrderLine::factory()->count(12)->recycle($tenant)->create(['so_id' => $order->id]);

        $response = $this->getJson("/api/v1/sales-orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonPath('data.lines_count', 12);
        $response->assertJsonPath('data.invoices_count', 0);
        $response->assertJsonMissingPath('data.lines');

        $lines = $this->getJson("/api/v1/sales-orders/{$order->id}/lines");

        $lines->assertOk();
        $lines->assertJsonCount(10, 'data');
        $lines->assertJsonPath('meta.last_page', 2);
    }

    public function test_sales_rep_cannot_list_lines_of_another_reps_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $otherRep = User::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle($tenant)->create(['sales_rep_id' => $otherRep->id]);

        $this->getJson("/api/v1/sales-orders/{$order->id}/lines")->assertForbidden();
        $this->getJson("/api/v1/sales-orders/{$order->id}/invoices")->assertForbidden();
    }

    public function test_lines_of_an_order_from_another_tenant_are_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $order = SalesOrder::factory()->create();

        $this->getJson("/api/v1/sales-orders/{$order->id}/lines")->assertNotFound();
    }
}
