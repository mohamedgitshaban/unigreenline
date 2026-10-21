<?php

namespace Modules\Recap\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/recap. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `recap-dashboard.view`, belongs to this module's permission group.
 */
class RecapDashboardController extends DashboardController {}
