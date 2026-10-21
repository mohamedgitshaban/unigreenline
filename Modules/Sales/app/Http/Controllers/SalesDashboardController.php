<?php

namespace Modules\Sales\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/sales. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `sales-dashboard.view`, belongs to this module's permission group.
 */
class SalesDashboardController extends DashboardController {}
