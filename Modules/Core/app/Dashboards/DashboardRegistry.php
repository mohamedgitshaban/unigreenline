<?php

namespace Modules\Core\Dashboards;

use Modules\Core\Models\User;

/**
 * Every department's dashboard and the features (sections) inside it. A module registers its own in its
 * service provider's boot():
 *
 *     $registry = app(DashboardRegistry::class);
 *     $registry->register(new Dashboard('sales', 'Sales', 'sales-dashboard.view'));
 *     $registry->section('sales', new OrdersSection);
 *
 * and adds the route `Route::get('dashboards/sales', ...)->defaults('module', 'sales')->can('sales-dashboard.view')`.
 * A new department is a new registration; a new feature of an existing department is one more section —
 * even one contributed by a different module.
 */
class DashboardRegistry
{
    /** @var array<string, Dashboard> */
    private array $dashboards = [];

    /** @var array<string, array<string, DashboardSection>> */
    private array $sections = [];

    public function register(Dashboard $dashboard): void
    {
        $this->dashboards[$dashboard->module] = $dashboard;
        $this->sections[$dashboard->module] ??= [];
    }

    public function section(string $module, DashboardSection $section): void
    {
        $this->sections[$module][$section->key()] = $section;
    }

    public function find(string $module): ?Dashboard
    {
        return $this->dashboards[$module] ?? null;
    }

    /**
     * @return array<string, Dashboard>
     */
    public function all(): array
    {
        return $this->dashboards;
    }

    /**
     * @return array<string, DashboardSection>
     */
    public function sectionsOf(string $module): array
    {
        return $this->sections[$module] ?? [];
    }

    /**
     * One department's dashboard for a user: the features they may open and the headline numbers, charts and
     * tables those features produce for the period. `$wantedFeatures` narrows it to some features.
     *
     * @param  array<int, string>|null  $wantedFeatures
     * @return array{features: array<int, array{key: string, label: string, available: bool}>, kpis: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, tables: array<int, array<string, mixed>>}
     */
    public function build(User $user, string $module, DashboardPeriod $period, ?array $wantedFeatures = null): array
    {
        $features = [];
        $widgets = ['kpis' => [], 'charts' => [], 'tables' => []];

        foreach ($this->sectionsOf($module) as $section) {
            $allowed = $section->permission() === null || $user->can($section->permission());
            $features[] = ['key' => $section->key(), 'label' => $section->label(), 'available' => $allowed];

            if (! $allowed || ($wantedFeatures !== null && ! in_array($section->key(), $wantedFeatures, true))) {
                continue;
            }

            $built = $section->build($user, $period);

            foreach (array_keys($widgets) as $kind) {
                foreach ($built[$kind] ?? [] as $widget) {
                    $widgets[$kind][] = ['feature' => $section->key()] + $widget;
                }
            }
        }

        return ['features' => $features, ...$widgets];
    }
}
