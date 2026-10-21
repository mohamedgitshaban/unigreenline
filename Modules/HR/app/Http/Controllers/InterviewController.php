<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HR\Http\Controllers\Concerns\CrudEndpoints;
use Modules\HR\Http\Resources\HrRecordResource;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\Interview;
use Modules\HR\Services\RecruitmentService;

/**
 * Interviews with applicants: scheduled, held, and what came of them.
 */
class InterviewController extends Controller
{
    use CrudEndpoints;

    public function __construct(private readonly RecruitmentService $recruitment) {}

    protected function model(): string
    {
        return Interview::class;
    }

    protected function entity(): string
    {
        return 'Interview';
    }

    protected function searchable(): array
    {
        return ['location', 'feedback', 'applicant.name'];
    }

    protected function defaultOrder(): array
    {
        return ['scheduled_at', 'asc'];
    }

    protected function with(): array
    {
        return ['applicant:id,name,job_position_id,stage_id', 'interviewer:id,name'];
    }

    protected function filterKeys(): array
    {
        return ['applicant_id', 'interviewer_id', 'status', 'result', 'mode'];
    }

    protected function scope(Builder $query, Request $request): void
    {
        $query->when($request->filled('from'), fn ($q) => $q->where('scheduled_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('scheduled_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->boolean('mine'), fn ($q) => $q->where('interviewer_id', $request->user()->id));
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'applicant_id' => ['required', 'string', $this->owned($request, 'hr_applicants')],
            'interviewer_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'mode' => ['sometimes', Rule::in(['onsite', 'phone', 'video'])],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['scheduled', 'done', 'cancelled'])],
            'result' => ['sometimes', Rule::in(['pending', 'pass', 'fail'])],
            'score' => ['nullable', 'integer', 'min:1', 'max:10'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function afterCreate(Model $record, Request $request): void
    {
        $applicant = Applicant::query()->find($record->applicant_id);
        $this->recruitment->record($applicant, $request->user(), 'interview', 'Interview scheduled for '.$record->scheduled_at->format('Y-m-d H:i'), ['interview_id' => $record->id]);
    }

    /**
     * Records how an interview went (POST /interviews/{id}/record-result): the interview is done, with its verdict, score and feedback.
     */
    public function recordResult(Request $request, string $id)
    {
        $interview = $this->find($request, $id);
        $data = $request->validate([
            'result' => ['required', Rule::in(['pass', 'fail'])],
            'score' => ['nullable', 'integer', 'min:1', 'max:10'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->updateRecord($request, $interview, $data + ['status' => 'done'], 'hr', 'Interview');
        $this->recruitment->record(Applicant::query()->findOrFail($interview->applicant_id), $request->user(), 'interview', "Interview {$data['result']}".(isset($data['score']) ? " ({$data['score']}/10)" : ''), ['interview_id' => $interview->id]);

        return new HrRecordResource($interview->refresh()->load($this->with()));
    }
}
