<?php

namespace Modules\HR\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\Employee;
use Modules\HR\Models\PayrollRun;
use Modules\HR\Support\ScheduleResolver;
use Modules\HR\Support\WorkCalendar;

/**
 * Calculates a month's payroll: one draft run, one payslip per employee on the
 * payroll that month (hired by month-end and still active, or who left during
 * it). Only possible once the month has ended — the nightly job does it on the
 * last day — because a half-finished month would count the days still to come as
 * absences. A draft can be recalculated after attendance is corrected, or deleted;
 * nothing touches the ledger until it is approved.
 */
class GeneratePayrollService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly ScheduleResolver $schedules,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function generate(string $tenantId, string $period, ?string $notes = null, array $actor = []): PayrollRun
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        if ($end->gt(today())) {
            throw new HrRuleException('Payroll can only be calculated once the month has ended — on its last day or later.');
        }

        if (PayrollRun::query()->where('tenant_id', $tenantId)->where('period', $period)->exists()) {
            throw ValidationException::withMessages(['period' => "A payroll run for {$period} already exists."]);
        }

        return DB::transaction(function () use ($tenantId, $period, $notes, $start, $end, $actor) {
            $run = PayrollRun::create([
                'tenant_id' => $tenantId,
                'period' => $period,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'notes' => $notes,
                'created_by' => $actor['actor_id'] ?? null,
            ]);

            $this->fill($run);

            $this->record($run, 'INSERT', ['period' => $period, 'employees' => (string) $run->employees_count, 'net' => (string) $run->total_net], $actor);

            return $run;
        });
    }

    /**
     * Recomputes a draft from the attendance, leave and permissions as they are now.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function recalculate(PayrollRun $run, array $actor = []): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new HrRuleException('Only a draft payroll run can be recalculated.');
            }

            $locked->payslips()->delete();
            $this->fill($locked);

            $this->record($locked, 'UPDATE', ['recalculated' => 'yes', 'net' => (string) $locked->total_net], $actor);

            return $locked;
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function delete(PayrollRun $run, array $actor = []): void
    {
        DB::transaction(function () use ($run, $actor) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new HrRuleException('Only a draft payroll run can be deleted — an approved one has already posted to the ledger.');
            }

            $locked->delete();

            $this->record($locked, 'DELETE', null, $actor, ['period' => $locked->period, 'status' => $locked->status]);
        });
    }

    private function fill(PayrollRun $run): void
    {
        $start = $run->period_start;
        $end = $run->period_end;
        $calendar = WorkCalendar::between($run->tenant_id, $start, $end);

        $employees = Employee::query()
            ->with('workSchedule')
            ->where('tenant_id', $run->tenant_id)
            ->whereDate('hire_date', '<=', $end->toDateString())
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('termination_date')->where('status', 'active'))
                ->orWhereDate('termination_date', '>=', $start->toDateString()))
            ->orderBy('code')
            ->get();

        $slips = $employees
            ->map(fn (Employee $employee) => $this->calculator->calculate($employee, $start, $end, $calendar))
            ->filter()
            ->values();

        if ($slips->isEmpty()) {
            throw new HrRuleException("No employees are on the payroll for {$run->period}.");
        }

        foreach ($slips as $slip) {
            $run->payslips()->create($slip);
        }

        $run->update([
            'employees_count' => $slips->count(),
            'total_earned' => round($slips->sum('earned_pay'), 2),
            'total_withheld' => round($slips->sum(fn (array $s) => $s['insurance'] + $s['tax']), 2),
            'total_net' => round($slips->sum('net_pay'), 2),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     * @param  array<string, mixed>|null  $previous
     */
    private function record(PayrollRun $run, string $operation, ?array $new, array $actor, ?array $previous = null): void
    {
        $this->auditLog->record([
            'module' => 'hr',
            'entity_type' => 'PayrollRun',
            'entity_id' => $run->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $run->tenant_id,
            'prev_values' => $previous,
            'new_values' => $new,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
