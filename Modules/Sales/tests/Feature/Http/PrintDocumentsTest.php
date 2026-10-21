<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TestCase;

/**
 * Invoices, orders, delivery notes and receipts print as a page ready for A4, or come back as data.
 */
class PrintDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $rep;

    private Customer $customer;

    private SalesOrder $order;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create(['name' => 'Nile Vet Supplies']);
        $this->rep = $this->user('Sales Rep');
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['name' => 'Green Valley Clinic', 'phone' => '0100 555 1234', 'city' => 'Giza', 'sales_rep_id' => $this->rep->id]);
        $warehouse = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Main Depot']);
        $product = Product::factory()->recycle($this->tenant)->create(['name' => 'Ivermectin 1%', 'sku' => 'IVM-01']);
        $this->order = SalesOrder::factory()->recycle([$this->tenant, $this->customer, $warehouse])->create([
            'sales_rep_id' => $this->rep->id, 'status' => 'invoiced', 'subtotal' => 1000, 'tax_amount' => 140, 'total' => 1140, 'notes' => 'Deliver before noon',
        ]);
        SalesOrderLine::factory()->for($this->order, 'salesOrder')->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => 'B-77', 'qty' => 10, 'unit' => 'Carton', 'unit_price' => 100, 'free_qty' => 2, 'discount_pct' => 0, 'subtotal' => 1000]);
        $this->invoice = Invoice::factory()->recycle([$this->tenant, $this->customer])->create(['so_id' => $this->order->id, 'subtotal' => 1000, 'tax_amount' => 140, 'total' => 1140, 'paid' => 400, 'balance' => 740, 'status' => 'partial']);
        Sanctum::actingAs($this->rep);
    }

    private function user(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_invoice_prints_as_a_page_with_customer_lines_totals_and_payments(): void
    {
        Collection::factory()->recycle([$this->tenant, $this->customer, $this->invoice])->create(['amount' => 400, 'method' => 'Cash', 'reference' => 'RCPT-9', 'payment_date' => '2026-10-02']);

        $response = $this->get("/api/v1/invoices/{$this->invoice->id}/print")->assertOk();

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $response->assertSee('Nile Vet Supplies')
            ->assertSee('Invoice')
            ->assertSee($this->invoice->id)
            ->assertSee('Green Valley Clinic')
            ->assertSee('Giza')
            ->assertSee('Ivermectin 1% (IVM-01)')
            ->assertSee('B-77')
            ->assertSee('10 Carton')
            ->assertSee('1,140.00')
            ->assertSee('740.00')
            ->assertSee('Payments received')
            ->assertSee('RCPT-9')
            ->assertSee('Customer')
            ->assertSee('window.print()', false);
    }

    public function test_the_same_document_can_be_asked_for_as_data(): void
    {
        $this->getJson("/api/v1/invoices/{$this->invoice->id}/print?format=json")
            ->assertOk()
            ->assertJsonPath('data.type', 'Invoice')
            ->assertJsonPath('data.company', 'Nile Vet Supplies')
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.parties.0.title', 'Bill to')
            ->assertJsonPath('data.tables.0.rows.0.product', 'Ivermectin 1% (IVM-01)')
            ->assertJsonPath('data.totals.0.label', 'Subtotal')
            ->assertJsonPath('data.totals.4.value', '740.00');
    }

    public function test_the_page_can_be_laid_out_right_to_left(): void
    {
        $this->get("/api/v1/invoices/{$this->invoice->id}/print?dir=rtl")->assertOk()->assertSee('dir="rtl"', false);
        $this->get("/api/v1/invoices/{$this->invoice->id}/print")->assertOk()->assertSee('dir="ltr"', false);
    }

    public function test_the_html_is_escaped(): void
    {
        $this->customer->update(['name' => '<script>alert(1)</script>']);

        $this->get("/api/v1/invoices/{$this->invoice->id}/print")->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_a_delivery_note_shows_quantities_and_no_prices(): void
    {
        $delivery = Delivery::factory()->recycle([$this->tenant, $this->customer, $this->order, $this->invoice])->create(['driver' => 'Hassan', 'status' => 'preparing']);

        $response = $this->get("/api/v1/deliveries/{$delivery->id}/print")->assertOk();

        $response->assertSee('Delivery note')->assertSee('Hassan')->assertSee('Green Valley Clinic')->assertSee('Main Depot')->assertSee('Received by (customer)')->assertSee('B-77');
        $response->assertDontSee('Unit price')->assertDontSee('1,140.00')->assertDontSee('1,000.00');
    }

    public function test_an_order_and_a_receipt_print_too(): void
    {
        $collection = Collection::factory()->recycle([$this->tenant, $this->customer, $this->invoice])->create(['amount' => 400, 'method' => 'Bank Transfer', 'payment_date' => '2026-10-02']);

        $this->get("/api/v1/sales-orders/{$this->order->id}/print")->assertOk()->assertSee('Sales order')->assertSee('Deliver before noon')->assertSee('Main Depot');
        $this->get("/api/v1/collections/{$collection->id}/print")->assertOk()->assertSee('Payment receipt')->assertSee('Bank Transfer')->assertSee('Amount received')->assertSee('400.00')->assertSee('Cashier');
    }

    public function test_a_rep_cannot_print_documents_of_someone_elses_customer(): void
    {
        $other = $this->user('Sales Rep');
        $theirOrder = SalesOrder::factory()->recycle([$this->tenant, $this->customer])->create(['sales_rep_id' => $other->id]);
        $theirInvoice = Invoice::factory()->recycle([$this->tenant, $this->customer])->create(['so_id' => $theirOrder->id]);

        $this->get("/api/v1/invoices/{$theirInvoice->id}/print")->assertForbidden();
        $this->get("/api/v1/sales-orders/{$theirOrder->id}/print")->assertForbidden();
    }

    public function test_a_manager_prints_their_teams_documents(): void
    {
        $manager = $this->user('Sales Manager');
        $manager->forceFill(['data_scope' => 'team'])->save();
        $this->rep->update(['manager_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $this->get("/api/v1/invoices/{$this->invoice->id}/print")->assertOk();
    }

    public function test_documents_of_another_company_are_not_found(): void
    {
        Sanctum::actingAs($this->user('Administrator'));
        $foreign = Invoice::factory()->create();
        $foreignDelivery = Delivery::factory()->create();

        $this->get("/api/v1/invoices/{$foreign->id}/print")->assertNotFound();
        $this->get("/api/v1/deliveries/{$foreignDelivery->id}/print")->assertNotFound();
        $this->getJson("/api/v1/deliveries/{$foreignDelivery->id}")->assertNotFound();
        $this->putJson("/api/v1/deliveries/{$foreignDelivery->id}/deliver")->assertNotFound();
    }

    public function test_printing_needs_the_print_permission(): void
    {
        Sanctum::actingAs($this->user('Auditor'));

        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertOk();
        $this->get("/api/v1/invoices/{$this->invoice->id}/print")->assertForbidden();
    }

    public function test_the_accountant_prints_invoices_and_receipts(): void
    {
        Sanctum::actingAs($this->user('Accountant'));
        $collection = Collection::factory()->recycle([$this->tenant, $this->customer, $this->invoice])->create();

        $this->get("/api/v1/invoices/{$this->invoice->id}/print")->assertOk();
        $this->get("/api/v1/collections/{$collection->id}/print")->assertOk();
    }
}
