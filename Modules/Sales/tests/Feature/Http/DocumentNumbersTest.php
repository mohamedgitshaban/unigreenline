<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\SalesOrder;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Orders, invoices, deliveries and collections carry a readable number, unique and counting up per company.
 */
class DocumentNumbersTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['credit_limit' => 100000, 'balance' => 0]);
        $this->product = Product::factory()->recycle($this->tenant)->create();
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->withQtyCartons(20)->create();
        $this->ensureLedgerAccounts($this->tenant);

        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);
    }

    public function test_each_document_gets_the_next_number_of_its_own_kind(): void
    {
        $first = $this->quickSale();
        $second = $this->quickSale();

        $this->assertSame('SO-000001', $first['number']);
        $this->assertSame('SO-000002', $second['number']);

        $invoice = $this->getJson("/api/v1/sales-orders/{$second['id']}/invoices")->assertOk()->json('data.0');
        $this->assertSame('INV-000002', $invoice['number']);

        $collection = $this->postJson('/api/v1/collections', ['invoice_id' => $invoice['id'], 'amount' => 100, 'method' => 'Cash'])
            ->assertCreated()
            ->json('data');
        $this->assertSame('COL-000001', $collection['number']);

        $this->assertDatabaseHas('deliveries', ['so_id' => $second['id'], 'number' => 'DLV-000002']);
    }

    public function test_another_company_counts_from_one_again(): void
    {
        $this->quickSale();
        $this->quickSale();

        $otherTenant = Tenant::factory()->create();
        $order = SalesOrder::factory()->recycle($otherTenant)->create();

        $this->assertSame('SO-000001', $order->number);
    }

    public function test_orders_and_invoices_can_be_found_by_their_number(): void
    {
        $this->quickSale();
        $second = $this->quickSale();

        $this->getJson('/api/v1/sales-orders?search=SO-000002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second['id']);

        $this->getJson('/api/v1/invoices?search=INV-000002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'INV-000002');
    }

    public function test_printed_documents_show_the_readable_number_not_the_id(): void
    {
        $order = $this->quickSale();
        $invoiceId = $this->getJson("/api/v1/sales-orders/{$order['id']}/invoices")->json('data.0.id');

        $this->getJson("/api/v1/invoices/{$invoiceId}/print?format=json")
            ->assertOk()
            ->assertJsonPath('data.number', 'INV-000001');
    }

    /**
     * @return array<string, mixed>
     */
    private function quickSale(): array
    {
        return $this->postJson('/api/v1/sales-orders/deliver', [
            'customer_id' => $this->customer->id,
            'pay_type' => 'credit',
            'lines' => [['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 1, 'unit' => 'Carton', 'unit_price' => 100]],
        ])->assertCreated()->json('data');
    }
}
