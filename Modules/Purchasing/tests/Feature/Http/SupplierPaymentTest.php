<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierPayment;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class SupplierPaymentTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->supplier = Supplier::factory()->recycle($this->tenant)->create(['balance' => -1000]);
    }

    public function test_an_accountant_pays_a_supplier_which_clears_payable_and_cash(): void
    {
        $this->actingAsRole('Accountant');

        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 400, 'method' => 'bank', 'reference' => 'TRF-9'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '400.00')
            ->assertJsonPath('data.supplier.id', $this->supplier->id);

        $this->assertSame('-600.00', $this->supplier->fresh()->balance);
        $this->assertSame('-400.00', $this->balance('2100'));   // debit on a liability
        $this->assertSame('-400.00', $this->balance('1120'));   // credit on an asset
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'SupplierPayment', 'operation' => 'INSERT']);
    }

    public function test_cash_payments_use_the_cash_account(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 100, 'method' => 'cash'])->assertCreated();

        $this->assertSame('-100.00', $this->balance('1110'));
        $this->assertSame('0.00', $this->balance('1120'));
    }

    public function test_a_payment_cannot_exceed_what_is_owed(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 1000.01, 'method' => 'bank'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $settled = Supplier::factory()->recycle($this->tenant)->create(['balance' => 0]);
        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $settled->id, 'amount' => 1, 'method' => 'bank'])
            ->assertUnprocessable()->assertJsonPath('message', "Nothing is owed to {$settled->name}.");

        $this->assertSame('-1000.00', $this->supplier->fresh()->balance);
        $this->assertSame(0, SupplierPayment::query()->count());
    }

    public function test_the_purchase_order_must_be_the_suppliers_and_received(): void
    {
        $this->actingAsRole('Administrator');
        $other = Supplier::factory()->recycle($this->tenant)->create();
        $foreignOrder = PurchaseOrder::factory()->recycle([$this->tenant, $other])->create(['stock_added' => true]);
        $unreceived = PurchaseOrder::factory()->recycle([$this->tenant, $this->supplier])->create(['stock_added' => false]);
        $received = PurchaseOrder::factory()->recycle([$this->tenant, $this->supplier])->create(['stock_added' => true]);

        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 10, 'method' => 'bank', 'purchase_order_id' => $foreignOrder->id])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id');
        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 10, 'method' => 'bank', 'purchase_order_id' => $unreceived->id])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id');
        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 10, 'method' => 'bank', 'purchase_order_id' => $received->id])
            ->assertCreated();
    }

    public function test_lists_shows_and_exports_but_has_no_edit_or_delete(): void
    {
        $this->actingAsRole('Accountant');
        $payment = SupplierPayment::factory()->recycle([$this->tenant, $this->supplier])->create();

        $this->getJson('/api/v1/supplier-payments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/supplier-payments/{$payment->id}")->assertOk()->assertJsonPath('data.id', $payment->id);
        $this->get('/api/v1/supplier-payments?export=csv')->assertOk()->assertDownload();
        $this->putJson("/api/v1/supplier-payments/{$payment->id}", ['amount' => 1])->assertStatus(405);
        $this->deleteJson("/api/v1/supplier-payments/{$payment->id}")->assertStatus(405);
    }

    public function test_permissions_and_tenancy(): void
    {
        $payment = SupplierPayment::factory()->create();

        $this->actingAsRole('Sales Manager');
        $this->getJson('/api/v1/supplier-payments')->assertForbidden();
        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 1, 'method' => 'cash'])->assertForbidden();

        $this->actingAsRole('Administrator');
        $this->getJson("/api/v1/supplier-payments/{$payment->id}")->assertNotFound();
    }

    private function balance(string $code): string
    {
        return Account::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail()->balance;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
