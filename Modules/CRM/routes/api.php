<?php

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\CampaignController;
use Modules\CRM\Http\Controllers\ComplaintController;
use Modules\CRM\Http\Controllers\CustomerController;
use Modules\CRM\Http\Controllers\CustomerVisitController;
use Modules\CRM\Http\Controllers\LeadController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers/import', [CustomerController::class, 'import']);
    Route::get('customers/import-template', [CustomerController::class, 'importTemplate']);
    Route::post('customers', [CustomerController::class, 'store']);
    Route::get('customers/{id}', [CustomerController::class, 'show']);
    Route::get('customers/{id}/visits', [CustomerVisitController::class, 'forCustomer']);
    Route::put('customers/{customer}', [CustomerController::class, 'update']);
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);

    Route::get('leads', [LeadController::class, 'index']);
    Route::post('leads', [LeadController::class, 'store']);
    Route::get('leads/{id}', [LeadController::class, 'show']);
    Route::put('leads/{lead}', [LeadController::class, 'update']);
    Route::delete('leads/{lead}', [LeadController::class, 'destroy']);

    Route::get('visits', [CustomerVisitController::class, 'index']);
    Route::post('visits', [CustomerVisitController::class, 'store']);
    Route::get('visits/{id}', [CustomerVisitController::class, 'show']);
    Route::put('visits/{visit}', [CustomerVisitController::class, 'update']);
    Route::delete('visits/{visit}', [CustomerVisitController::class, 'destroy']);

    Route::get('complaints', [ComplaintController::class, 'index']);
    Route::post('complaints', [ComplaintController::class, 'store']);
    Route::get('complaints/{id}', [ComplaintController::class, 'show']);
    Route::put('complaints/{complaint}', [ComplaintController::class, 'update']);
    Route::delete('complaints/{complaint}', [ComplaintController::class, 'destroy']);
    Route::put('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve']);

    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::post('campaigns', [CampaignController::class, 'store']);
    Route::get('campaigns/{id}', [CampaignController::class, 'show']);
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update']);
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy']);
    Route::put('campaigns/{campaign}/end', [CampaignController::class, 'end']);
});
