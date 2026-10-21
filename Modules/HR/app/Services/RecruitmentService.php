<?php

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\ApplicantEvent;
use Modules\HR\Models\Employee;
use Modules\HR\Models\JobPosition;
use Modules\HR\Models\RecruitmentStage;
use Modules\HR\Models\RefuseReason;

/**
 * Moving applicants through the hiring pipeline, from the first application to the new employee.
 * Everything that happens to an applicant is also written to their history.
 */
class RecruitmentService
{
    /** The pipeline a company starts with: name => is the contract-signed stage. */
    public const DEFAULT_STAGES = ['New' => false, 'Initial Qualification' => false, 'First Interview' => false, 'Second Interview' => false, 'Contract Proposal' => false, 'Contract Signed' => true];

    public const DEFAULT_REFUSE_REASONS = ["Doesn't fit the job requirements", 'Salary expectation too high', 'Accepted another offer', 'Withdrew the application', 'Duplicate application'];

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * The company's stages in order; the standard pipeline is created the first time it is needed.
     *
     * @return Collection<int, RecruitmentStage>
     */
    public function stages(string $tenantId): Collection
    {
        if (! RecruitmentStage::query()->where('tenant_id', $tenantId)->exists()) {
            $sequence = 10;

            foreach (self::DEFAULT_STAGES as $name => $hired) {
                RecruitmentStage::query()->firstOrCreate(['tenant_id' => $tenantId, 'name' => $name], ['sequence' => $sequence, 'is_hired' => $hired]);
                $sequence += 10;
            }
        }

        return RecruitmentStage::query()->where('tenant_id', $tenantId)->orderBy('sequence')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, RefuseReason>
     */
    public function refuseReasons(string $tenantId): Collection
    {
        if (! RefuseReason::query()->where('tenant_id', $tenantId)->exists()) {
            foreach (self::DEFAULT_REFUSE_REASONS as $name) {
                RefuseReason::query()->firstOrCreate(['tenant_id' => $tenantId, 'name' => $name]);
            }
        }

        return RefuseReason::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
    }

    /**
     * Puts a new applicant in the first stage unless told otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data, User $actor): Applicant
    {
        return DB::transaction(function () use ($data, $actor) {
            $stage = isset($data['stage_id'])
                ? RecruitmentStage::query()->where('tenant_id', $actor->tenant_id)->findOrFail($data['stage_id'])
                : $this->stages($actor->tenant_id)->first();

            $position = isset($data['job_position_id']) ? JobPosition::query()->where('tenant_id', $actor->tenant_id)->find($data['job_position_id']) : null;

            $applicant = Applicant::create($data + [
                'tenant_id' => $actor->tenant_id,
                'stage_id' => $stage->id,
                'applied_on' => now()->toDateString(),
                'stage_changed_at' => now(),
                'recruiter_id' => $position?->recruiter_id,
                'created_by' => $actor->id,
            ]);

            $this->record($applicant, $actor, 'created', 'Applied'.($position ? " for {$position->name}" : ''));
            $this->audit($actor, $applicant, 'INSERT', null, ['name' => $applicant->name, 'job_position_id' => $applicant->job_position_id, 'stage_id' => $applicant->stage_id]);

            return $applicant->refresh();
        });
    }

    public function move(Applicant $applicant, RecruitmentStage $stage, User $actor): Applicant
    {
        return DB::transaction(function () use ($applicant, $stage, $actor) {
            $locked = $this->lock($applicant);

            if ($locked->status !== 'active') {
                throw new HrRuleException('Only an active applicant can be moved. Restore a refused or archived one first.');
            }

            if ($locked->stage_id === $stage->id) {
                return $locked;
            }

            $from = RecruitmentStage::query()->find($locked->stage_id);
            $locked->forceFill(['stage_id' => $stage->id, 'stage_changed_at' => now(), 'kanban_state' => 'normal'])->save();

            $this->record($locked, $actor, 'stage_changed', "Moved from {$from?->name} to {$stage->name}", ['from' => $from?->id, 'to' => $stage->id]);
            $this->audit($actor, $locked, 'UPDATE', ['stage_id' => $from?->id], ['stage_id' => $stage->id]);

            return $locked->refresh();
        });
    }

    public function refuse(Applicant $applicant, ?RefuseReason $reason, ?string $note, User $actor): Applicant
    {
        return DB::transaction(function () use ($applicant, $reason, $note, $actor) {
            $locked = $this->lock($applicant);

            if ($locked->status !== 'active') {
                throw new HrRuleException('Only an active applicant can be refused.');
            }

            $locked->forceFill(['status' => 'refused', 'refuse_reason_id' => $reason?->id, 'refused_at' => now(), 'offer_status' => in_array($locked->offer_status, ['draft', 'sent'], true) ? 'declined' : $locked->offer_status])->save();

            $this->record($locked, $actor, 'refused', 'Refused'.($reason ? ": {$reason->name}" : '').($note ? " — {$note}" : ''));
            $this->audit($actor, $locked, 'UPDATE', ['status' => 'active'], ['status' => 'refused', 'refuse_reason_id' => $reason?->id]);

            return $locked->refresh();
        });
    }

    public function archive(Applicant $applicant, User $actor): Applicant
    {
        return DB::transaction(function () use ($applicant, $actor) {
            $locked = $this->lock($applicant);

            if ($locked->status !== 'active') {
                throw new HrRuleException('Only an active applicant can be archived.');
            }

            $locked->forceFill(['status' => 'archived'])->save();
            $this->record($locked, $actor, 'archived', 'Archived');
            $this->audit($actor, $locked, 'UPDATE', ['status' => 'active'], ['status' => 'archived']);

            return $locked->refresh();
        });
    }

    public function restore(Applicant $applicant, User $actor): Applicant
    {
        return DB::transaction(function () use ($applicant, $actor) {
            $locked = $this->lock($applicant);

            if (! in_array($locked->status, ['refused', 'archived'], true)) {
                throw new HrRuleException('Only a refused or archived applicant can be restored.');
            }

            $previous = $locked->status;
            $locked->forceFill(['status' => 'active', 'refuse_reason_id' => null, 'refused_at' => null])->save();
            $this->record($locked, $actor, 'restored', 'Restored to the pipeline');
            $this->audit($actor, $locked, 'UPDATE', ['status' => $previous], ['status' => 'active']);

            return $locked->refresh();
        });
    }

    /**
     * Records an offer to the applicant: its salary, how long it stands and where it is (draft, sent, accepted, declined).
     *
     * @param  array{proposed_salary?: float|string|null, offer_valid_until?: ?string, offer_status: string}  $data
     */
    public function offer(Applicant $applicant, array $data, User $actor): Applicant
    {
        return DB::transaction(function () use ($applicant, $data, $actor) {
            $locked = $this->lock($applicant);

            if ($locked->status !== 'active') {
                throw new HrRuleException('Offers can only be made to an active applicant.');
            }

            $previous = $locked->only(['proposed_salary', 'offer_status', 'offer_valid_until']);
            $locked->forceFill(array_intersect_key($data, array_flip(['proposed_salary', 'offer_valid_until', 'offer_status'])))->save();

            $this->record($locked, $actor, 'offer', 'Offer '.$locked->offer_status.($locked->proposed_salary ? " at {$locked->proposed_salary}" : ''));
            $this->audit($actor, $locked, 'UPDATE', $previous, $locked->only(array_keys($previous)));

            return $locked->refresh();
        });
    }

    /**
     * Turns the applicant into an employee: creates the employee record from what is known, marks the applicant hired,
     * moves them to the contract-signed stage and counts the hire against the job; a job whose vacancies are filled is closed.
     *
     * @param  array{hire_date: string, code?: ?string, basic_salary?: float|string|null, department_id?: ?string, work_schedule_id?: ?string, user_id?: ?string}  $data
     */
    public function hire(Applicant $applicant, array $data, User $actor): Applicant
    {
        return DB::transaction(function () use ($applicant, $data, $actor) {
            $locked = $this->lock($applicant);

            if ($locked->status !== 'active') {
                throw new HrRuleException($locked->status === 'hired' ? 'This applicant has already been hired.' : 'Only an active applicant can be hired.');
            }

            $position = $locked->job_position_id ? JobPosition::query()->whereKey($locked->job_position_id)->lockForUpdate()->first() : null;
            $salary = $data['basic_salary'] ?? $locked->proposed_salary ?? $locked->expected_salary;

            if ($salary === null) {
                throw new HrRuleException('Say what the salary is: there is no proposed or expected salary on the applicant.');
            }

            $employee = Employee::create([
                'tenant_id' => $locked->tenant_id,
                'code' => $data['code'] ?? $this->nextEmployeeCode($locked->tenant_id),
                'name' => $locked->name,
                'email' => $locked->email,
                'phone' => $locked->phone,
                'job_title' => $position?->name,
                'department_id' => $data['department_id'] ?? $position?->department_id,
                'work_schedule_id' => $data['work_schedule_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'hire_date' => $data['hire_date'],
                'status' => 'active',
                'basic_salary' => $salary,
            ]);

            $hiredStage = $this->stages($locked->tenant_id)->firstWhere('is_hired', true);

            $locked->forceFill([
                'status' => 'hired', 'employee_id' => $employee->id, 'hired_at' => now(), 'offer_status' => 'accepted',
                'proposed_salary' => $salary, 'stage_id' => $hiredStage?->id ?? $locked->stage_id, 'stage_changed_at' => now(), 'kanban_state' => 'done',
            ])->save();

            if ($position) {
                $position->hired_count++;
                $position->status = $position->hired_count >= $position->vacancies && $position->status === 'open' ? 'closed' : $position->status;
                $position->save();
            }

            $this->record($locked, $actor, 'hired', "Hired as {$employee->code} {$employee->name}", ['employee_id' => $employee->id]);
            $this->audit($actor, $locked, 'UPDATE', ['status' => 'active'], ['status' => 'hired', 'employee_id' => $employee->id]);
            $this->auditLog->record([
                'module' => 'hr', 'entity_type' => 'Employee', 'entity_id' => $employee->id, 'operation' => 'INSERT',
                'user_id' => $actor->id, 'user_name' => $actor->name, 'tenant_id' => $locked->tenant_id,
                'new_values' => ['code' => $employee->code, 'name' => $employee->name, 'hire_date' => $data['hire_date'], 'from_applicant' => $locked->id],
            ]);

            return $locked->refresh();
        });
    }

    public function note(Applicant $applicant, string $text, User $actor): ApplicantEvent
    {
        return $this->record($applicant, $actor, 'note', $text);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function record(Applicant $applicant, ?User $actor, string $type, string $summary, ?array $data = null): ApplicantEvent
    {
        return ApplicantEvent::create([
            'tenant_id' => $applicant->tenant_id, 'applicant_id' => $applicant->id, 'user_id' => $actor?->id,
            'type' => $type, 'summary' => Str::limit($summary, 250), 'payload' => $data,
        ]);
    }

    private function lock(Applicant $applicant): Applicant
    {
        return Applicant::query()->where('tenant_id', $applicant->tenant_id)->whereKey($applicant->id)->lockForUpdate()->firstOrFail();
    }

    private function nextEmployeeCode(string $tenantId): string
    {
        $next = Employee::query()->where('tenant_id', $tenantId)->where('code', 'like', 'EMP-%')->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 4))->max() + 1;

        while (Employee::query()->where('tenant_id', $tenantId)->where('code', 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT))->exists()) {
            $next++;
        }

        return 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    private function audit(User $actor, Applicant $applicant, string $operation, ?array $previous, ?array $new): void
    {
        $this->auditLog->record([
            'module' => 'hr', 'entity_type' => 'Applicant', 'entity_id' => $applicant->id, 'operation' => $operation,
            'user_id' => $actor->id, 'user_name' => $actor->name, 'tenant_id' => $applicant->tenant_id,
            'prev_values' => $previous, 'new_values' => $new,
        ]);
    }
}
