<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\ReportController;
use Modules\Purchasing\Http\Controllers\SupplierController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index']);
    Route::post('suppliers', [SupplierController::class, 'store']);
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
    Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);

    Route::get('reports/ap-aging', [ReportController::class, 'apAging']);
});
