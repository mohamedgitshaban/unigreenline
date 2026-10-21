<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\GoodsReceiptController;
use Modules\Inventory\Http\Controllers\InventoryDashboardController;
use Modules\Inventory\Http\Controllers\ProductCategoryController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\TransferController;
use Modules\Inventory\Http\Controllers\UnitController;
use Modules\Inventory\Http\Controllers\WarehouseActivityController;
use Modules\Inventory\Http\Controllers\WarehouseController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('dashboards/inventory', [InventoryDashboardController::class, 'show'])->defaults('module', 'inventory')->can('inventory-dashboard.view');
    Route::get('warehouses', [WarehouseController::class, 'index'])->can('warehouses.view')->exportable('warehouses.export');
    Route::post('warehouses/import', [WarehouseController::class, 'import'])->can('warehouses.import');
    Route::get('warehouses/import-template', [WarehouseController::class, 'importTemplate'])->can('warehouses.import');
    Route::post('warehouses', [WarehouseController::class, 'store'])->can('warehouses.create');
    Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->can('warehouses.view');
    Route::get('warehouses/{id}/batches', [WarehouseController::class, 'batches'])->can('warehouses.view');
    Route::get('warehouses/{id}/overview', [WarehouseActivityController::class, 'overview'])->can('warehouses.overview');
    Route::get('warehouses/{id}/movements', [WarehouseActivityController::class, 'movements'])->can('warehouses.movements')->exportable('warehouses.export');
    Route::get('warehouses/{id}/incoming', [WarehouseActivityController::class, 'incoming'])->can('warehouses.incoming');
    Route::get('warehouses/{id}/outgoing', [WarehouseActivityController::class, 'outgoing'])->can('warehouses.outgoing');
    Route::get('warehouses/{id}/users', [WarehouseActivityController::class, 'users'])->can('warehouses.view');
    Route::put('warehouses/{id}/users', [WarehouseActivityController::class, 'assignUsers'])->can('warehouses.assign-users');
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->can('warehouses.update');
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->can('warehouses.delete');

    Route::get('products', [ProductController::class, 'index'])->can('products.view')->exportable('products.export');
    Route::post('products/import', [ProductController::class, 'import'])->can('products.import');
    Route::get('products/import-template', [ProductController::class, 'importTemplate'])->can('products.import');
    Route::post('products', [ProductController::class, 'store'])->can('products.create');
    Route::get('products/{id}', [ProductController::class, 'show'])->can('products.view');
    Route::get('products/{id}/batches', [ProductController::class, 'batches'])->can('products.view');
    Route::get('products/{id}/movements', [WarehouseActivityController::class, 'productMovements'])->can('products.movements')->exportable('products.export');
    Route::get('products/{id}/availability', [ProductController::class, 'availability'])->can('products.availability');
    Route::put('products/{product}', [ProductController::class, 'update'])->can('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->can('products.delete');

    Route::get('categories', [ProductCategoryController::class, 'index'])->can('categories.view')->exportable('categories.export');
    Route::post('categories', [ProductCategoryController::class, 'store'])->can('categories.create');
    Route::get('categories/{id}', [ProductCategoryController::class, 'show'])->can('categories.view');
    Route::put('categories/{category}', [ProductCategoryController::class, 'update'])->can('categories.update');
    Route::delete('categories/{category}', [ProductCategoryController::class, 'destroy'])->can('categories.delete');

    Route::get('units', [UnitController::class, 'index'])->can('units.view');
    Route::post('units', [UnitController::class, 'store'])->can('units.create');
    Route::get('units/{unit}', [UnitController::class, 'show'])->can('units.view');
    Route::put('units/{unit}', [UnitController::class, 'update'])->can('units.update');
    Route::delete('units/{unit}', [UnitController::class, 'destroy'])->can('units.delete');

    Route::post('inventory/grn', [GoodsReceiptController::class, 'store'])->can('goods-receipts.create');

    Route::get('transfers', [TransferController::class, 'index'])->can('transfers.view')->exportable('transfers.export');
    Route::post('transfers', [TransferController::class, 'store'])->can('transfers.create');
    Route::get('transfers/{id}', [TransferController::class, 'show'])->can('transfers.view');
    Route::get('transfers/{id}/print', [TransferController::class, 'print'])->can('transfers.print');
});
