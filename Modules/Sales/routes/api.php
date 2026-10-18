<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\CollectionController;
use Modules\Sales\Http\Controllers\DeliveryController;
use Modules\Sales\Http\Controllers\InvoiceController;
use Modules\Sales\Http\Controllers\ReportController;
use Modules\Sales\Http\Controllers\ReturnController;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('sales-orders', [SalesOrderController::class, 'index'])->can('sales-orders.view')->exportable('sales-orders.export');
    Route::post('sales-orders', [SalesOrderController::class, 'store'])->can('sales-orders.create');
    Route::post('sales-orders/deliver', [SalesOrderController::class, 'storeAndDeliver'])->can('sales-orders.quick-deliver');
    Route::get('sales-orders/{id}', [SalesOrderController::class, 'show'])->can('sales-orders.view');
    Route::get('sales-orders/{id}/lines', [SalesOrderController::class, 'lines'])->can('sales-orders.view');
    Route::get('sales-orders/{id}/invoices', [InvoiceController::class, 'forSalesOrder'])->can('sales-orders.view');
    Route::put('sales-orders/{salesOrder}', [SalesOrderController::class, 'update'])->can('sales-orders.update');
    Route::delete('sales-orders/{salesOrder}', [SalesOrderController::class, 'destroy'])->can('sales-orders.delete');
    Route::put('sales-orders/{salesOrder}/status', [SalesOrderController::class, 'updateStatus'])->can('sales-orders.change-status');

    Route::get('invoices', [InvoiceController::class, 'index'])->can('invoices.view')->exportable('invoices.export');
    Route::post('invoices/import', [InvoiceController::class, 'import'])->can('invoices.import');
    Route::get('invoices/import-template', [InvoiceController::class, 'importTemplate'])->can('invoices.import');
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->can('invoices.view');
    Route::get('invoices/{id}/lines', [InvoiceController::class, 'lines'])->can('invoices.view');
    Route::get('invoices/{id}/collections', [CollectionController::class, 'forInvoice'])->can('collections.view');
    Route::post('invoices/{id}/void', [InvoiceController::class, 'void'])->can('invoices.void');

    Route::get('deliveries', [DeliveryController::class, 'index'])->can('deliveries.view')->exportable('deliveries.export');
    Route::post('deliveries/invoices', [DeliveryController::class, 'uploadInvoice'])->can('deliveries.upload-invoice');
    Route::get('deliveries/{id}/invoice', [DeliveryController::class, 'downloadInvoice'])->can('deliveries.view');
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->can('deliveries.view');
    Route::put('deliveries/{delivery}/deliver', [DeliveryController::class, 'deliver'])->can('deliveries.deliver');

    Route::get('collections', [CollectionController::class, 'index'])->can('collections.view')->exportable('collections.export');
    Route::post('collections', [CollectionController::class, 'store'])->can('collections.create');
    Route::get('collections/{id}', [CollectionController::class, 'show'])->can('collections.view');

    Route::get('customers/{id}/orders', [SalesOrderController::class, 'forCustomer'])->can('customers.view');
    Route::get('customers/{id}/invoices', [InvoiceController::class, 'forCustomer'])->can('customers.view');

    Route::get('returns', [ReturnController::class, 'index'])->can('returns.view')->exportable('returns.export');
    Route::post('returns', [ReturnController::class, 'store'])->can('returns.create');
    Route::get('returns/{id}', [ReturnController::class, 'show'])->can('returns.view');

    Route::get('reports/ar-aging', [ReportController::class, 'arAging'])->can('ar-aging.view');
});
