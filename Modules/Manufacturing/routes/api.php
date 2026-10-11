<?php

use Illuminate\Support\Facades\Route;
use Modules\Manufacturing\Http\Controllers\BomController;
use Modules\Manufacturing\Http\Controllers\ProductionOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('boms', [BomController::class, 'index']);
    Route::post('boms', [BomController::class, 'store']);
    Route::get('boms/{bom}', [BomController::class, 'show']);
    Route::put('boms/{bom}', [BomController::class, 'update']);
    Route::delete('boms/{bom}', [BomController::class, 'destroy']);

    Route::get('production-orders', [ProductionOrderController::class, 'index']);
    Route::post('production-orders', [ProductionOrderController::class, 'store']);
    Route::get('production-orders/{productionOrder}', [ProductionOrderController::class, 'show']);
    Route::put('production-orders/{productionOrder}', [ProductionOrderController::class, 'update']);
    Route::delete('production-orders/{productionOrder}', [ProductionOrderController::class, 'destroy']);
    Route::post('production-orders/{productionOrder}/complete', [ProductionOrderController::class, 'complete']);
    Route::post('production-orders/{productionOrder}/cancel', [ProductionOrderController::class, 'cancel']);
});
