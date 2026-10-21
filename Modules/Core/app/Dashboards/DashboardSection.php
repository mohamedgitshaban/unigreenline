<?php

namespace Modules\Core\Dashboards;

use Modules\Core\Models\User;

/**
 * One feature of a department's dashboard — the sales orders, the stock alerts, the leave requests. A module
 * writes one section per feature and registers it with {@see DashboardRegistry}; the dashboard of the department
 * is just its sections side by side, each shown only to people allowed to see that feature.
 */
interface DashboardSection
{
    /** Stable name of the feature, e.g. `orders`. */
    public function key(): string;

    /** What to call it, e.g. `Sales orders`. */
    public function label(): string;

    /** The permission that opens this feature's own list, or null when the dashboard's permission is enough. */
    public function permission(): ?string;

    /**
     * Figures for the period, limited to what the user may see (company, reporting line, warehouses).
     *
     * @return array{kpis?: array<int, array<string, mixed>>, charts?: array<int, array<string, mixed>>, tables?: array<int, array<string, mixed>>}
     */
    public function build(User $user, DashboardPeriod $period): array;
}
