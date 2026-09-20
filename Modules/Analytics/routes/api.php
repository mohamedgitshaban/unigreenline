<?php

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;
use Modules\Analytics\Http\Controllers\ReportController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard']);
    Route::get('analytics/expiry', [AnalyticsController::class, 'expiry']);
    Route::get('analytics/stock', [AnalyticsController::class, 'stock']);

    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/inventory', [ReportController::class, 'inventory']);
});
