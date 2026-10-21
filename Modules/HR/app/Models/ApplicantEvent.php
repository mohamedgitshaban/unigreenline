<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;

/**
 * One line of an applicant's history: created, moved, interviewed, refused, hired, a note. Never edited.
 */
#[Fillable(['tenant_id', 'applicant_id', 'user_id', 'type', 'summary', 'payload'])]
class ApplicantEvent extends Model
{
    use HasUlids;

    protected $table = 'hr_applicant_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
