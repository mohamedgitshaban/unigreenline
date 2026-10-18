<?php

namespace Modules\HR\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\Attendance;
use Modules\HR\Models\Employee;
use Modules\HR\Support\ScheduleResolver;

/**
 * Writes attendance rows and keeps their late / early / overtime figures in
 * step with the employee's schedule, whether HR keys the day in by hand or the
 * employee checks in and out themselves.
 */
class AttendanceService
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly ScheduleResolver $schedules,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{employee_id: string, work_date: string, check_in?: ?string, check_out?: ?string, notes?: ?string}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(string $tenantId, array $data, string $source = 'manual', array $actor = []): Attendance
    {
        $employee = Employee::query()->where('tenant_id', $tenantId)->findOrFail($data['employee_id']);

        $this->assertNoRecordFor($employee, $data['work_date']);

        return DB::transaction(function () use ($tenantId, $employee, $data, $source, $actor) {
            $attendance = new Attendance([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'work_date' => $data['work_date'],
                'check_in' => $data['check_in'] ?? null,
                'check_out' => $data['check_out'] ?? null,
                'source' => $source,
                'notes' => $data['notes'] ?? null,
            ]);

            $attendance->fill($this->calculator->compute($this->schedules->forEmployee($employee), $attendance->check_in, $attendance->check_out))->save();

            $this->record($attendance, 'INSERT', null, $attendance->only(['employee_id', 'work_date', 'check_in', 'check_out']), $actor);

            return $attendance;
        });
    }

    /**
     * @param  array{work_date?: string, check_in?: ?string, check_out?: ?string, notes?: ?string}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(Attendance $attendance, array $data, array $actor = []): Attendance
    {
        return DB::transaction(function () use ($attendance, $data, $actor) {
            if (isset($data['work_date']) && $data['work_date'] !== $attendance->work_date->toDateString()) {
                $this->assertNoRecordFor($attendance->employee, $data['work_date'], $attendance->id);
            }

            $attendance->fill($data);

            if ($attendance->check_in !== null && $attendance->check_out !== null && $attendance->check_out <= $attendance->check_in) {
                throw ValidationException::withMessages(['check_out' => 'The check-out time must be after the check-in time.']);
            }

            $previous = array_intersect_key($attendance->getOriginal(), $attendance->getDirty());

            $attendance->fill($this->calculator->compute($this->schedules->forEmployee($attendance->employee), $attendance->check_in, $attendance->check_out))->save();

            $this->record($attendance, 'UPDATE', array_map('strval', $previous), $attendance->only(['work_date', 'check_in', 'check_out']), $actor);

            return $attendance;
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function checkIn(Employee $employee, array $actor = []): Attendance
    {
        $today = now()->toDateString();
        $existing = Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $today)->first();

        if ($existing?->check_in !== null) {
            throw new HrRuleException('You have already checked in today.');
        }

        if ($existing !== null) {
            return $this->update($existing, ['check_in' => now()->format('H:i:s')], $actor);
        }

        return $this->create($employee->tenant_id, ['employee_id' => $employee->id, 'work_date' => $today, 'check_in' => now()->format('H:i:s')], 'self', $actor);
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function checkOut(Employee $employee, array $actor = []): Attendance
    {
        $attendance = Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', now()->toDateString())->first();

        if ($attendance === null || $attendance->check_in === null) {
            throw new HrRuleException('You have not checked in today.');
        }

        if ($attendance->check_out !== null) {
            throw new HrRuleException('You have already checked out today.');
        }

        return $this->update($attendance, ['check_out' => now()->format('H:i:s')], $actor);
    }

    private function assertNoRecordFor(Employee $employee, string $date, ?string $ignoreId = null): void
    {
        $exists = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['work_date' => "{$employee->name} already has an attendance record for {$date}."]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(Attendance $attendance, string $operation, ?array $previous, ?array $new, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'hr',
            'entity_type' => 'Attendance',
            'entity_id' => $attendance->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $attendance->tenant_id,
            'prev_values' => $previous,
            'new_values' => array_map(fn ($v) => $v === null ? null : (string) $v, $new ?? []),
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
