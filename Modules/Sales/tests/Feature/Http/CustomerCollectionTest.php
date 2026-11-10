<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * One payment from a customer, spread over his open invoices, oldest due date first.
 */
class CustomerCollectionTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    private Invoice $overdue;

    private Invoice $later;

    private Invoice $undated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['balance' => 600]);
        $this->ensureLedgerAccounts($this->tenant);

        // Created newest first on purpose: the order they are paid in must follow the due date, not creation.
        $this->undated = $this->invoice(100, null, 'outstanding');
        $this->later = $this->invoice(200, '2026-02-10', 'outstanding');
        $this->overdue = $this->invoice(300, '2026-01-10', 'overdue');

        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);
    }

    public function test_a_payment_is_spread_over_the_oldest_invoices_first(): void
    {
        $this->travelTo('2026-11-10 09:00:00');

        $this->postJson("/api/v1/customers/{$this->customer->id}/collections", ['amount' => 450, 'method' => 'Cash'])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.invoice.id', $this->overdue->id)
            ->assertJsonPath('data.0.amount', '300.00')
            ->assertJsonPath('data.0.payment_date', '2026-11-10')
            ->assertJsonPath('data.1.invoice.id', $this->later->id)
            ->assertJsonPath('data.1.amount', '150.00');

        $this->assertDatabaseHas('invoices', ['id' => $this->overdue->id, 'status' => 'paid', 'balance' => '0.00']);
        $this->assertDatabaseHas('invoices', ['id' => $this->later->id, 'status' => 'partial', 'balance' => '50.00']);
        $this->assertDatabaseHas('invoices', ['id' => $this->undated->id, 'status' => 'outstanding', 'balance' => '100.00']);
        $this->assertSame('150.00', $this->customer->fresh()->balance);
        $this->assertDatabaseCount('collections', 2);
    }

    public function test_paying_exactly_what_is_owed_closes_every_invoice(): void
    {
        $this->postJson("/api/v1/customers/{$this->customer->id}/collections", ['amount' => 600, 'method' => 'Bank Transfer', 'reference' => 'TRX-9'])
            ->assertCreated()
            ->assertJsonCount(3, 'data');

        $this->assertSame(0, Invoice::query()->where('customer_id', $this->customer->id)->where('balance', '>', 0)->count());
        $this->assertSame('0.00', $this->customer->fresh()->balance);
    }

    public function test_the_payment_date_can_be_set_to_an_earlier_day(): void
    {
        $this->postJson("/api/v1/customers/{$this->customer->id}/collections", ['amount' => 50, 'method' => 'Cash', 'payment_date' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.0.payment_date', '2026-11-01');
    }

    public function test_more_than_the_customer_owes_is_refused_and_nothing_changes(): void
    {
        $this->postJson("/api/v1/customers/{$this->customer->id}/collections", ['amount' => 600.01, 'method' => 'Cash'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The amount is more than this customer owes (600.00).');

        $this->assertDatabaseCount('collections', 0);
        $this->assertSame('600.00', $this->customer->fresh()->balance);
    }

    public function test_paid_and_void_invoices_are_left_alone(): void
    {
        $this->invoice(500, '2025-12-01', 'paid', balance: 0);
        $this->invoice(700, '2025-12-02', 'void', balance: 0);

        $this->postJson("/api/v1/customers/{$this->customer->id}/collections", ['amount' => 600, 'method' => 'Cash'])->assertCreated()->assertJsonCount(3, 'data');
    }

    public function test_the_amount_and_method_are_required(): void
    {
        $this->postJson("/api/v1/customers/{$this->customer->id}/collections", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'method']);
    }

    public function test_a_role_without_collection_rights_is_forbidden(): void
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/customers/{$this->customer->id}/collections", ['amount' => 10, 'method' => 'Cash'])->assertForbidden();
    }

    public function test_another_companys_customer_is_not_found(): void
    {
        $stranger = Customer::factory()->create();

        $this->postJson("/api/v1/customers/{$stranger->id}/collections", ['amount' => 10, 'method' => 'Cash'])->assertNotFound();
    }

    private function invoice(float $total, ?string $dueDate, string $status, ?float $balance = null): Invoice
    {
        $balance ??= $total;

        return Invoice::factory()->recycle([$this->tenant, $this->customer])->create([
            'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'paid' => $total - $balance,
            'balance' => $balance, 'due_date' => $dueDate, 'status' => $status,
        ]);
    }
}
