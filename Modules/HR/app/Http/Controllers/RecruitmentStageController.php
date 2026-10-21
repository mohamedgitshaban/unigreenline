<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Models\RecruitmentStage;
use Modules\HR\Services\RecruitmentService;

/**
 * The columns of the hiring pipeline. A company that has none gets the standard six the first time it looks.
 */
class RecruitmentStageController extends Controller
{
    use CrudEndpoints {
        index as protected crudIndex;
    }

    public function __construct(private readonly RecruitmentService $recruitment) {}

    protected function model(): string
    {
        return RecruitmentStage::class;
    }

    protected function entity(): string
    {
        return 'RecruitmentStage';
    }

    protected function searchable(): array
    {
        return ['name'];
    }

    protected function defaultOrder(): array
    {
        return ['sequence', 'asc'];
    }

    public function index(Request $request)
    {
        $this->recruitment->stages($request->user()->tenant_id);

        return $this->crudIndex($request);
    }

    protected function scope($query, Request $request): void
    {
        $query->withCount(['applicants', 'applicants as active_applicants_count' => fn ($q) => $q->where('status', 'active')]);
    }

    protected function rules(Request $request, $record): array
    {
        $tenant = $request->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_recruitment_stages', 'name')->where('tenant_id', $tenant)->ignore($record?->id)],
            'sequence' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'is_hired' => ['sometimes', 'boolean'],
            'requirements' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function afterCreate($record, Request $request): void
    {
        $this->singleHiredStage($record);
    }

    protected function afterUpdate($record, Request $request): void
    {
        $this->singleHiredStage($record);
    }

    /**
     * Only one stage is "contract signed": flagging another clears the flag from the rest.
     */
    private function singleHiredStage(RecruitmentStage $stage): void
    {
        if ($stage->is_hired) {
            RecruitmentStage::query()->where('tenant_id', $stage->tenant_id)->where('id', '!=', $stage->id)->update(['is_hired' => false]);
        }
    }
}
