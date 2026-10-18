<?php

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\CampaignController;
use Modules\CRM\Http\Controllers\ComplaintController;
use Modules\CRM\Http\Controllers\CustomerController;
use Modules\CRM\Http\Controllers\CustomerVisitController;
use Modules\CRM\Http\Controllers\LeadController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('customers', [CustomerController::class, 'index'])->can('customers.view')->exportable('customers.export');
    Route::post('customers/import', [CustomerController::class, 'import'])->can('customers.import');
    Route::get('customers/import-template', [CustomerController::class, 'importTemplate'])->can('customers.import');
    Route::post('customers', [CustomerController::class, 'store'])->can('customers.create');
    Route::get('customers/{id}', [CustomerController::class, 'show'])->can('customers.view');
    Route::get('customers/{id}/visits', [CustomerVisitController::class, 'forCustomer'])->can('customers.view');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->can('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->can('customers.delete');

    Route::get('leads', [LeadController::class, 'index'])->can('leads.view')->exportable('leads.export');
    Route::post('leads', [LeadController::class, 'store'])->can('leads.create');
    Route::get('leads/{id}', [LeadController::class, 'show'])->can('leads.view');
    Route::put('leads/{lead}', [LeadController::class, 'update'])->can('leads.update');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->can('leads.delete');

    Route::get('visits', [CustomerVisitController::class, 'index'])->can('visits.view')->exportable('visits.export');
    Route::post('visits', [CustomerVisitController::class, 'store'])->can('visits.create');
    Route::get('visits/{id}', [CustomerVisitController::class, 'show'])->can('visits.view');
    Route::put('visits/{visit}', [CustomerVisitController::class, 'update'])->can('visits.update');
    Route::delete('visits/{visit}', [CustomerVisitController::class, 'destroy'])->can('visits.delete');

    Route::get('complaints', [ComplaintController::class, 'index'])->can('complaints.view')->exportable('complaints.export');
    Route::post('complaints', [ComplaintController::class, 'store'])->can('complaints.create');
    Route::get('complaints/{id}', [ComplaintController::class, 'show'])->can('complaints.view');
    Route::put('complaints/{complaint}', [ComplaintController::class, 'update'])->can('complaints.update');
    Route::delete('complaints/{complaint}', [ComplaintController::class, 'destroy'])->can('complaints.delete');
    Route::put('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve'])->can('complaints.resolve');

    Route::get('campaigns', [CampaignController::class, 'index'])->can('campaigns.view')->exportable('campaigns.export');
    Route::post('campaigns', [CampaignController::class, 'store'])->can('campaigns.create');
    Route::get('campaigns/{id}', [CampaignController::class, 'show'])->can('campaigns.view');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->can('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->can('campaigns.delete');
    Route::put('campaigns/{campaign}/end', [CampaignController::class, 'end'])->can('campaigns.end');
});
