<?php

namespace Modules\HR\Dashboards;

use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardSection;
use Modules\Core\Dashboards\Widgets;
use Modules\Core\Models\User;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\Interview;
use Modules\HR\Models\JobPosition;
use Modules\HR\Services\RecruitmentService;

/**
 * Hiring: jobs open, applicants in the pipeline, interviews coming, and how many were hired.
 */
class RecruitmentSection implements DashboardSection
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function key(): string
    {
        return 'recruitment';
    }

    public function label(): string
    {
        return 'Recruitment';
    }

    public function permission(): ?string
    {
        return 'applicants.view';
    }

    public function build(User $user, DashboardPeriod $period): array
    {
        $tenant = $user->tenant_id;
        $applied = fn (DashboardPeriod $in) => Applicant::query()->where('tenant_id', $tenant)->whereBetween('applied_on', [$in->from->toDateString(), $in->to->toDateString()])->count();
        $hired = fn (DashboardPeriod $in) => Applicant::query()->where('tenant_id', $tenant)->where('status', 'hired')->whereBetween('hired_at', [$in->from, $in->to])->count();

        $counts = Applicant::query()->where('tenant_id', $tenant)->where('status', 'active')->selectRaw('stage_id, count(*) as total')->groupBy('stage_id')->pluck('total', 'stage_id');
        $funnel = $this->recruitment->stages($tenant)->map(fn ($stage) => ['label' => $stage->name, 'value' => (int) ($counts[$stage->id] ?? 0)])->all();
        $bySource = Applicant::query()->where('tenant_id', $tenant)->whereBetween('applied_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw("coalesce(source, 'Unknown') as source, count(*) as total")->groupBy('source')->orderByDesc('total')->limit(8)->get();
        $interviews = Interview::query()->where('hr_interviews.tenant_id', $tenant)->where('status', 'scheduled')->where('scheduled_at', '>=', now())
            ->join('hr_applicants', 'hr_applicants.id', '=', 'hr_interviews.applicant_id')->orderBy('scheduled_at')->limit(5)
            ->get(['hr_interviews.id', 'hr_applicants.name as applicant', 'hr_interviews.scheduled_at', 'hr_interviews.mode']);
        $jobs = JobPosition::query()->where('tenant_id', $tenant)->where('status', 'open')->withCount(['applicants as active_applicants' => fn ($q) => $q->where('status', 'active')])->orderByDesc('active_applicants')->limit(5)->get();

        return [
            'kpis' => [
                Widgets::kpi('open_positions', 'Open positions', JobPosition::query()->where('tenant_id', $tenant)->where('status', 'open')->count()),
                Widgets::kpi('open_vacancies', 'Vacancies to fill', (int) JobPosition::query()->where('tenant_id', $tenant)->where('status', 'open')->selectRaw('coalesce(sum(vacancies - hired_count), 0) as total')->value('total')),
                Widgets::kpi('applications', 'Applications', $applied($period), $applied($period->previous())),
                Widgets::kpi('hires', 'Hired', $hired($period), $hired($period->previous())),
                Widgets::kpi('active_applicants', 'Applicants in the pipeline', (int) $counts->sum()),
                Widgets::kpi('interviews_this_week', 'Interviews in the next 7 days', Interview::query()->where('tenant_id', $tenant)->where('status', 'scheduled')->whereBetween('scheduled_at', [now(), now()->addDays(7)])->count()),
            ],
            'charts' => [
                Widgets::chart('recruitment_funnel', 'Applicants per stage', 'bar', $funnel),
                Widgets::chart('applicants_by_source', 'Where applicants come from', 'donut', $bySource->map(fn ($row) => ['label' => $row->source, 'value' => (int) $row->total])->all()),
            ],
            'tables' => [
                Widgets::table('next_interviews', 'Next interviews', [['key' => 'applicant', 'label' => 'Applicant'], ['key' => 'scheduled_at', 'label' => 'When'], ['key' => 'mode', 'label' => 'How']],
                    $interviews->map(fn ($row) => ['id' => $row->id, 'applicant' => $row->applicant, 'scheduled_at' => $row->scheduled_at->format('Y-m-d H:i'), 'mode' => $row->mode])->all()),
                Widgets::table('busiest_positions', 'Positions with most applicants', [['key' => 'name', 'label' => 'Position'], ['key' => 'applicants', 'label' => 'Applicants'], ['key' => 'open', 'label' => 'Still to fill']],
                    $jobs->map(fn ($job) => ['id' => $job->id, 'name' => $job->name, 'applicants' => (int) $job->active_applicants, 'open' => max(0, $job->vacancies - $job->hired_count)])->all()),
            ],
        ];
    }
}
