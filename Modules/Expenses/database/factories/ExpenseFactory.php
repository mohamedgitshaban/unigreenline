<?php

namespace Modules\Expenses\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseCategory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'category_id' => ExpenseCategory::factory(),
            'warehouse_id' => null,
            'supplier_id' => null,
            'payee' => fake()->company(),
            'created_by' => User::factory(),
            'status' => 'draft',
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'reference' => null,
            'description' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_at' => now()]);
    }
}
