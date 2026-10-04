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
use Tests\TestCase;

class ExpenseIndexReceiptTest extends TestCase
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
        $this->getJson('/api/v1/expenses')->assertUnauthorized();
    }

    public function test_index_is_scoped_to_the_tenant(): void
    {
        $this->actingAsRole('Accountant');
        $own = Expense::factory()->recycle($this->tenant)->create();
        Expense::factory()->create();

        $this->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_user_without_expenses_view_is_forbidden(): void
    {
        $this->actingAsRole('Sales Rep');

        $this->getJson('/api/v1/expenses')->assertForbidden();
    }

    public function test_cannot_view_another_tenants_expense(): void
    {
        $this->actingAsRole('Administrator');
        $expense = Expense::factory()->create();

        $this->getJson("/api/v1/expenses/{$expense->id}")->assertNotFound();
    }

    public function test_exports_expenses_as_csv(): void
    {
        $this->actingAsRole('Accountant');
        $expense = Expense::factory()->recycle($this->tenant)->create();

        $response = $this->get('/api/v1/expenses?export=csv');

        $response->assertOk()->assertDownload('expenses-'.now()->format('Y-m-d').'.csv');
        $this->assertStringContainsString($expense->id, $response->streamedContent());
    }

    public function test_updating_the_receipt_path_deletes_the_old_file(): void
    {
        Storage::fake();
        $this->actingAsRole('Accountant');
        $directory = Expense::receiptDirectory($this->tenant->id);
        $oldPath = UploadedFile::fake()->image('old.jpg')->store($directory);
        $newPath = UploadedFile::fake()->image('new.png')->store($directory);
        $expense = Expense::factory()->recycle($this->tenant)->create(['receipt_path' => $oldPath]);

        $this->putJson("/api/v1/expenses/{$expense->id}", ['receipt_path' => $newPath])
            ->assertOk()
            ->assertJsonPath('data.receipt_path', $newPath);

        Storage::assertMissing($oldPath);
        Storage::assertExists($newPath);
    }

    public function test_keeping_the_same_receipt_path_on_update_keeps_the_file(): void
    {
        Storage::fake();
        $this->actingAsRole('Accountant');
        $path = UploadedFile::fake()->image('r.jpg')->store(Expense::receiptDirectory($this->tenant->id));
        $expense = Expense::factory()->recycle($this->tenant)->create(['receipt_path' => $path]);

        $this->putJson("/api/v1/expenses/{$expense->id}", ['receipt_path' => $path, 'amount' => '10.00'])->assertOk();

        Storage::assertExists($path);
    }

    public function test_downloads_the_receipt(): void
    {
        Storage::fake();
        $this->actingAsRole('Accountant');
        $path = UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')->store('expense-receipts');
        $expense = Expense::factory()->recycle($this->tenant)->create(['receipt_path' => $path]);

        $this->get("/api/v1/expenses/{$expense->id}/receipt")
            ->assertOk()
            ->assertDownload("receipt-{$expense->id}.pdf");
    }

    public function test_downloading_a_missing_receipt_returns_404(): void
    {
        $this->actingAsRole('Accountant');
        $expense = Expense::factory()->recycle($this->tenant)->create(['receipt_path' => null]);

        $this->getJson("/api/v1/expenses/{$expense->id}/receipt")->assertNotFound();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
