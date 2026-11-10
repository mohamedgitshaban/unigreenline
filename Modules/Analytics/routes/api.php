<?php

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;
use Modules\Analytics\Http\Controllers\OverviewController;
use Modules\Analytics\Http\Controllers\ReportController;
use Modules\Analytics\Http\Controllers\SalesBreakdownController;
use Modules\Analytics\Http\Controllers\StockSnapshotController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Open to every signed-in user, like GET /dashboards: each user only gets the departments they may open.
    Route::get('analytics/overview', [OverviewController::class, 'show']);
    Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard'])->can('analytics-dashboard.view');
    Route::get('analytics/expiry', [AnalyticsController::class, 'expiry'])->can('expiry-analytics.view')->exportable('expiry-analytics.export');
    Route::get('analytics/stock', [AnalyticsController::class, 'stock'])->can('stock-analytics.view')->exportable('stock-analytics.export');
    Route::get('analytics/stock-snapshot', [StockSnapshotController::class, 'show'])->can('stock-snapshot.view')->exportable('stock-snapshot.export');
    Route::get('products/{id}/lifecycle', [AnalyticsController::class, 'productLifecycle'])->can('products.lifecycle');

    Route::get('reports/sales-breakdown', [SalesBreakdownController::class, 'show'])->can('sales-report.view')->exportable('sales-report.export');
    Route::get('reports/sales', [ReportController::class, 'sales'])->can('sales-report.export');
    Route::get('reports/inventory', [ReportController::class, 'inventory'])->can('inventory-report.export');
});
