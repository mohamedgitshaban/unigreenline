<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Collection;
use Modules\Sales\Models\Invoice;
use Tests\TestCase;

class InvoiceVoidImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['name' => 'Nile Clinic', 'balance' => 0]);
        foreach (['1110' => 'Asset', '1200' => 'Asset', '2300' => 'Liability', '4100' => 'Revenue'] as $code => $type) {
            Account::factory()->recycle($this->tenant)->create(['code' => $code, 'type' => $type, 'balance' => 0]);
        }
    }

    public function test_imports_invoices_posting_the_same_entries_a_live_invoice_would(): void
    {
        $this->actingAsRole('Administrator');
        $csv = "Customer,Issued Date,Due Date,Subtotal,Tax Amount,Paid\n"
            ."Nile Clinic,2026-01-10,2099-02-10,1000,140,0\n"
            ."Nile Clinic,2026-01-12,2099-02-12,500,0,200\n";

        $this->postJson('/api/v1/invoices/import', ['file' => UploadedFile::fake()->createWithContent('invoices.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('created', 2);

        $this->assertDatabaseHas('invoices', ['customer_id' => $this->customer->id, 'total' => '1140.00', 'balance' => '1140.00', 'status' => 'outstanding']);
        $this->assertDatabaseHas('invoices', ['customer_id' => $this->customer->id, 'total' => '500.00', 'paid' => '200.00', 'balance' => '300.00', 'status' => 'partial']);
        // Receivable: 1140 + 500 − 200 collected.
        $this->assertSame('1440.00', Account::query()->where('code', '1200')->first()->balance);
        $this->assertSame('1500.00', Account::query()->where('code', '4100')->first()->balance);
        $this->assertSame('140.00', Account::query()->where('code', '2300')->first()->balance);
        $this->assertSame('200.00', Account::query()->where('code', '1110')->first()->balance);
        $this->assertSame('1440.00', $this->customer->fresh()->balance);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Invoice', 'operation' => 'IMPORT']);
    }

    public function test_a_bad_row_rejects_the_whole_file_and_reports_the_row(): void
    {
        $this->actingAsRole('Administrator');
        $csv = "Customer,Issued Date,Subtotal\nNile Clinic,2026-01-10,100\nNobody,2026-01-11,100\nNile Clinic,not-a-date,100\n";

        $this->postJson('/api/v1/invoices/import', ['file' => UploadedFile::fake()->createWithContent('invoices.csv', $csv)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 3)
            ->assertJsonPath('errors.1.row', 4);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame('0.00', Account::query()->where('code', '1200')->first()->balance);
    }

    public function test_import_needs_create_permission_and_the_required_columns(): void
    {
        $file = UploadedFile::fake()->createWithContent('invoices.csv', "Customer,Subtotal\nNile Clinic,10\n");

        $this->actingAsRole('Auditor');
        $this->postJson('/api/v1/invoices/import', ['file' => $file])->assertForbidden();

        $this->actingAsRole('Administrator');
        $this->postJson('/api/v1/invoices/import', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Missing required column(s): issued_date.');
    }

    public function test_downloads_the_import_template(): void
    {
        $this->actingAsRole('Administrator');

        $this->get('/api/v1/invoices/import-template')->assertOk()->assertDownload('invoices-import-template.xlsx');
    }

    public function test_voiding_reverses_the_journal_and_returns_the_balance_to_the_customer(): void
    {
        $this->actingAsRole('Administrator');
        $this->customer->update(['balance' => 1140]);
        $invoice = Invoice::factory()->recycle($this->tenant)->create(['customer_id' => $this->customer->id, 'subtotal' => 1000, 'tax_amount' => 140, 'total' => 1140, 'balance' => 1140]);
        Account::query()->where('code', '1200')->update(['balance' => 1140]);
        Account::query()->where('code', '4100')->update(['balance' => 1000]);
        Account::query()->where('code', '2300')->update(['balance' => 140]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        $this->assertSame('0.00', Account::query()->where('code', '1200')->first()->balance);
        $this->assertSame('0.00', Account::query()->where('code', '4100')->first()->balance);
        $this->assertSame('0.00', Account::query()->where('code', '2300')->first()->balance);
        $this->assertSame('0.00', $this->customer->fresh()->balance);
        $this->postJson("/api/v1/invoices/{$invoice->id}/void")->assertUnprocessable();
    }

    public function test_an_invoice_with_collections_cannot_be_voided(): void
    {
        $this->actingAsRole('Administrator');
        $invoice = Invoice::factory()->recycle($this->tenant)->create(['customer_id' => $this->customer->id, 'paid' => 100, 'balance' => 900, 'status' => 'partial']);
        Collection::factory()->recycle($this->tenant)->create(['invoice_id' => $invoice->id, 'customer_id' => $this->customer->id]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/void")->assertUnprocessable();
        $this->assertSame('partial', $invoice->fresh()->status);
    }

    public function test_voiding_needs_the_delete_permission(): void
    {
        $invoice = Invoice::factory()->recycle($this->tenant)->create(['customer_id' => $this->customer->id]);

        $this->actingAsRole('Sales Manager');
        $this->postJson("/api/v1/invoices/{$invoice->id}/void")->assertForbidden();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
