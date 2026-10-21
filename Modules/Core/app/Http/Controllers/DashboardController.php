<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Dashboards\DashboardPeriod;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Support\DataScope;

/**
 * Dashboards, one per department (module). `GET /dashboards` lists the ones the caller may open;
 * `GET /dashboards/{module}` returns one: headline numbers, charts and short tables, grouped by feature,
 * for a period, limited to what the caller may see.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardRegistry $registry, private readonly DataScope $scope) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json(['data' => collect($this->registry->all())
            ->filter(fn ($dashboard) => $user->can($dashboard->permission))
            ->map(fn ($dashboard) => [
                'module' => $dashboard->module,
                'title' => $dashboard->title,
                'description' => $dashboard->description,
                'url' => "/api/v1/dashboards/{$dashboard->module}",
                'features' => collect($this->registry->sectionsOf($dashboard->module))
                    ->filter(fn ($section) => $section->permission() === null || $user->can($section->permission()))
                    ->map(fn ($section) => ['key' => $section->key(), 'label' => $section->label()])
                    ->values(),
            ])
            ->values(),
        ]);
    }

    public function show(Request $request)
    {
        $module = (string) $request->route('module');
        $dashboard = $this->registry->find($module);

        abort_if($dashboard === null, 404);
        abort_unless($request->user()->can($dashboard->permission), 403);

        $user = $request->user();
        $period = DashboardPeriod::fromRequest($request);
        $wanted = $request->filled('features') ? array_filter(explode(',', (string) $request->query('features'))) : null;

        $features = [];
        $widgets = ['kpis' => [], 'charts' => [], 'tables' => []];

        foreach ($this->registry->sectionsOf($module) as $section) {
            $allowed = $section->permission() === null || $user->can($section->permission());
            $features[] = ['key' => $section->key(), 'label' => $section->label(), 'available' => $allowed];

            if (! $allowed || ($wanted !== null && ! in_array($section->key(), $wanted, true))) {
                continue;
            }

            $built = $section->build($user, $period);

            foreach (array_keys($widgets) as $kind) {
                foreach ($built[$kind] ?? [] as $widget) {
                    $widgets[$kind][] = ['feature' => $section->key()] + $widget;
                }
            }
        }

        return response()->json(['data' => [
            'module' => $dashboard->module,
            'title' => $dashboard->title,
            'generated_at' => now()->toIso8601String(),
            'period' => $period->toArray(),
            'scope' => [
                'data_scope' => $this->scope->effective($user),
                'people' => $this->scope->isTeamScoped($user) ? count($this->scope->visibleUserIds($user)) : null,
            ],
            'features' => $features,
            ...$widgets,
        ]]);
    }
}
