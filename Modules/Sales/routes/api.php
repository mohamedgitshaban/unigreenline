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
    Route::post('sales-orders/deliver', [SalesOrderController::class, 'storeAndDeliver']);
    Route::get('sales-orders/{id}', [SalesOrderController::class, 'show']);
    Route::get('sales-orders/{id}/lines', [SalesOrderController::class, 'lines']);
    Route::get('sales-orders/{id}/invoices', [InvoiceController::class, 'forSalesOrder']);
    Route::put('sales-orders/{salesOrder}', [SalesOrderController::class, 'update']);
    Route::delete('sales-orders/{salesOrder}', [SalesOrderController::class, 'destroy']);
    Route::put('sales-orders/{salesOrder}/status', [SalesOrderController::class, 'updateStatus']);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::post('invoices/import', [InvoiceController::class, 'import']);
    Route::get('invoices/import-template', [InvoiceController::class, 'importTemplate']);
    Route::get('invoices/{id}', [InvoiceController::class, 'show']);
    Route::get('invoices/{id}/lines', [InvoiceController::class, 'lines']);
    Route::get('invoices/{id}/collections', [CollectionController::class, 'forInvoice']);
    Route::post('invoices/{id}/void', [InvoiceController::class, 'void']);

    Route::get('deliveries', [DeliveryController::class, 'index']);
    Route::post('deliveries/invoices', [DeliveryController::class, 'uploadInvoice']);
    Route::get('deliveries/{id}/invoice', [DeliveryController::class, 'downloadInvoice']);
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show']);
    Route::put('deliveries/{delivery}/deliver', [DeliveryController::class, 'deliver']);

    Route::get('collections', [CollectionController::class, 'index']);
    Route::post('collections', [CollectionController::class, 'store']);
    Route::get('collections/{id}', [CollectionController::class, 'show']);

    Route::get('customers/{id}/orders', [SalesOrderController::class, 'forCustomer']);
    Route::get('customers/{id}/invoices', [InvoiceController::class, 'forCustomer']);

    Route::get('returns', [ReturnController::class, 'index']);
    Route::post('returns', [ReturnController::class, 'store']);
    Route::get('returns/{id}', [ReturnController::class, 'show']);

    Route::get('reports/ar-aging', [ReportController::class, 'arAging']);
});
