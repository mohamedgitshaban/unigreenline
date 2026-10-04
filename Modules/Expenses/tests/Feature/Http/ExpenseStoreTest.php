<?php

namespace Modules\Expenses\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class ExpenseStoreTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/v1/expenses', [])->assertUnauthorized();
    }

    public function test_creates_a_draft_expense_with_an_uploaded_receipt_and_no_journal_entry(): void
    {
        Storage::fake();
        $user = $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();
        $warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $supplier = Supplier::factory()->recycle($this->tenant)->create();
        $receiptPath = $this->postJson('/api/v1/expenses/receipts', [
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.receipt_path');

        $response = $this->postJson('/api/v1/expenses', [
            'category_id' => $category->id,
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'bank',
            'expense_date' => '2026-10-01',
            'amount' => '1250.50',
            'reference' => 'INV-77',
            'receipt_path' => $receiptPath,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.amount', '1250.50')
            ->assertJsonPath('data.receipt_path', $receiptPath)
            ->assertJsonPath('data.created_by.id', $user->id);
        $expense = Expense::query()->findOrFail($response->json('data.id'));
        $this->assertSame($warehouse->id, $expense->warehouse_id);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Expense', 'entity_id' => $expense->id, 'operation' => 'INSERT']);
    }

    public function test_upload_stores_the_receipt_in_the_tenants_folder(): void
    {
        Storage::fake();
        $this->actingAsRole('Purchasing');

        $response = $this->postJson('/api/v1/expenses/receipts', [
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ]);

        $response->assertCreated()->assertJsonPath('data.original_name', 'receipt.jpg');
        $path = $response->json('data.receipt_path');
        $this->assertStringStartsWith("expense-receipts/{$this->tenant->id}/", $path);
        Storage::assertExists($path);
    }

    public function test_upload_rejects_a_file_that_is_not_an_image_or_pdf(): void
    {
        Storage::fake();
        $this->actingAsRole('Accountant');

        $this->postJson('/api/v1/expenses/receipts', ['receipt' => UploadedFile::fake()->create('script.exe', 10)])
            ->assertJsonValidationErrors(['receipt']);
    }

    public function test_upload_requires_expenses_add(): void
    {
        Storage::fake();
        $this->actingAsRole('Sales Rep');

        $this->postJson('/api/v1/expenses/receipts', ['receipt' => UploadedFile::fake()->image('r.jpg')])->assertForbidden();
    }

    public function test_rejects_a_receipt_path_from_another_tenant(): void
    {
        Storage::fake();
        $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();
        $foreignPath = UploadedFile::fake()->image('r.jpg')->store(Expense::receiptDirectory(Tenant::factory()->create()->id));

        $this->postJson('/api/v1/expenses', $this->payload(['category_id' => $category->id, 'receipt_path' => $foreignPath]))
            ->assertJsonValidationErrors(['receipt_path' => 'The receipt path must be a receipt uploaded via POST /expenses/receipts.']);
    }

    public function test_rejects_a_receipt_path_that_was_never_uploaded(): void
    {
        Storage::fake();
        $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();

        $this->postJson('/api/v1/expenses', $this->payload([
            'category_id' => $category->id,
            'receipt_path' => Expense::receiptDirectory($this->tenant->id).'/missing.pdf',
        ]))->assertJsonValidationErrors(['receipt_path']);
    }

    public function test_rejects_a_receipt_already_attached_to_another_expense(): void
    {
        Storage::fake();
        $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();
        $path = UploadedFile::fake()->image('r.jpg')->store(Expense::receiptDirectory($this->tenant->id));
        Expense::factory()->recycle($this->tenant)->create(['receipt_path' => $path]);

        $this->postJson('/api/v1/expenses', $this->payload(['category_id' => $category->id, 'receipt_path' => $path]))
            ->assertJsonValidationErrors(['receipt_path' => 'The receipt path is already attached to another expense.']);
    }

    public function test_purchasing_can_record_an_expense(): void
    {
        $this->actingAsRole('Purchasing');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();

        $this->postJson('/api/v1/expenses', $this->payload(['category_id' => $category->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_empty_payload_fails_required_fields(): void
    {
        $this->actingAsRole('Accountant');

        $this->postJson('/api/v1/expenses', [])
            ->assertJsonValidationErrors(['category_id', 'payment_method', 'expense_date', 'amount']);
    }

    public function test_rejects_an_inactive_category(): void
    {
        $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create(['active' => false]);

        $this->postJson('/api/v1/expenses', $this->payload(['category_id' => $category->id]))
            ->assertJsonValidationErrors(['category_id' => 'The selected category id is invalid.']);
    }

    public function test_rejects_another_tenants_category(): void
    {
        $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->create();

        $this->postJson('/api/v1/expenses', $this->payload(['category_id' => $category->id]))
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_rejects_a_zero_amount(): void
    {
        $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();

        $this->postJson('/api/v1/expenses', $this->payload(['category_id' => $category->id, 'amount' => 0]))
            ->assertJsonValidationErrors(['amount' => 'The amount field must be at least 0.01.']);
    }

    public function test_user_without_expenses_add_is_forbidden(): void
    {
        $this->actingAsRole('Sales Rep');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();

        $this->postJson('/api/v1/expenses', $this->payload(['category_id' => $category->id]))->assertForbidden();

        $this->assertDatabaseCount('expenses', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'payment_method' => 'cash',
            'expense_date' => '2026-10-01',
            'amount' => '100.00',
            ...$overrides,
        ];
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
