<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

/**
 * The sales dashboard adds up the right orders for whoever is looking: everything for a manager who sees everything,
 * only their own team for a team-scoped manager, only their own for a rep.
 */
class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private User $rep;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->manager = $this->user('Sales Manager', ['data_scope' => 'team']);
        $this->rep = $this->user('Sales Rep', ['manager_id' => $this->manager->id]);
        $this->outsider = $this->user('Sales Rep');

        $this->order($this->rep, 'draft', 100);
        $this->order($this->rep, 'delivered', 300);
        $this->order($this->rep, 'cancelled', 500);
        $this->order($this->outsider, 'picking', 1000);
        $this->order($this->rep, 'delivered', 200, now()->startOfMonth()->subDay()->toDateString());
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function order(User $rep, string $status, float $total, ?string $date = null): SalesOrder
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $rep->id]);

        return SalesOrder::factory()->recycle([$this->tenant, $customer])->create(['sales_rep_id' => $rep->id, 'status' => $status, 'total' => $total, 'order_date' => $date ?? now()->toDateString()]);
    }

    private function kpis(string $query = ''): array
    {
        return collect($this->getJson('/api/v1/dashboards/sales'.$query)->assertOk()->json('data.kpis'))->keyBy('key')->all();
    }

    public function test_a_team_scoped_manager_sees_the_figures_of_their_team_only(): void
    {
        Sanctum::actingAs($this->manager);

        $kpis = $this->kpis();

        $this->assertSame(2, $kpis['orders']['value'], 'the rep\'s two live orders this month');
        $this->assertSame(1, $kpis['orders']['previous'], 'the order from last month');
        $this->assertEquals(400, $kpis['sales']['value']);
        $this->assertEquals(200, $kpis['average_order']['value']);
        $this->assertSame(1, $kpis['open_orders']['value']);
        $this->assertSame(1, $kpis['delivered_orders']['value']);
        $this->assertEquals(100.0, $kpis['orders']['change_pct']);
        $this->assertSame('up', $kpis['orders']['trend']);
    }

    public function test_a_manager_who_sees_everything_sees_every_order(): void
    {
        Sanctum::actingAs($this->user('Sales Manager'));

        $kpis = $this->kpis();

        $this->assertSame(3, $kpis['orders']['value']);
        $this->assertEquals(1400, $kpis['sales']['value']);
    }

    public function test_a_rep_sees_only_their_own_orders(): void
    {
        Sanctum::actingAs($this->rep);
        $this->assertSame(2, $this->kpis()['orders']['value']);

        Sanctum::actingAs($this->outsider);
        $kpis = $this->kpis();
        $this->assertSame(1, $kpis['orders']['value']);
        $this->assertEquals(1000, $kpis['sales']['value']);
    }

    public function test_the_charts_and_tables_follow_the_same_scope(): void
    {
        Sanctum::actingAs($this->manager);

        $data = $this->getJson('/api/v1/dashboards/sales')->json('data');
        $charts = collect($data['charts'])->keyBy('key');
        $tables = collect($data['tables'])->keyBy('key');

        $this->assertSame(now()->endOfMonth()->day, count($charts['sales_by_day']['series']), 'one point per day of the month');
        $this->assertEquals(400, collect($charts['sales_by_day']['series'])->sum('value'));
        $this->assertEqualsCanonicalizing(['draft' => 1, 'delivered' => 1], collect($charts['orders_by_status']['series'])->pluck('value', 'label')->all());
        $this->assertCount(2, $tables['top_customers']['rows']);
        $this->assertArrayNotHasKey('by_sales_rep', $tables->all(), 'a breakdown by rep is pointless with a single rep');
    }

    public function test_a_manager_with_several_reps_gets_the_breakdown_by_rep(): void
    {
        $second = $this->user('Sales Rep', ['manager_id' => $this->manager->id]);
        $this->order($second, 'delivered', 50);
        Sanctum::actingAs($this->manager);

        $rows = collect($this->getJson('/api/v1/dashboards/sales')->json('data.tables'))->firstWhere('key', 'by_sales_rep')['rows'];

        $this->assertSame([$this->rep->id, $second->id], collect($rows)->pluck('id')->all(), 'best seller first');
        $this->assertEquals(400, $rows[0]['value']);
    }

    public function test_a_period_can_be_chosen(): void
    {
        Sanctum::actingAs($this->manager);

        $lastMonth = $this->kpis('?period=last_month');

        $this->assertSame(1, $lastMonth['orders']['value']);
        $this->assertEquals(200, $lastMonth['sales']['value']);
    }

    public function test_invoices_and_collections_are_limited_to_the_visible_orders(): void
    {
        $mine = $this->order($this->rep, 'invoiced', 100);
        $theirs = $this->order($this->outsider, 'invoiced', 100);
        $overdue = Invoice::factory()->recycle([$this->tenant, $mine->customer])->create(['so_id' => $mine->id, 'issued_date' => now()->toDateString(), 'total' => 100, 'balance' => 40, 'paid' => 60, 'status' => 'overdue', 'due_date' => now()->subDays(5)->toDateString()]);
        Invoice::factory()->recycle([$this->tenant, $theirs->customer])->create(['so_id' => $theirs->id, 'issued_date' => now()->toDateString(), 'total' => 900, 'balance' => 900, 'paid' => 0, 'status' => 'outstanding']);
        Collection::factory()->recycle([$this->tenant, $mine->customer, $overdue])->create(['amount' => 60, 'payment_date' => now()->toDateString()]);
        Sanctum::actingAs($this->manager);

        $kpis = $this->kpis('?features=invoices');

        $this->assertEquals(100, $kpis['billed']['value']);
        $this->assertEquals(60, $kpis['collected']['value']);
        $this->assertEquals(40, $kpis['outstanding']['value']);
        $this->assertEquals(40, $kpis['overdue']['value']);
        $this->assertEquals(60.0, $kpis['collection_rate']['value']);
    }
}
