<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Http\Resources\HrRecordResource;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\RecruitmentStage;
use Modules\HR\Models\RefuseReason;
use Modules\HR\Services\RecruitmentService;

/**
 * Applicants and everything that happens to them on the way to being hired (or not): moving between stages, offers,
 * refusal and restoring, the CV, their history, and finally hiring.
 */
class ApplicantController extends Controller
{
    use CrudEndpoints;

    private const RESUME_MAX_KB = 5120;

    public function __construct(private readonly RecruitmentService $recruitment) {}

    protected function model(): string
    {
        return Applicant::class;
    }

    protected function entity(): string
    {
        return 'Applicant';
    }

    protected function searchable(): array
    {
        return ['name', 'email', 'phone', 'source', 'notes'];
    }

    protected function with(): array
    {
        return ['jobPosition:id,name', 'stage:id,name,sequence,is_hired', 'recruiter:id,name', 'refuseReason:id,name', 'employee:id,code,name'];
    }

    protected function filterKeys(): array
    {
        return ['job_position_id', 'stage_id', 'status', 'recruiter_id', 'priority', 'source', 'offer_status', 'kanban_state'];
    }

    protected function scope(Builder $query, Request $request): void
    {
        $query->when($request->boolean('mine'), fn ($q) => $q->where('recruiter_id', $request->user()->id))
            ->withCount(['interviews', 'interviews as upcoming_interviews_count' => fn ($q) => $q->where('status', 'scheduled')->where('scheduled_at', '>=', now())]);
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $tenant = $request->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_position_id' => ['nullable', 'string', $this->owned($request, 'hr_job_positions')],
            'source' => ['nullable', 'string', 'max:100'],
            'expected_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'availability_date' => ['nullable', 'date'],
            'priority' => ['sometimes', 'integer', 'between:0,3'],
            'kanban_state' => ['sometimes', Rule::in(['normal', 'blocked', 'done'])],
            'recruiter_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenant)],
            'notes' => ['nullable', 'string', 'max:10000'],
            ...($record === null ? ['stage_id' => ['sometimes', 'string', $this->owned($request, 'hr_recruitment_stages')]] : []),
        ];
    }

    public function store(Request $request)
    {
        $applicant = $this->recruitment->apply($request->validate($this->rules($request, null)), $request->user());

        return (new HrRecordResource($applicant->load($this->with())))->response()->setStatusCode(201);
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->status === 'hired') {
            throw new HrRuleException('A hired applicant is part of the employee\'s record and cannot be deleted.');
        }

        if ($record->resume_path) {
            Storage::delete($record->resume_path);
        }
    }

    protected function exportShape(): array
    {
        return [['Name', 'Email', 'Phone', 'Job', 'Stage', 'Status', 'Source', 'Expected salary', 'Applied'], fn (Applicant $a) => [$a->name, $a->email, $a->phone, $a->jobPosition?->name, $a->stage?->name, $a->status, $a->source, $a->expected_salary, $a->applied_on->toDateString()]];
    }

    protected function exportName(): string
    {
        return 'applicants';
    }

    public function move(Request $request, string $id)
    {
        $data = $request->validate(['stage_id' => ['required', 'string', $this->owned($request, 'hr_recruitment_stages')]]);

        return $this->answer($this->recruitment->move($this->find($request, $id), RecruitmentStage::query()->findOrFail($data['stage_id']), $request->user()));
    }

    public function refuse(Request $request, string $id)
    {
        $data = $request->validate([
            'refuse_reason_id' => ['nullable', 'string', $this->owned($request, 'hr_refuse_reasons')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = isset($data['refuse_reason_id']) ? RefuseReason::query()->find($data['refuse_reason_id']) : null;

        return $this->answer($this->recruitment->refuse($this->find($request, $id), $reason, $data['note'] ?? null, $request->user()));
    }

    public function archive(Request $request, string $id)
    {
        return $this->answer($this->recruitment->archive($this->find($request, $id), $request->user()));
    }

    public function restore(Request $request, string $id)
    {
        return $this->answer($this->recruitment->restore($this->find($request, $id), $request->user()));
    }

    public function offer(Request $request, string $id)
    {
        $data = $request->validate([
            'offer_status' => ['required', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'proposed_salary' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'offer_valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
        ]);

        return $this->answer($this->recruitment->offer($this->find($request, $id), $data, $request->user()));
    }

    public function hire(Request $request, string $id)
    {
        $tenant = $request->user()->tenant_id;
        $data = $request->validate([
            'hire_date' => ['required', 'date'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('hr_employees', 'code')->where('tenant_id', $tenant)],
            'basic_salary' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'department_id' => ['sometimes', 'nullable', 'string', $this->owned($request, 'hr_departments')],
            'work_schedule_id' => ['sometimes', 'nullable', 'string', $this->owned($request, 'hr_work_schedules')],
            'user_id' => ['sometimes', 'nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $tenant), Rule::unique('hr_employees', 'user_id')],
        ]);

        return $this->answer($this->recruitment->hire($this->find($request, $id), $data, $request->user()));
    }

    public function history(Request $request, string $id)
    {
        $applicant = $this->find($request, $id);

        return HrRecordResource::collection($applicant->events()->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function addNote(Request $request, string $id)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        return (new HrRecordResource($this->recruitment->note($this->find($request, $id), $data['text'], $request->user())))->response()->setStatusCode(201);
    }

    /**
     * Attaches the applicant's CV (POST /applicants/{id}/resume, multipart `file`: pdf, doc or docx up to 5 MB), replacing any earlier one.
     */
    public function uploadResume(Request $request, string $id)
    {
        $applicant = $this->find($request, $id);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:'.self::RESUME_MAX_KB]]);

        $file = $request->file('file');
        $path = $file->store("hr-resumes/{$applicant->tenant_id}");

        if ($applicant->resume_path) {
            Storage::delete($applicant->resume_path);
        }

        $applicant->forceFill(['resume_path' => $path, 'resume_name' => $file->getClientOriginalName()])->save();
        $this->recruitment->record($applicant, $request->user(), 'resume', 'CV uploaded: '.$file->getClientOriginalName());

        return $this->answer($applicant);
    }

    public function downloadResume(Request $request, string $id)
    {
        $applicant = $this->find($request, $id);

        abort_if($applicant->resume_path === null || ! Storage::exists($applicant->resume_path), 404, 'This applicant has no CV on file.');

        return Storage::download($applicant->resume_path, $applicant->resume_name ?: 'cv.'.pathinfo($applicant->resume_path, PATHINFO_EXTENSION));
    }

    private function answer(Applicant $applicant): HrRecordResource
    {
        return new HrRecordResource($applicant->load($this->with()));
    }
}
