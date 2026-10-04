<?php

namespace Modules\Expenses\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseCategory;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExpenseApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->expenseAccount = Account::factory()->recycle($this->tenant)->create(['code' => '6200', 'type' => 'Expense', 'balance' => 0]);
        Account::factory()->recycle($this->tenant)->create(['code' => '1110', 'type' => 'Asset', 'balance' => 5000]);
        Account::factory()->recycle($this->tenant)->create(['code' => '1120', 'type' => 'Asset', 'balance' => 5000]);
    }

    public function test_approving_posts_a_journal_entry_crediting_the_payment_account(): void
    {
        $accountant = $this->actingAsRole('Accountant');
        $expense = $this->expense(['payment_method' => 'bank', 'amount' => '300.00', 'expense_date' => '2026-09-15']);

        $response = $this->postJson("/api/v1/expenses/{$expense->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by.id', $accountant->id);
        $expense->refresh();
        $this->assertDatabaseHas('journal_entries', ['id' => $expense->journal_entry_id, 'ref' => $expense->id, 'entry_date' => '2026-09-15']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $expense->journal_entry_id, 'account_code' => '6200', 'debit' => '300.00', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $expense->journal_entry_id, 'account_code' => '1120', 'debit' => '0.00', 'credit' => '300.00']);
        $this->assertSame('300.00', $this->expenseAccount->fresh()->balance);
        $this->assertSame('5000.00', Account::query()->where('code', '1110')->first()->balance);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Expense', 'entity_id' => $expense->id, 'operation' => 'APPROVE']);
    }

    public function test_an_approved_expense_cannot_be_approved_again(): void
    {
        $this->actingAsRole('Accountant');
        $expense = $this->expense();
        $this->postJson("/api/v1/expenses/{$expense->id}/approve")->assertOk();

        $this->postJson("/api/v1/expenses/{$expense->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only draft expenses can be changed.');

        $this->assertDatabaseCount('journal_entries', 1);
    }

    #[TestWith(['Owner'])]
    #[TestWith(['Purchasing'])]
    public function test_roles_without_expenses_approve_cannot_approve_or_reject(string $role): void
    {
        $this->actingAsRole($role);
        $expense = $this->expense();

        $this->postJson("/api/v1/expenses/{$expense->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/expenses/{$expense->id}/reject")->assertForbidden();

        $this->assertSame('draft', $expense->fresh()->status);
    }

    public function test_cannot_approve_another_tenants_expense(): void
    {
        $this->actingAsRole('Administrator');
        $expense = Expense::factory()->create();

        $this->postJson("/api/v1/expenses/{$expense->id}/approve")->assertNotFound();
    }

    public function test_rejecting_records_the_reason_and_posts_nothing(): void
    {
        $this->actingAsRole('Accountant');
        $expense = $this->expense();

        $this->postJson("/api/v1/expenses/{$expense->id}/reject", ['reason' => 'No receipt'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'No receipt');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_rejected_expense_cannot_be_approved(): void
    {
        $this->actingAsRole('Accountant');
        $expense = $this->expense(['status' => 'rejected']);

        $this->postJson("/api/v1/expenses/{$expense->id}/approve")->assertUnprocessable();

        $this->assertDatabaseCount('journal_entries', 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function expense(array $attributes = []): Expense
    {
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create(['account_id' => $this->expenseAccount->id]);

        return Expense::factory()->recycle($this->tenant)->create(['category_id' => $category->id, ...$attributes]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
