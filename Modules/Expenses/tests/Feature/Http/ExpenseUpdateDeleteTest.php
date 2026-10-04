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

class ExpenseUpdateDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_updates_a_draft_expense(): void
    {
        $this->actingAsRole('Accountant');
        $expense = Expense::factory()->recycle($this->tenant)->create(['amount' => '100.00']);

        $this->putJson("/api/v1/expenses/{$expense->id}", ['amount' => '150.00', 'payee' => 'Landlord'])
            ->assertOk()
            ->assertJsonPath('data.amount', '150.00')
            ->assertJsonPath('data.payee', 'Landlord');

        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Expense', 'entity_id' => $expense->id, 'operation' => 'UPDATE']);
    }

    public function test_an_approved_expense_cannot_be_edited(): void
    {
        $this->actingAsRole('Accountant');
        $expense = Expense::factory()->recycle($this->tenant)->approved()->create(['amount' => '100.00']);

        $this->putJson("/api/v1/expenses/{$expense->id}", ['amount' => '150.00'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only draft expenses can be changed.');

        $this->assertSame('100.00', $expense->fresh()->amount);
    }

    public function test_cannot_update_another_tenants_expense(): void
    {
        $this->actingAsRole('Administrator');
        $expense = Expense::factory()->create();

        $this->putJson("/api/v1/expenses/{$expense->id}", ['amount' => '1.00'])->assertNotFound();
    }

    public function test_deletes_a_draft_expense_and_its_receipt(): void
    {
        Storage::fake();
        $this->actingAsRole('Administrator');
        $path = UploadedFile::fake()->image('receipt.jpg')->store('expense-receipts');
        $expense = Expense::factory()->recycle($this->tenant)->create(['receipt_path' => $path]);

        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertNoContent();

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
        Storage::assertMissing($path);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Expense', 'entity_id' => $expense->id, 'operation' => 'DELETE']);
    }

    public function test_an_approved_expense_cannot_be_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $expense = Expense::factory()->recycle($this->tenant)->approved()->create();

        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertUnprocessable();

        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    }

    public function test_user_without_expenses_delete_is_forbidden(): void
    {
        $this->actingAsRole('Accountant');
        $expense = Expense::factory()->recycle($this->tenant)->create();

        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertForbidden();

        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    }

    public function test_cannot_delete_another_tenants_expense(): void
    {
        $this->actingAsRole('Administrator');
        $expense = Expense::factory()->create();

        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertNotFound();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
