<?php

namespace Modules\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Loan;
use Modules\Core\Models\Tenant;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'number' => fake()->unique()->numerify('LN#####'),
            'name' => fake()->words(3, true),
            'kind' => 'borrowed',
            'principal' => 12000,
            'annual_rate_pct' => 12,
            'method' => 'annuity',
            'periods' => 12,
            'period_months' => 1,
            'grace_periods' => 0,
            'start_date' => '2026-01-01',
            'first_due_date' => '2026-02-01',
            'principal_account_code' => '2400',
            'interest_account_code' => '6600',
        ];
    }
}
