<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\HR\Database\Factories\ApplicantFactory;

/**
 * Someone applying for a job, moving through the pipeline until hired or turned down.
 */
#[Fillable(['tenant_id', 'name', 'email', 'phone', 'job_position_id', 'stage_id', 'source', 'expected_salary', 'proposed_salary', 'availability_date', 'priority', 'kanban_state', 'recruiter_id', 'status', 'refuse_reason_id', 'refused_at', 'employee_id', 'hired_at', 'applied_on', 'stage_changed_at', 'resume_path', 'resume_name', 'offer_status', 'offer_valid_until', 'notes', 'created_by'])]
class Applicant extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_applicants';

    protected $attributes = [
        'status' => 'active',
        'priority' => 0,
        'kanban_state' => 'normal',
        'offer_status' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'expected_salary' => 'decimal:2',
            'proposed_salary' => 'decimal:2',
            'availability_date' => 'date',
            'priority' => 'integer',
            'refused_at' => 'datetime',
            'hired_at' => 'datetime',
            'applied_on' => 'date',
            'stage_changed_at' => 'datetime',
            'offer_valid_until' => 'date',
        ];
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentStage::class, 'stage_id');
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    public function refuseReason(): BelongsTo
    {
        return $this->belongsTo(RefuseReason::class, 'refuse_reason_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'applicant_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicantEvent::class, 'applicant_id');
    }

    protected static function newFactory(): ApplicantFactory
    {
        return ApplicantFactory::new();
    }
}
