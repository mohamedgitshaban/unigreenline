<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\GoodsReceiptController;
use Modules\Inventory\Http\Controllers\ProductCategoryController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\TransferController;
use Modules\Inventory\Http\Controllers\WarehouseController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::post('warehouses', [WarehouseController::class, 'store']);
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::put('products/{product}', [ProductController::class, 'update']);

    Route::get('categories', [ProductCategoryController::class, 'index']);
    Route::post('categories', [ProductCategoryController::class, 'store']);

    Route::post('inventory/grn', [GoodsReceiptController::class, 'store']);

    Route::get('transfers', [TransferController::class, 'index']);
    Route::post('transfers', [TransferController::class, 'store']);
});
