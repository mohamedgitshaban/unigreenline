<?php

namespace Modules\HR\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\WorkSchedule;

/**
 * Gives every company a default working schedule (9 to 5, Friday/Saturday
 * weekend) so attendance and payroll have something to measure against.
 * Departments, employees and holidays are the company's own to enter.
 */
class HRDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::query()->get() as $tenant) {
            WorkSchedule::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Standard'],
                ['start_time' => '09:00:00', 'end_time' => '17:00:00', 'break_minutes' => 0, 'grace_minutes' => 15, 'weekend_days' => [5, 6], 'is_default' => true],
            );
        }
    }
}
