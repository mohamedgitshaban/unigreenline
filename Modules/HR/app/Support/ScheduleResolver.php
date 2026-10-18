<?php

namespace Modules\HR\Support;

use Modules\HR\Models\Employee;
use Modules\HR\Models\WorkSchedule;

/**
 * Which schedule applies to an employee: their own, else the company default,
 * else the built-in one from config — so attendance and payroll always have a
 * schedule to measure against.
 */
class ScheduleResolver
{
    /** @var array<string, WorkSchedule> */
    private array $defaults = [];

    public function forEmployee(Employee $employee): WorkSchedule
    {
        return $employee->workSchedule
            ?? $this->companyDefault($employee->tenant_id);
    }

    private function companyDefault(string $tenantId): WorkSchedule
    {
        return $this->defaults[$tenantId] ??= WorkSchedule::query()->where('tenant_id', $tenantId)->where('is_default', true)->first()
            ?? $this->builtIn($tenantId);
    }

    private function builtIn(string $tenantId): WorkSchedule
    {
        $config = config('hr.default_schedule');

        return new WorkSchedule([
            'tenant_id' => $tenantId,
            'name' => 'Built-in default',
            'start_time' => $config['start'].':00',
            'end_time' => $config['end'].':00',
            'break_minutes' => $config['break_minutes'],
            'grace_minutes' => $config['grace_minutes'],
            'weekend_days' => $config['weekend_days'],
            'is_default' => true,
        ]);
    }
}
