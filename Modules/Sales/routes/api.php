<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\CollectionController;
use Modules\Sales\Http\Controllers\DeliveryController;
use Modules\Sales\Http\Controllers\InvoiceController;
use Modules\Sales\Http\Controllers\ReportController;
use Modules\Sales\Http\Controllers\ReturnController;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('sales-orders', [SalesOrderController::class, 'index']);
    Route::post('sales-orders', [SalesOrderController::class, 'store']);
    Route::get('sales-orders/{salesOrder}', [SalesOrderController::class, 'show']);
    Route::put('sales-orders/{salesOrder}/status', [SalesOrderController::class, 'updateStatus']);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);

    Route::get('deliveries', [DeliveryController::class, 'index']);
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show']);
    Route::put('deliveries/{delivery}/deliver', [DeliveryController::class, 'deliver']);

    Route::get('collections', [CollectionController::class, 'index']);
    Route::post('collections', [CollectionController::class, 'store']);
    Route::get('collections/{collection}', [CollectionController::class, 'show']);

    Route::get('returns', [ReturnController::class, 'index']);
    Route::post('returns', [ReturnController::class, 'store']);

    Route::get('reports/ar-aging', [ReportController::class, 'arAging']);
});
