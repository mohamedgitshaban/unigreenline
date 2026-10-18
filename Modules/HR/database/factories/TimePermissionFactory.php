<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\Employee;
use Modules\HR\Models\TimePermission;

/**
 * @extends Factory<TimePermission>
 */
class TimePermissionFactory extends Factory
{
    protected $model = TimePermission::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => Employee::factory(),
            'type' => 'late_arrival',
            'permission_date' => now()->toDateString(),
            'from_time' => '09:00:00',
            'to_time' => '10:00:00',
            'minutes' => 60,
            'status' => 'pending',
        ];
    }
}
