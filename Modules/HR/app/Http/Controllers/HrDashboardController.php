<?php

namespace Modules\HR\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/hr. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `hr-dashboard.view`, belongs to this module's permission group.
 */
class HrDashboardController extends DashboardController {}
