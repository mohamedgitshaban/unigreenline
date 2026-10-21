<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HR\Database\Factories\RecruitmentStageFactory;

/**
 * A column of the hiring pipeline. The stage flagged `is_hired` is where applicants end up once the contract is signed.
 */
#[Fillable(['tenant_id', 'name', 'sequence', 'is_hired', 'requirements'])]
class RecruitmentStage extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'hr_recruitment_stages';

    protected $attributes = [
        'sequence' => 10,
        'is_hired' => false,
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_hired' => 'boolean',
        ];
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class, 'stage_id');
    }

    protected static function newFactory(): RecruitmentStageFactory
    {
        return RecruitmentStageFactory::new();
    }
}
