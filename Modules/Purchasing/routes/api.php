<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\ReportController;
use Modules\Purchasing\Http\Controllers\SupplierController;
use Modules\Purchasing\Http\Controllers\SupplierPaymentController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index'])->can('suppliers.view')->exportable('suppliers.export');
    Route::post('suppliers/import', [SupplierController::class, 'import'])->can('suppliers.import');
    Route::get('suppliers/import-template', [SupplierController::class, 'importTemplate'])->can('suppliers.import');
    Route::post('suppliers', [SupplierController::class, 'store'])->can('suppliers.create');
    Route::post('suppliers/contracts', [SupplierController::class, 'uploadContract'])->can('suppliers.upload-contract');
    Route::get('suppliers/{id}/contract', [SupplierController::class, 'downloadContract'])->can('suppliers.view');
    Route::get('suppliers/{id}', [SupplierController::class, 'show'])->can('suppliers.view');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->can('suppliers.update');
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->can('suppliers.delete');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->can('purchase-orders.view')->exportable('purchase-orders.export');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->can('purchase-orders.create');
    Route::post('purchase-orders/invoices', [PurchaseOrderController::class, 'uploadInvoice'])->can('purchase-orders.upload-invoice');
    Route::get('purchase-orders/pending-pricing', [PurchaseOrderController::class, 'pendingPricing'])->can('purchase-orders.pricing');
    Route::get('purchase-orders/{id}/invoice', [PurchaseOrderController::class, 'downloadInvoice'])->can('purchase-orders.view');
    Route::get('purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->can('purchase-orders.view');
    Route::get('purchase-orders/{id}/lines', [PurchaseOrderController::class, 'lines'])->can('purchase-orders.view');
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->can('purchase-orders.update');
    Route::put('purchase-orders/{purchaseOrder}/pricing', [PurchaseOrderController::class, 'price'])->can('purchase-orders.pricing');
    Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->can('purchase-orders.delete');
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->can('purchase-orders.receive');

    Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->can('supplier-payments.view')->exportable('supplier-payments.export');
    Route::post('supplier-payments', [SupplierPaymentController::class, 'store'])->can('supplier-payments.create');
    Route::get('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'show'])->can('supplier-payments.view');

    Route::get('reports/ap-aging', [ReportController::class, 'apAging'])->can('ap-aging.view');
});
