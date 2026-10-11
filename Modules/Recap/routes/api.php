<?php

use Illuminate\Support\Facades\Route;
use Modules\Recap\Http\Controllers\RecapController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('recaps', [RecapController::class, 'index']);
    Route::post('recaps', [RecapController::class, 'store']);
    Route::get('recaps/expired-batches', [RecapController::class, 'expiredBatches']);
    Route::post('recaps/from-expired', [RecapController::class, 'generateFromExpired']);
    Route::get('recaps/{recap}', [RecapController::class, 'show']);
    Route::put('recaps/{recap}', [RecapController::class, 'update']);
    Route::delete('recaps/{recap}', [RecapController::class, 'destroy']);
    Route::post('recaps/{recap}/approve', [RecapController::class, 'approve']);
    Route::post('recaps/{recap}/reject', [RecapController::class, 'reject']);
});
