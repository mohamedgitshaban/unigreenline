<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Why an applicant was turned down.
 */
#[Fillable(['tenant_id', 'name'])]
class RefuseReason extends Model
{
    use HasUlids;

    protected $table = 'hr_refuse_reasons';
}
