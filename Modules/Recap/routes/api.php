<?php

use Illuminate\Support\Facades\Route;
use Modules\Recap\Http\Controllers\RecapController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('recaps', [RecapController::class, 'index'])->can('recaps.view')->exportable('recaps.export');
    Route::post('recaps', [RecapController::class, 'store'])->can('recaps.create');
    Route::get('recaps/expired-batches', [RecapController::class, 'expiredBatches'])->can('recaps.view');
    Route::post('recaps/from-expired', [RecapController::class, 'generateFromExpired'])->can('recaps.generate');
    Route::get('recaps/{recap}', [RecapController::class, 'show'])->can('recaps.view');
    Route::put('recaps/{recap}', [RecapController::class, 'update'])->can('recaps.update');
    Route::delete('recaps/{recap}', [RecapController::class, 'destroy'])->can('recaps.delete');
    Route::post('recaps/{recap}/approve', [RecapController::class, 'approve'])->can('recaps.approve');
    Route::post('recaps/{recap}/reject', [RecapController::class, 'reject'])->can('recaps.reject');
});
