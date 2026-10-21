<?php

namespace Modules\Accounting\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/accounting. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `accounting-dashboard.view`, belongs to this module's permission group.
 */
class AccountingDashboardController extends DashboardController {}
