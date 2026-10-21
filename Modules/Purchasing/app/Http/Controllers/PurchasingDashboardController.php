<?php

namespace Modules\Purchasing\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/purchasing. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `purchasing-dashboard.view`, belongs to this module's permission group.
 */
class PurchasingDashboardController extends DashboardController {}
