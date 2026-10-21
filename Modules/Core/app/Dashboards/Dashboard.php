<?php

namespace Modules\Core\Dashboards;

/**
 * A department's dashboard: its name, the permission that opens it, and (through the registry) its sections.
 */
final class Dashboard
{
    /**
     * @param  string  $module  Short name used in the URL, e.g. `sales` → GET /dashboards/sales.
     * @param  string  $permission  Permission needed to open it, e.g. `sales-dashboard.view`.
     */
    public function __construct(
        public readonly string $module,
        public readonly string $title,
        public readonly string $permission,
        public readonly string $description = '',
    ) {}
}
