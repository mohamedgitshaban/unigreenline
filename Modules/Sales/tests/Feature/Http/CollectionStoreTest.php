<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;
use Tests\TestCase;

class CollectionStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_records_a_collection(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        Account::factory()->recycle($tenant)->create(['code' => '1110', 'type' => 'Asset']);
        Account::factory()->recycle($tenant)->create(['code' => '1200', 'type' => 'Asset']);

        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create(['total' => 500, 'balance' => 500, 'paid' => 0]);

        $response = $this->postJson('/api/v1/collections', [
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'method' => 'Cash',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '500.00');
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_amount_over_balance_returns_422(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create(['total' => 500, 'balance' => 200, 'paid' => 300]);

        $response = $this->postJson('/api/v1/collections', [
            'invoice_id' => $invoice->id,
            'amount' => 201,
            'method' => 'Cash',
        ]);

        $response->assertUnprocessable();
    }

    public function test_customer_service_role_cannot_record_a_collection(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create();

        $response = $this->postJson('/api/v1/collections', [
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'method' => 'Cash',
        ]);

        $response->assertForbidden();
    }
}
