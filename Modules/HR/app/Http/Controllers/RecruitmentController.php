<?php

namespace Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\JobPosition;
use Modules\HR\Services\RecruitmentService;

/**
 * The recruitment overview: the pipeline as columns of applicants, and the numbers that say how hiring is going.
 */
class RecruitmentController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    /**
     * Stages with the applicants in them (GET /recruitment/pipeline), `?job_position_id=` for one job, `?limit=` applicants per stage.
     */
    public function pipeline(Request $request)
    {
        $filters = $request->validate(['job_position_id' => ['sometimes', 'string'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);
        $tenant = $request->user()->tenant_id;
        $limit = (int) ($filters['limit'] ?? 20);

        $applicants = fn () => Applicant::query()->where('tenant_id', $tenant)->when($filters['job_position_id'] ?? null, fn ($q, $job) => $q->where('job_position_id', $job));
        $counts = $applicants()->where('status', 'active')->selectRaw('stage_id, count(*) as total')->groupBy('stage_id')->pluck('total', 'stage_id');

        $stages = $this->recruitment->stages($tenant)->map(fn ($stage) => [
            'id' => $stage->id, 'name' => $stage->name, 'sequence' => $stage->sequence, 'is_hired' => $stage->is_hired,
            'count' => (int) ($counts[$stage->id] ?? 0),
            'applicants' => $applicants()->where('status', 'active')->where('stage_id', $stage->id)->with(['jobPosition:id,name', 'recruiter:id,name'])
                ->orderByDesc('priority')->orderBy('stage_changed_at')->limit($limit)->get()->map(fn (Applicant $a) => [
                    'id' => $a->id, 'name' => $a->name, 'priority' => $a->priority, 'kanban_state' => $a->kanban_state, 'offer_status' => $a->offer_status,
                    'job_position' => $a->jobPosition ? ['id' => $a->jobPosition->id, 'name' => $a->jobPosition->name] : null,
                    'recruiter' => $a->recruiter ? ['id' => $a->recruiter->id, 'name' => $a->recruiter->name] : null,
                    'expected_salary' => $a->expected_salary, 'days_in_stage' => $a->stage_changed_at ? (int) $a->stage_changed_at->diffInDays(now()) : 0,
                ])->values(),
        ])->values();

        return response()->json(['data' => [
            'stages' => $stages,
            'totals' => [
                'active' => $stages->sum('count'),
                'hired' => $applicants()->where('status', 'hired')->count(),
                'refused' => $applicants()->where('status', 'refused')->count(),
                'archived' => $applicants()->where('status', 'archived')->count(),
            ],
        ]]);
    }

    /**
     * How hiring is going over a period (GET /recruitment/analysis): applications, hires, time to hire, where applicants come
     * from, why they are refused, and how each job is doing.
     */
    public function analysis(Request $request)
    {
        $tenant = $request->user()->tenant_id;
        $period = DashboardPeriod::fromRequest($request);
        $applied = fn () => Applicant::query()->where('tenant_id', $tenant)->whereBetween('applied_on', [$period->from->toDateString(), $period->to->toDateString()]);
        $hired = fn () => Applicant::query()->where('tenant_id', $tenant)->where('status', 'hired')->whereBetween('hired_at', [$period->from, $period->to]);

        $timeToHire = $hired()->selectRaw('avg(datediff(date(hired_at), applied_on)) as days')->value('days');
        $accepted = Applicant::query()->where('tenant_id', $tenant)->where('offer_status', 'accepted')->whereBetween('updated_at', [$period->from, $period->to])->count();
        $declined = Applicant::query()->where('tenant_id', $tenant)->where('offer_status', 'declined')->whereBetween('updated_at', [$period->from, $period->to])->count();

        $bySource = $applied()->selectRaw("coalesce(source, 'Unknown') as source, count(*) as applicants, sum(status = 'hired') as hired")->groupBy('source')->orderByDesc('applicants')->get();
        $refusals = $applied()->where('status', 'refused')->leftJoin('hr_refuse_reasons', 'hr_refuse_reasons.id', '=', 'hr_applicants.refuse_reason_id')
            ->selectRaw("coalesce(hr_refuse_reasons.name, 'No reason given') as reason, count(*) as total")->groupBy('hr_refuse_reasons.name')->orderByDesc('total')->get();

        $jobs = JobPosition::query()->where('tenant_id', $tenant)->whereIn('status', ['open', 'closed'])
            ->withCount(['applicants as active_applicants' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('active_applicants')->limit(20)->get();

        return response()->json(['data' => [
            'period' => $period->toArray(),
            'applications' => $applied()->count(),
            'hired' => $hired()->count(),
            'refused' => $applied()->where('status', 'refused')->count(),
            'average_days_to_hire' => $timeToHire === null ? null : round((float) $timeToHire, 1),
            'offer_acceptance_pct' => $accepted + $declined > 0 ? round($accepted / ($accepted + $declined) * 100, 1) : null,
            'by_source' => $bySource->map(fn ($row) => ['source' => $row->source, 'applicants' => (int) $row->applicants, 'hired' => (int) $row->hired])->values(),
            'refusal_reasons' => $refusals->map(fn ($row) => ['reason' => $row->reason, 'total' => (int) $row->total])->values(),
            'jobs' => $jobs->map(fn (JobPosition $job) => [
                'id' => $job->id, 'name' => $job->name, 'status' => $job->status, 'vacancies' => $job->vacancies, 'hired' => $job->hired_count,
                'still_open' => max(0, $job->vacancies - $job->hired_count), 'active_applicants' => (int) $job->active_applicants,
            ])->values(),
        ]]);
    }
}
