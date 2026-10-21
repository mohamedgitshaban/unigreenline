<?php

namespace Modules\Expenses\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/expenses. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `expenses-dashboard.view`, belongs to this module's permission group.
 */
class ExpensesDashboardController extends DashboardController {}
