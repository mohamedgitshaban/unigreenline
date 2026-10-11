<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\GoodsReceiptController;
use Modules\Inventory\Http\Controllers\ProductCategoryController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\TransferController;
use Modules\Inventory\Http\Controllers\UnitController;
use Modules\Inventory\Http\Controllers\WarehouseController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::post('warehouses/import', [WarehouseController::class, 'import']);
    Route::get('warehouses/import-template', [WarehouseController::class, 'importTemplate']);
    Route::post('warehouses', [WarehouseController::class, 'store']);
    Route::get('warehouses/{id}', [WarehouseController::class, 'show']);
    Route::get('warehouses/{id}/batches', [WarehouseController::class, 'batches']);
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy']);

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products/import', [ProductController::class, 'import']);
    Route::get('products/import-template', [ProductController::class, 'importTemplate']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{id}', [ProductController::class, 'show']);
    Route::get('products/{id}/batches', [ProductController::class, 'batches']);
    Route::get('products/{id}/availability', [ProductController::class, 'availability']);
    Route::put('products/{product}', [ProductController::class, 'update']);
    Route::delete('products/{product}', [ProductController::class, 'destroy']);

    Route::get('categories', [ProductCategoryController::class, 'index']);
    Route::post('categories', [ProductCategoryController::class, 'store']);
    Route::get('categories/{id}', [ProductCategoryController::class, 'show']);
    Route::put('categories/{category}', [ProductCategoryController::class, 'update']);
    Route::delete('categories/{category}', [ProductCategoryController::class, 'destroy']);

    Route::get('units', [UnitController::class, 'index']);
    Route::post('units', [UnitController::class, 'store']);
    Route::get('units/{unit}', [UnitController::class, 'show']);
    Route::put('units/{unit}', [UnitController::class, 'update']);
    Route::delete('units/{unit}', [UnitController::class, 'destroy']);

    Route::post('inventory/grn', [GoodsReceiptController::class, 'store']);

    Route::get('transfers', [TransferController::class, 'index']);
    Route::post('transfers', [TransferController::class, 'store']);
    Route::get('transfers/{id}', [TransferController::class, 'show']);
});
