<?php

namespace Modules\Analytics\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

/**
 * The company at a glance, served by the Analytics module from every department's dashboard.
 */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_an_administrator_gets_every_department_with_its_headline_numbers(): void
    {
        $this->actingAsRole('Administrator');

        $response = $this->getJson('/api/v1/analytics/overview')->assertOk()
            ->assertJsonStructure(['data' => ['generated_at', 'period' => ['from', 'to'], 'scope', 'modules' => [['module', 'title', 'features', 'kpis', 'charts', 'tables']]]]);

        $this->assertEqualsCanonicalizing(array_keys(app(DashboardRegistry::class)->all()), collect($response->json('data.modules'))->pluck('module')->all());
    }

    public function test_a_user_only_gets_the_departments_they_may_open(): void
    {
        $this->actingAsRole('Warehouse Employee');

        $modules = collect($this->getJson('/api/v1/analytics/overview')->assertOk()->json('data.modules'))->pluck('module');

        $this->assertTrue($modules->contains('inventory'));
        $this->assertFalse($modules->contains('accounting'));
        $this->assertFalse($modules->contains('hr'));
    }

    public function test_the_departments_can_be_narrowed_and_the_period_chosen(): void
    {
        $this->actingAsRole('Administrator');

        $this->getJson('/api/v1/analytics/overview?modules=sales&period=quarter')
            ->assertOk()
            ->assertJsonCount(1, 'data.modules')
            ->assertJsonPath('data.modules.0.module', 'sales')
            ->assertJsonPath('data.period.label', 'quarter');
    }

    public function test_a_headline_number_points_at_the_list_behind_it(): void
    {
        $this->actingAsRole('Administrator');

        $kpis = collect($this->getJson('/api/v1/analytics/overview?modules=sales&period=month')->assertOk()->json('data.modules.0.kpis'));
        $overdue = $kpis->firstWhere('key', 'overdue');

        $this->assertSame('/api/v1/invoices', $overdue['link']['path']);
        $this->assertSame(['filter[status]' => 'overdue'], $overdue['link']['query']);
        $this->assertArrayHasKey('filter[status][ne]', $kpis->firstWhere('key', 'sales')['link']['query']);
    }

    public function test_the_overview_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/analytics/overview')->assertUnauthorized();
    }

    public function test_the_old_home_cards_say_where_their_data_is(): void
    {
        $this->actingAsRole('Administrator');

        $this->getJson('/api/v1/analytics/dashboard')
            ->assertOk()
            ->assertJsonPath('data.links.overdue_amount.path', '/api/v1/invoices')
            ->assertJsonPath('data.links.overdue_amount.query', ['filter[status]' => 'overdue'])
            ->assertJsonPath('data.links.pending_deliveries.path', '/api/v1/deliveries');
    }

    public function test_cancelled_orders_are_not_counted_as_monthly_sales(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create();
        SalesOrder::factory()->recycle([$this->tenant, $customer])->create(['status' => 'delivered', 'total' => 500, 'order_date' => now()->toDateString()]);
        SalesOrder::factory()->recycle([$this->tenant, $customer])->create(['status' => 'cancelled', 'total' => 900, 'order_date' => now()->toDateString()]);
        $this->actingAsRole('Administrator');

        $this->getJson('/api/v1/analytics/dashboard')->assertOk()->assertJsonPath('data.monthly_sales', '500.00');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
