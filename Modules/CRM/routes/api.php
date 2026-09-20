<?php

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\CampaignController;
use Modules\CRM\Http\Controllers\ComplaintController;
use Modules\CRM\Http\Controllers\CustomerController;
use Modules\CRM\Http\Controllers\CustomerVisitController;
use Modules\CRM\Http\Controllers\LeadController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store']);
    Route::get('customers/{customer}', [CustomerController::class, 'show']);
    Route::put('customers/{customer}', [CustomerController::class, 'update']);

    Route::get('leads', [LeadController::class, 'index']);
    Route::post('leads', [LeadController::class, 'store']);

    Route::get('visits', [CustomerVisitController::class, 'index']);
    Route::post('visits', [CustomerVisitController::class, 'store']);

    Route::get('complaints', [ComplaintController::class, 'index']);
    Route::post('complaints', [ComplaintController::class, 'store']);
    Route::put('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve']);

    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::post('campaigns', [CampaignController::class, 'store']);
    Route::put('campaigns/{campaign}/end', [CampaignController::class, 'end']);
});
