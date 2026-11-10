<?php

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Support\DataScope;

/**
 * GET /analytics/overview: the company at a glance, built from every department's dashboard. A user gets the
 * departments and the features they are allowed to open, nothing else, so one home page serves everyone. Every
 * headline number carries a `link` to the list behind it, where there is one.
 */
class OverviewController extends Controller
{
    public function __construct(private readonly DashboardRegistry $registry, private readonly DataScope $scope) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $period = DashboardPeriod::fromRequest($request);
        $only = $request->filled('modules') ? array_filter(explode(',', (string) $request->query('modules'))) : null;

        $modules = collect($this->registry->all())
            ->filter(fn ($dashboard, string $module) => $user->can($dashboard->permission) && ($only === null || in_array($module, $only, true)))
            ->map(fn ($dashboard, string $module) => ['module' => $module, 'title' => $dashboard->title, 'description' => $dashboard->description] + $this->registry->build($user, $module, $period))
            ->values();

        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(),
            'period' => $period->toArray(),
            'scope' => [
                'data_scope' => $this->scope->effective($user),
                'people' => $this->scope->isTeamScoped($user) ? count($this->scope->visibleUserIds($user)) : null,
            ],
            'modules' => $modules,
        ]]);
    }
}
