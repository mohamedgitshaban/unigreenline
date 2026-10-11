<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\ReportController;
use Modules\Purchasing\Http\Controllers\SupplierController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index']);
    Route::post('suppliers/import', [SupplierController::class, 'import']);
    Route::get('suppliers/import-template', [SupplierController::class, 'importTemplate']);
    Route::post('suppliers', [SupplierController::class, 'store']);
    Route::post('suppliers/contracts', [SupplierController::class, 'uploadContract']);
    Route::get('suppliers/{id}/contract', [SupplierController::class, 'downloadContract']);
    Route::get('suppliers/{id}', [SupplierController::class, 'show']);
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy']);

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::post('purchase-orders/invoices', [PurchaseOrderController::class, 'uploadInvoice']);
    Route::get('purchase-orders/{id}/invoice', [PurchaseOrderController::class, 'downloadInvoice']);
    Route::get('purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::get('purchase-orders/{id}/lines', [PurchaseOrderController::class, 'lines']);
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
    Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);

    Route::get('reports/ap-aging', [ReportController::class, 'apAging']);
});
