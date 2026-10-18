<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\Employee;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => fake()->unique()->numerify('EMP-####'),
            'name' => fake()->name(),
            'hire_date' => '2020-01-01',
            'status' => 'active',
            'basic_salary' => 3000,
            'allowances' => 0,
            'insurance_pct' => 0,
            'tax_pct' => 0,
            'overtime_eligible' => false,
            'annual_leave_days' => 21,
        ];
    }
}
