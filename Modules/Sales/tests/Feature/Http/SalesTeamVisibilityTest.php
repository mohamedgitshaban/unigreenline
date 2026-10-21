<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

/**
 * A manager whose scope is `team` sees the orders, invoices, collections and deliveries of everyone who
 * reports to them, directly or through others, and nothing from outside the line.
 */
class SalesTeamVisibilityTest extends TestCase
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
        $lead = $this->user('Sales Rep', ['manager_id' => $this->manager->id]);
        $this->rep = $this->user('Sales Rep', ['manager_id' => $lead->id]);
        $this->outsider = $this->user('Sales Rep');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{order: SalesOrder, invoice: Invoice, collection: Collection, delivery: Delivery}
     */
    private function documentsOf(User $owner): array
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $owner->id]);
        $order = SalesOrder::factory()->recycle([$this->tenant, $customer])->create(['sales_rep_id' => $owner->id]);
        $invoice = Invoice::factory()->recycle([$this->tenant, $customer])->create(['so_id' => $order->id]);

        return [
            'order' => $order,
            'invoice' => $invoice,
            'collection' => Collection::factory()->recycle([$this->tenant, $customer, $invoice])->create(),
            'delivery' => Delivery::factory()->recycle([$this->tenant, $customer, $order, $invoice])->create(),
        ];
    }

    private function ids(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_team_scoped_manager_sees_their_teams_documents_and_not_an_outsiders(): void
    {
        $team = $this->documentsOf($this->rep);
        $own = $this->documentsOf($this->manager);
        $other = $this->documentsOf($this->outsider);
        Sanctum::actingAs($this->manager);

        $orders = $this->ids('/api/v1/sales-orders');
        $this->assertEqualsCanonicalizing([$team['order']->id, $own['order']->id], $orders);
        $this->assertEqualsCanonicalizing([$team['invoice']->id, $own['invoice']->id], $this->ids('/api/v1/invoices'));
        $this->assertEqualsCanonicalizing([$team['collection']->id, $own['collection']->id], $this->ids('/api/v1/collections'));
        $this->assertEqualsCanonicalizing([$team['delivery']->id, $own['delivery']->id], $this->ids('/api/v1/deliveries'));

        $this->getJson("/api/v1/sales-orders/{$team['order']->id}")->assertOk();
        $this->getJson("/api/v1/invoices/{$team['invoice']->id}")->assertOk();
        $this->getJson("/api/v1/sales-orders/{$other['order']->id}")->assertForbidden();
        $this->getJson("/api/v1/invoices/{$other['invoice']->id}")->assertForbidden();
    }

    public function test_a_rep_sees_only_their_own_documents_even_though_a_manager_sees_theirs(): void
    {
        $mine = $this->documentsOf($this->rep);
        $this->documentsOf($this->manager);
        Sanctum::actingAs($this->rep);

        $this->assertSame([$mine['order']->id], $this->ids('/api/v1/sales-orders'));
        $this->assertSame([$mine['invoice']->id], $this->ids('/api/v1/invoices'));
    }

    public function test_a_manager_without_a_team_scope_sees_every_order(): void
    {
        $this->documentsOf($this->rep);
        $this->documentsOf($this->outsider);
        $unscoped = $this->user('Sales Manager');
        Sanctum::actingAs($unscoped);

        $this->assertCount(2, $this->ids('/api/v1/sales-orders'));
    }

    public function test_a_customers_invoices_are_limited_to_the_visible_ones(): void
    {
        $team = $this->documentsOf($this->rep);
        Sanctum::actingAs($this->manager);

        $this->assertSame([$team['invoice']->id], $this->ids("/api/v1/customers/{$team['order']->customer_id}/invoices"));
    }

    public function test_an_order_can_only_be_placed_under_yourself_or_your_team(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->manager);

        $body = ['customer_id' => $customer->id, 'lines' => []];
        $this->postJson('/api/v1/sales-orders', [...$body, 'sales_rep_id' => $this->outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('sales_rep_id');
    }
}
