<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;

/**
 * A meeting with an applicant and what came of it.
 */
#[Fillable(['tenant_id', 'applicant_id', 'interviewer_id', 'scheduled_at', 'duration_minutes', 'mode', 'location', 'status', 'result', 'score', 'feedback', 'created_by'])]
class Interview extends Model
{
    use HasUlids;

    protected $table = 'hr_interviews';

    protected $attributes = [
        'status' => 'scheduled',
        'result' => 'pending',
        'mode' => 'onsite',
        'duration_minutes' => 45,
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'score' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }
}
