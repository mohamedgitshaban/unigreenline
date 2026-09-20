<?php

namespace Modules\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(['Asset', 'Liability', 'Equity', 'Revenue', 'Expense']),
            'level' => 1,
            'parent_id' => null,
            'active' => true,
        ];
    }
}
