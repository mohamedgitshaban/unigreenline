<?php

namespace Modules\Manufacturing\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/manufacturing. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `manufacturing-dashboard.view`, belongs to this module's permission group.
 */
class ManufacturingDashboardController extends DashboardController {}
