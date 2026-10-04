<?php

namespace Modules\Expenses\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Expenses\Models\ExpenseCategory;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
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
        $this->getJson('/api/v1/expense-categories')->assertUnauthorized();
    }

    public function test_creates_a_category_mapped_to_an_expense_account(): void
    {
        $this->actingAsRole('Accountant');
        $account = Account::factory()->recycle($this->tenant)->create(['type' => 'Expense']);

        $response = $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'account_id' => $account->id]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Rent')
            ->assertJsonPath('data.account.code', $account->code);
        $this->assertDatabaseHas('expense_categories', ['tenant_id' => $this->tenant->id, 'name' => 'Rent', 'account_id' => $account->id]);
    }

    public function test_rejects_a_non_expense_account(): void
    {
        $this->actingAsRole('Accountant');
        $account = Account::factory()->recycle($this->tenant)->create(['type' => 'Asset']);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'account_id' => $account->id])
            ->assertJsonValidationErrors(['account_id' => 'The selected account id is invalid.']);
    }

    public function test_rejects_a_duplicate_name_within_the_tenant(): void
    {
        $this->actingAsRole('Accountant');
        $existing = ExpenseCategory::factory()->recycle($this->tenant)->create();

        $this->postJson('/api/v1/expense-categories', ['name' => $existing->name, 'account_id' => $existing->account_id])
            ->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
    }

    public function test_user_without_expenses_add_is_forbidden(): void
    {
        $this->actingAsRole('Sales Rep');
        $account = Account::factory()->recycle($this->tenant)->create(['type' => 'Expense']);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent', 'account_id' => $account->id])->assertForbidden();
    }

    public function test_updates_a_category(): void
    {
        $this->actingAsRole('Accountant');
        $category = ExpenseCategory::factory()->recycle($this->tenant)->create();

        $this->putJson("/api/v1/expense-categories/{$category->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }

    public function test_cannot_update_another_tenants_category(): void
    {
        $this->actingAsRole('Administrator');
        $category = ExpenseCategory::factory()->create();

        $this->putJson("/api/v1/expense-categories/{$category->id}", ['name' => 'Hijacked'])->assertNotFound();
    }

    public function test_index_is_scoped_to_the_tenant(): void
    {
        $this->actingAsRole('Accountant');
        $own = ExpenseCategory::factory()->recycle($this->tenant)->create();
        ExpenseCategory::factory()->create();

        $this->getJson('/api/v1/expense-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
