<?php

namespace Modules\Expenses\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\Expenses\Models\ExpenseCategory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'account_id' => Account::factory()->state(['type' => 'Expense']),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'active' => true,
        ];
    }
}
