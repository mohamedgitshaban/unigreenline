<?php

namespace Modules\Core\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\ReturnRecord;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsWorkbooks;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class ProductionImportTest extends TestCase
{
    use BuildsWorkbooks, EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->admin = $this->userWithRole('Administrator');
    }

    public function test_loads_warehouses_suppliers_products_and_customers_from_their_sheets(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets())])->assertSuccessful();

        $this->assertSame(['Branch Warehouse', 'Main Warehouse'], Warehouse::query()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('warehouses', ['name' => 'Main Warehouse', 'city' => '', 'governorate' => '', 'status' => 'active']);

        $this->assertSame(['Interpharma', 'Vetcomed'], Supplier::query()->orderBy('name')->pluck('name')->all());

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['sku' => 'IP-1', 'name' => 'Equiyeast 3 kg', 'pack_unit' => 'Pack', 'carton_qty' => 1, 'pack_cost_price' => '109.27', 'tax_pct' => '0.00', 'active' => 1]);
        $this->assertSame('Supplement', Product::query()->where('sku', 'IP-1')->firstOrFail()->category()->value('name'));
        $this->assertSame('Uncategorized', Product::query()->where('sku', 'OT-1')->firstOrFail()->category()->value('name'));
        $this->assertDatabaseHas('products', ['sku' => 'OT-1', 'name' => 'Delivery service', 'pack_unit' => 'Pack', 'pack_cost_price' => '0.00']);
        $this->assertSame(2, ProductCategory::query()->count());
    }

    public function test_keeps_the_customer_code_so_two_customers_can_share_a_name(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets())])->assertSuccessful();

        $this->assertDatabaseCount('customers', 4);
        $this->assertSame(2, Customer::query()->where('name', 'El Tobgy Co.')->count());
        $this->assertDatabaseHas('customers', ['code' => 'VP-1', 'name' => 'Ahmed Vet', 'type' => 'Other', 'pay_terms' => 'Account', 'status' => 'active', 'credit_limit' => '50000.00', 'phone' => null]);
        $this->assertDatabaseHas('customers', ['code' => 'EY-1', 'pay_terms' => 'Cash']);
        $this->assertDatabaseHas('customers', ['code' => 'TG-1', 'status' => 'inactive']);
    }

    public function test_turns_the_invoice_lines_into_invoices_and_returns_and_skips_free_documents(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets())])->assertSuccessful();

        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseHas('invoices', ['number' => 'SI-1', 'issued_date' => '2024-06-06', 'subtotal' => '645.00', 'tax_amount' => '0.00', 'total' => '645.00', 'balance' => '645.00', 'status' => 'outstanding']);
        $this->assertDatabaseHas('invoices', ['number' => 'SI-3', 'total' => '360.00']);
        $this->assertDatabaseMissing('invoices', ['number' => 'SI-2']);

        $this->assertDatabaseCount('returns', 1);
        $return = ReturnRecord::query()->firstOrFail();
        $this->assertSame('Sales Return', $return->type);
        $this->assertSame('300.00', $return->amount);
        $this->assertSame(2, $return->qty);
        $this->assertSame('2024-06-20', $return->return_date->toDateString());
        $this->assertFalse($return->restocked);
        $this->assertSame(Product::query()->where('sku', 'IP-1')->value('id'), $return->product_id);
    }

    public function test_the_returns_credit_comes_off_the_customers_balance_and_the_receivable(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets())])->assertSuccessful();

        // Ahmed Vet: 645 invoiced, 300 returned. El Tobgy (cash customer): 360.
        $this->assertSame('345.00', Customer::query()->where('code', 'VP-1')->firstOrFail()->balance);
        $this->assertSame('360.00', Customer::query()->where('code', 'TG-2')->firstOrFail()->balance);
        $this->assertSame('705.00', Account::query()->where('code', '1200')->firstOrFail()->balance);
        $this->assertSame('705.00', Account::query()->where('code', '4100')->firstOrFail()->balance);
    }

    public function test_importing_the_same_workbook_again_adds_nothing(): void
    {
        $file = $this->writeWorkbook($this->sheets());
        $this->artisan('production:import', ['file' => $file])->assertSuccessful();

        $this->artisan('production:import', ['file' => $file])->assertSuccessful();

        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(1, ReturnRecord::query()->count());
        $this->assertSame(4, Customer::query()->count());
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(2, Warehouse::query()->count());
        $this->assertSame(2, Supplier::query()->count());
        $this->assertSame('345.00', Customer::query()->where('code', 'VP-1')->firstOrFail()->balance);
    }

    public function test_a_dry_run_checks_everything_and_saves_nothing(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets()), '--dry-run' => true])
            ->expectsOutputToContain('Nothing was saved')
            ->assertSuccessful();

        foreach (['warehouses', 'suppliers', 'products', 'customers', 'invoices', 'returns', 'journal_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('0.00', Account::query()->where('code', '1200')->firstOrFail()->balance);
    }

    public function test_one_bad_row_saves_nothing_from_any_sheet_and_names_the_row(): void
    {
        $sheets = $this->sheets();
        $sheets['Invoice Data 2024, 25, 26'][] = ['SI-9', 45470, 'ZZ-9', 'Nobody', 'IP-1', 'Equiyeast 3 kg', 1, 100, 0];

        $this->artisan('production:import', ['file' => $this->writeWorkbook($sheets)])
            ->expectsOutputToContain('Nothing was imported. The invoices sheet has 1 row(s) to fix')
            ->expectsOutputToContain('Row 8 (customer_code): No customer with code "ZZ-9".')
            ->assertFailed();

        $this->assertDatabaseCount('warehouses', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_returns_for_a_product_that_was_not_imported_are_rejected(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets()), '--only' => ['customers', 'invoices']])
            ->expectsOutputToContain('No product with code "IP-1". Import the products first.')
            ->assertFailed();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_the_only_option_imports_just_those_sheets(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets()), '--only' => ['customers']])->assertSuccessful();

        $this->assertDatabaseCount('customers', 4);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_rejects_an_unknown_only_value_and_an_unreadable_file(): void
    {
        $this->artisan('production:import', ['file' => $this->writeWorkbook($this->sheets()), '--only' => ['customers', 'payroll']])
            ->expectsOutputToContain('Unknown --only value(s): payroll')
            ->assertFailed();

        $this->artisan('production:import', ['file' => '/no/such/workbook.xlsx'])
            ->expectsOutputToContain('Cannot read the file')
            ->assertFailed();
    }

    public function test_needs_one_account_or_the_one_named_with_the_user_option(): void
    {
        $file = $this->writeWorkbook($this->sheets());
        $second = $this->userWithRole('Accountant');

        $this->artisan('production:import', ['file' => $file])
            ->expectsOutputToContain('more than one account')
            ->assertFailed();

        $this->artisan('production:import', ['file' => $file, '--user' => $second->email, '--only' => ['customers']])->assertSuccessful();
        $this->artisan('production:import', ['file' => $file, '--user' => 'nobody@example.com'])
            ->expectsOutputToContain('No account with the email nobody@example.com')
            ->assertFailed();

        User::query()->delete();
        $this->artisan('production:import', ['file' => $file])
            ->expectsOutputToContain('Run production:reset first')
            ->assertFailed();
    }

    /**
     * The company's workbook in miniature: the sheets in its own order and layout, "NEEDED" cells, a title above
     * the warehouse headings, trailing spaces in names, formula-only filler rows under the invoices.
     *
     * @return array<string, list<list<mixed>>>
     */
    private function sheets(): array
    {
        $filler = '=IF(A2="","",G2*H2*(1-I2))';

        return [
            'Marketing Expansions ' => [['Date ', 'Item ', 'Cost  '], ['Aug.2025', 'Posters', 500]],
            'Customer Master Sheet' => [
                ['Customer Code', 'acc.name', 'Customer Type', 'Customer Category', 'Governorate', 'City', 'Phone', 'Credit Limit', 'Currency', 'Active Status'],
                ['VP-1', 'Ahmed Vet', 'Account / collection customer', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'EGP', 'Active'],
                ['EY-1', 'Alfarida Stud', 'Cash customer', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'EGP', 'Active'],
                ['TG-1', 'El Tobgy Co.', 'Account / collection customer', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'EGP', 'Collection-only'],
                ['TG-2', 'El Tobgy Co.', 'Cash customer', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'NEEDED', 'EGP', 'Active'],
            ],
            'Product Master Sheet ' => [
                ['Product Code', 'Product Name Arabic', 'Product Name English', 'Category', 'Packaging', 'Tax Category', 'Standard Cost', 'Sales price  ', 'Active Status'],
                ['IP-1', 'أكوين', 'Equiyeast 3 kg', 'Supplement ', 'Pack ', 'Non Taxable ', 109.27, null, 'Active'],
                ['OT-1', 'NEEDED', 'Delivery service', 'NEEDED', 'NEEDED', 'Non Taxable ', 'NEEDED', null, 'Active'],
            ],
            'Warehouse Master' => [
                ['سجل المخازن (Warehouse Master)'],
                [],
                ['كود المخزن', 'Warehouse Name', 'اسم المخزن', 'نوع المخزن', null, 'النمط'],
                ['WM-1', 'Main Warehouse', 'المخزن الرئيسي', 'Main', null, 'WM-240601'],
                ['BW-1', 'Branch Warehouse', 'المخزن الفرعي', 'Branch', null, 'meaning of the codes'],
            ],
            'Invoice Data 2024, 25, 26' => [
                ['Invoice Number', 'Date', 'Customer Code ', 'Customer Name', 'Product Code', 'Product', 'Units', 'Price', 'Discount %', 'Line Total'],
                ['SI-1', 45449, 'VP-1', 'Ahmed Vet', 'IP-1', 'Equiyeast 3 kg', 1, 285, 0, $filler],
                ['SI-1', 45449, 'VP-1', 'Ahmed Vet', 'OT-1', 'Delivery service', 2, 180, 0, $filler],
                ['SI-2', 45452, 'EY-1', 'Alfarida Stud', 'IP-1', 'Equiyeast 3 kg', 3, 250, 1, $filler],
                ['SI-3', 45455, 'TG-2', 'El Tobgy Co.', 'IP-1', 'Equiyeast 3 kg', 4, 100, 0.1, $filler],
                ['RT-1', 45463, 'VP-1', 'Ahmed Vet', 'IP-1', 'Equiyeast 3 kg', 2, -150, 0, $filler],
                [null, null, null, null, null, null, null, null, null, $filler],
            ],
            'Production 24, 25, 26' => [
                ['Po. Numer   ', 'supplier or manufacturer', 'Product Code'],
                ['MO-1', 'Interpharma', 'IP-1'],
                ['MO-2', 'Interpharma', 'IP-1'],
                ['MO-3', 'Vetcomed', 'OT-1'],
            ],
        ];
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role);

        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);

        return $user;
    }
}
