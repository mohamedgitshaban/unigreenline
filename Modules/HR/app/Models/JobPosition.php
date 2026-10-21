<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\HR\Database\Factories\JobPositionFactory;

/**
 * An open (or planned) job: how many people are wanted, who recruits and what it pays.
 */
#[Fillable(['tenant_id', 'name', 'department_id', 'recruiter_id', 'vacancies', 'hired_count', 'status', 'employment_type', 'location', 'salary_min', 'salary_max', 'description', 'requirements', 'deadline', 'created_by'])]
class JobPosition extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_job_positions';

    protected $attributes = [
        'status' => 'open',
        'vacancies' => 1,
        'hired_count' => 0,
        'employment_type' => 'full_time',
    ];

    protected function casts(): array
    {
        return [
            'vacancies' => 'integer',
            'hired_count' => 'integer',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'deadline' => 'date',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class, 'job_position_id');
    }

    protected static function newFactory(): JobPositionFactory
    {
        return JobPositionFactory::new();
    }
}
