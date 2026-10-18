<?php

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;
use Modules\Analytics\Http\Controllers\ReportController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard'])->can('analytics-dashboard.view');
    Route::get('analytics/expiry', [AnalyticsController::class, 'expiry'])->can('expiry-analytics.view')->exportable('expiry-analytics.export');
    Route::get('analytics/stock', [AnalyticsController::class, 'stock'])->can('stock-analytics.view')->exportable('stock-analytics.export');
    Route::get('products/{id}/lifecycle', [AnalyticsController::class, 'productLifecycle'])->can('products.lifecycle');

    Route::get('reports/sales', [ReportController::class, 'sales'])->can('sales-report.export');
    Route::get('reports/inventory', [ReportController::class, 'inventory'])->can('inventory-report.export');
});
