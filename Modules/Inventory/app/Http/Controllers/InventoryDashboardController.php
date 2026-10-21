<?php

namespace Modules\Inventory\Http\Controllers;

use Modules\Core\Http\Controllers\DashboardController;

/**
 * GET /dashboards/inventory. The behaviour is shared (see the Core DashboardController); the class lives in this
 * module so the permission it needs, `inventory-dashboard.view`, belongs to this module's permission group.
 */
class InventoryDashboardController extends DashboardController {}
