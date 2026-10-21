<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Models\RefuseReason;
use Modules\HR\Services\RecruitmentService;

/**
 * The reasons an applicant can be turned down for. A company that has none gets a standard list the first time it looks.
 */
class RefuseReasonController extends Controller
{
    use CrudEndpoints {
        index as protected crudIndex;
    }

    public function __construct(private readonly RecruitmentService $recruitment) {}

    protected function model(): string
    {
        return RefuseReason::class;
    }

    protected function entity(): string
    {
        return 'RefuseReason';
    }

    protected function searchable(): array
    {
        return ['name'];
    }

    protected function defaultOrder(): array
    {
        return ['name', 'asc'];
    }

    public function index(Request $request)
    {
        $this->recruitment->refuseReasons($request->user()->tenant_id);

        return $this->crudIndex($request);
    }

    protected function rules(Request $request, $record): array
    {
        return ['name' => ['required', 'string', 'max:255', Rule::unique('hr_refuse_reasons', 'name')->where('tenant_id', $request->user()->tenant_id)->ignore($record?->id)]];
    }
}
