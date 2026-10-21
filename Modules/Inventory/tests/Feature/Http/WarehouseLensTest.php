<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TestCase;

/**
 * The people who run a warehouse: how a warehouse is given to its manager, what they then see, and what is on its way in and out.
 */
class WarehouseLensTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Warehouse $main;

    private Warehouse $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->admin = $this->user('Administrator');
        $this->main = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Main']);
        $this->branch = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Branch']);
        $this->product = Product::factory()->recycle($this->tenant)->create(['name' => 'Ivermectin', 'carton_qty' => 10]);
        Sanctum::actingAs($this->admin);
    }

    private function user(string $role, ?User $manager = null, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create(['manager_id' => $manager?->id, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function purchaseOrder(Warehouse $warehouse, string $status = 'sent', int $cartons = 10, array $attributes = []): PurchaseOrder
    {
        $supplier = Supplier::query()->where('tenant_id', $this->tenant->id)->first() ?? Supplier::factory()->recycle($this->tenant)->create(['name' => 'Vet Supplies Co']);
        $po = PurchaseOrder::factory()->recycle([$this->tenant, $supplier, $warehouse])->create(['status' => $status, 'subtotal' => 900, 'tax_amount' => 0, 'total' => 900, ...$attributes]);
        PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create(['product_id' => $this->product->id, 'qty_cartons' => $cartons, 'cost_per_carton' => 90, 'total' => 900]);

        return $po;
    }

    private function salesOrder(Warehouse $warehouse, string $status = 'draft', int $qty = 4, array $attributes = []): SalesOrder
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['name' => 'Green Valley']);
        $order = SalesOrder::factory()->recycle([$this->tenant, $customer, $warehouse])->create(['status' => $status, 'sales_rep_id' => $this->admin->id, 'total' => 500, ...$attributes]);
        SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $this->product->id, 'qty' => $qty, 'unit' => 'Carton', 'free_qty' => 1, 'warehouse_id' => $warehouse->id, 'unit_price' => 100]);

        return $order;
    }

    public function test_assigning_people_to_a_warehouse_and_naming_its_manager(): void
    {
        $manager = $this->user('Warehouse Manager');
        $clerk = $this->user('Warehouse Employee');

        $this->putJson("/api/v1/warehouses/{$this->main->id}/users", ['user_ids' => [$clerk->id], 'manager_id' => $manager->id])
            ->assertOk()
            ->assertJsonPath('data.manager_id', $manager->id)
            ->assertJsonCount(2, 'data.users');

        $this->assertEqualsCanonicalizing([$manager->id, $clerk->id], $this->main->users()->pluck('users.id')->all(), 'the manager works there too');
        $this->assertSame($manager->name, $this->main->fresh()->manager_name);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Warehouse', 'entity_id' => $this->main->id, 'operation' => 'UPDATE', 'user_id' => $this->admin->id]);

        $this->putJson("/api/v1/warehouses/{$this->main->id}/users", ['user_ids' => []])->assertOk()->assertJsonCount(1, 'data.users');
        $this->assertSame([$manager->id], $this->main->users()->pluck('users.id')->all(), 'the manager stays');
    }

    public function test_assignment_is_checked(): void
    {
        $this->putJson("/api/v1/warehouses/{$this->main->id}/users", [])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->putJson("/api/v1/warehouses/{$this->main->id}/users", ['user_ids' => [User::factory()->create()->id]])->assertUnprocessable()->assertJsonValidationErrors('user_ids.0');
        $this->putJson("/api/v1/warehouses/{$this->main->id}/users", ['user_ids' => [], 'manager_id' => User::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->putJson('/api/v1/warehouses/'.Warehouse::factory()->create()->id.'/users', ['user_ids' => []])->assertNotFound();

        $outsider = $this->user('Warehouse Employee');
        Sanctum::actingAs($outsider);
        $this->putJson("/api/v1/warehouses/{$this->main->id}/users", ['user_ids' => [$outsider->id]])->assertForbidden();
    }

    public function test_naming_a_manager_when_creating_or_editing_a_warehouse_gives_them_the_warehouse(): void
    {
        $manager = $this->user('Warehouse Manager');
        $second = $this->user('Warehouse Manager');

        $id = $this->postJson('/api/v1/warehouses', ['name' => 'North', 'city' => 'Giza', 'governorate' => 'Giza', 'manager_id' => $manager->id, 'temperature' => 'ambient'])->assertCreated()->json('data.id');
        $this->assertTrue(Warehouse::query()->findOrFail($id)->users()->whereKey($manager->id)->exists());

        $this->putJson("/api/v1/warehouses/{$id}", ['manager_id' => $second->id])->assertOk();
        $this->assertTrue(Warehouse::query()->findOrFail($id)->users()->whereKey($second->id)->exists());

        Sanctum::actingAs($second);
        $this->assertContains($id, collect($this->getJson('/api/v1/warehouses')->json('data'))->pluck('id')->all());
    }

    public function test_a_warehouse_manager_sees_the_warehouse_they_manage_even_if_never_assigned(): void
    {
        $manager = $this->user('Warehouse Manager');
        $this->main->update(['manager_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $this->assertSame([$this->main->id], collect($this->getJson('/api/v1/warehouses')->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/warehouses/{$this->main->id}")->assertOk();
        $this->getJson("/api/v1/warehouses/{$this->branch->id}")->assertForbidden();
    }

    public function test_a_manager_sees_the_warehouses_of_the_people_who_report_to_them(): void
    {
        $boss = $this->user('Warehouse Manager');
        $clerk = $this->user('Warehouse Employee', $boss);
        $this->main->users()->attach($clerk);
        $this->branch->update(['manager_id' => $this->user('Warehouse Manager', $boss)->id]);
        Sanctum::actingAs($boss);

        $this->assertEqualsCanonicalizing([$this->main->id, $this->branch->id], collect($this->getJson('/api/v1/warehouses')->json('data'))->pluck('id')->all());

        Sanctum::actingAs($clerk);
        $this->assertSame([$this->main->id], collect($this->getJson('/api/v1/warehouses')->json('data'))->pluck('id')->all());
    }

    public function test_the_warehouse_sees_the_purchase_orders_on_their_way_without_prices(): void
    {
        $storeman = $this->user('Warehouse Manager');
        $this->main->users()->attach($storeman);
        $coming = $this->purchaseOrder($this->main, 'sent', 25, ['expected_date' => now()->addDays(3)->toDateString()]);
        $this->purchaseOrder($this->main, 'received');
        $this->purchaseOrder($this->main, 'cancelled');
        $this->purchaseOrder($this->branch, 'sent');
        Sanctum::actingAs($storeman);

        $response = $this->getJson("/api/v1/warehouses/{$this->main->id}/incoming")->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $coming->id)
            ->assertJsonPath('data.0.supplier.name', 'Vet Supplies Co')
            ->assertJsonPath('data.0.total_cartons', 25)
            ->assertJsonPath('data.0.lines.0.product.name', 'Ivermectin')
            ->assertJsonPath('data.0.lines.0.qty_cartons', 25)
            ->assertJsonPath('meta.total', 1);
        $payload = json_encode($response->json('data'));
        foreach (['cost', 'price', '"total"', 'subtotal', 'tax'] as $money) {
            $this->assertStringNotContainsString($money, $payload, "a warehouse lens must not show {$money}");
        }

        $this->getJson("/api/v1/warehouses/{$this->main->id}/incoming?status=received")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/incoming?purchase_order_id={$coming->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/incoming?search=nobody")->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/warehouses/{$this->branch->id}/incoming")->assertForbidden();
    }

    public function test_incoming_orders_are_listed_soonest_first(): void
    {
        $later = $this->purchaseOrder($this->main, 'sent', 5, ['expected_date' => now()->addDays(9)->toDateString()]);
        $sooner = $this->purchaseOrder($this->main, 'pending', 5, ['expected_date' => now()->addDays(2)->toDateString()]);
        $undated = $this->purchaseOrder($this->main, 'draft', 5, ['expected_date' => null]);

        $ids = collect($this->getJson("/api/v1/warehouses/{$this->main->id}/incoming")->json('data'))->pluck('id')->all();

        $this->assertSame([$sooner->id, $later->id, $undated->id], $ids);
    }

    public function test_the_warehouse_sees_the_sales_orders_that_will_take_stock_out(): void
    {
        $storeman = $this->user('Warehouse Manager');
        $this->main->users()->attach($storeman);
        $preparing = $this->salesOrder($this->main, 'picking', 4);
        $toHandOver = $this->salesOrder($this->main, 'invoiced', 2);
        $this->salesOrder($this->main, 'delivered');
        $this->salesOrder($this->main, 'cancelled');
        $this->salesOrder($this->branch, 'draft');
        Sanctum::actingAs($storeman);

        $response = $this->getJson("/api/v1/warehouses/{$this->main->id}/outgoing")->assertOk();
        $orders = collect($response->json('data'))->keyBy('id');

        $this->assertEqualsCanonicalizing([$preparing->id, $toHandOver->id], $orders->keys()->all());
        $this->assertSame('to_prepare', $orders[$preparing->id]['stage']);
        $this->assertSame('to_deliver', $orders[$toHandOver->id]['stage']);
        $this->assertSame('Green Valley', $orders[$preparing->id]['customer']['name']);
        $this->assertSame(5, $orders[$preparing->id]['total_cartons'], '4 ordered and 1 free');
        $this->assertSame('Ivermectin', $orders[$preparing->id]['lines'][0]['product']['name']);
        $payload = json_encode($response->json('data'));
        foreach (['price', 'subtotal', '"total"', 'tax', 'discount'] as $money) {
            $this->assertStringNotContainsString($money, $payload, "a warehouse lens must not show {$money}");
        }

        $this->getJson("/api/v1/warehouses/{$this->main->id}/outgoing?status=picking")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/outgoing?sales_order_id={$toHandOver->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/warehouses/{$this->branch->id}/outgoing")->assertForbidden();
    }

    public function test_an_order_with_lines_in_two_warehouses_shows_each_warehouse_only_its_own_lines(): void
    {
        $order = $this->salesOrder($this->main, 'picking', 4);
        $other = Product::factory()->recycle($this->tenant)->create(['name' => 'Amoxicillin']);
        SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $other->id, 'qty' => 6, 'unit' => 'Carton', 'free_qty' => 0, 'warehouse_id' => $this->branch->id]);

        $fromMain = $this->getJson("/api/v1/warehouses/{$this->main->id}/outgoing")->json('data.0');
        $fromBranch = $this->getJson("/api/v1/warehouses/{$this->branch->id}/outgoing")->json('data.0');

        $this->assertSame(['Ivermectin'], collect($fromMain['lines'])->pluck('product.name')->all());
        $this->assertSame(['Amoxicillin'], collect($fromBranch['lines'])->pluck('product.name')->all());
        $this->assertSame(6, $fromBranch['total_cartons']);
    }

    public function test_the_overview_gathers_the_numbers_a_warehouse_manager_needs(): void
    {
        $storeman = $this->user('Warehouse Manager');
        $this->main->users()->attach($storeman);
        $this->product->update(['min_stock_cartons' => 100]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-A', 'qty_cartons' => 30, 'reserved_cartons' => 5, 'exp_date' => now()->addDays(10)->toDateString()]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-B', 'qty_cartons' => 20, 'exp_date' => now()->subDays(2)->toDateString()]);
        $this->purchaseOrder($this->main, 'sent', 12);
        $this->purchaseOrder($this->main, 'sent', 8);
        $this->salesOrder($this->main, 'picking');
        $this->salesOrder($this->main, 'invoiced');
        Sanctum::actingAs($storeman);

        $response = $this->getJson("/api/v1/warehouses/{$this->main->id}/overview")->assertOk();

        $response->assertJsonPath('data.stock.products', 1)
            ->assertJsonPath('data.stock.batches', 2)
            ->assertJsonPath('data.stock.cartons', 50)
            ->assertJsonPath('data.stock.reserved_cartons', 5)
            ->assertJsonPath('data.stock.available_cartons', 45)
            ->assertJsonPath('data.stock.value', null)
            ->assertJsonPath('data.alerts.low_stock_products', 1)
            ->assertJsonPath('data.alerts.expired_batches', 1)
            ->assertJsonPath('data.alerts.expiring_within_30_days', 1)
            ->assertJsonPath('data.incoming.open_purchase_orders', 2)
            ->assertJsonPath('data.incoming.cartons_expected', 20)
            ->assertJsonPath('data.outgoing.open_sales_orders', 2)
            ->assertJsonPath('data.outgoing.to_prepare', 1)
            ->assertJsonPath('data.outgoing.to_deliver', 1);

        $this->assertNotEmpty($response->json('data.recent_movements'));
        $this->getJson("/api/v1/warehouses/{$this->branch->id}/overview")->assertForbidden();
    }

    public function test_the_overview_shows_the_stock_value_to_the_administrator(): void
    {
        $this->main->forceFill(['stock_value' => 1234.5])->save();

        $this->assertEquals(1234.5, $this->getJson("/api/v1/warehouses/{$this->main->id}/overview")->json('data.stock.value'));
    }

    public function test_the_manager_hears_about_a_purchase_order_for_their_warehouse_and_is_linked_to_it(): void
    {
        $manager = $this->user('Warehouse Manager');
        $this->main->update(['manager_id' => $manager->id]);
        $buyer = $this->user('Purchasing');
        $supplier = Supplier::factory()->recycle($this->tenant)->create();
        Sanctum::actingAs($buyer);

        $id = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $this->main->id, 'lines' => [['product_id' => $this->product->id, 'qty_cartons' => 10]]])->assertCreated()->json('data.id');

        $note = Notification::query()->where('user_id', $manager->id)->sole();
        $this->assertSame('Purchase order added', $note->title);
        $this->assertSame("/api/v1/warehouses/{$this->main->id}/incoming?purchase_order_id={$id}", $note->link_url);

        Sanctum::actingAs($manager);
        $this->assertSame([$id], collect($this->getJson($note->link_url)->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_the_manager_hears_when_a_sales_order_for_their_warehouse_moves(): void
    {
        $manager = $this->user('Warehouse Manager');
        $this->main->update(['manager_id' => $manager->id]);
        $order = $this->salesOrder($this->main, 'draft');
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-S', 'qty_cartons' => 50]);
        $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'picking'])->assertOk();

        $note = Notification::query()->where('user_id', $manager->id)->where('link_entity', 'SalesOrder')->sole();
        $this->assertSame("/api/v1/warehouses/{$this->main->id}/outgoing?sales_order_id={$order->id}", $note->link_url);
    }

    public function test_a_new_manager_and_the_old_one_hear_about_the_change(): void
    {
        $old = $this->user('Warehouse Manager');
        $new = $this->user('Warehouse Manager');
        $this->main->update(['manager_id' => $old->id]);

        $this->putJson("/api/v1/warehouses/{$this->main->id}/users", ['user_ids' => [], 'manager_id' => $new->id])->assertOk();

        $this->assertSame('Warehouse updated', Notification::query()->where('user_id', $new->id)->sole()->title);
        $this->assertSame('Warehouse updated', Notification::query()->where('user_id', $old->id)->sole()->title);
    }

    public function test_a_transfer_tells_the_managers_of_both_warehouses(): void
    {
        $fromManager = $this->user('Warehouse Manager');
        $toManager = $this->user('Warehouse Manager');
        $this->main->update(['manager_id' => $fromManager->id]);
        $this->branch->update(['manager_id' => $toManager->id]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-T', 'qty_cartons' => 20]);

        $this->postJson('/api/v1/transfers', ['product_id' => $this->product->id, 'from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $this->branch->id, 'batch_no' => 'B-T', 'qty_cartons' => 5])->assertCreated();

        $this->assertSame('Transfer added', Notification::query()->where('user_id', $fromManager->id)->sole()->title);
        $this->assertSame('Transfer added', Notification::query()->where('user_id', $toManager->id)->sole()->title);
    }

    public function test_the_users_of_a_warehouse_can_be_listed_by_anyone_who_can_see_it(): void
    {
        $clerk = $this->user('Warehouse Employee');
        $this->main->users()->attach($clerk);
        Sanctum::actingAs($clerk);

        $this->getJson("/api/v1/warehouses/{$this->main->id}/users")->assertOk()->assertJsonPath('data.users.0.id', $clerk->id)->assertJsonPath('data.users.0.is_manager', false);
        $this->getJson("/api/v1/warehouses/{$this->branch->id}/users")->assertForbidden();
    }

    public function test_the_lenses_need_their_own_permissions(): void
    {
        $seller = $this->user('Sales Rep');
        Sanctum::actingAs($seller);

        foreach (['overview', 'movements', 'incoming', 'outgoing'] as $lens) {
            $this->getJson("/api/v1/warehouses/{$this->main->id}/{$lens}")->assertForbidden();
        }
    }
}
