<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\AccountingDashboardController;
use Modules\Accounting\Http\Controllers\AssetCategoryController;
use Modules\Accounting\Http\Controllers\FinancialStatementController;
use Modules\Accounting\Http\Controllers\FixedAssetController;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Http\Controllers\LoanController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('dashboards/accounting', [AccountingDashboardController::class, 'show'])->defaults('module', 'accounting')->can('accounting-dashboard.view');
    Route::get('chart-of-accounts', [AccountController::class, 'index'])->can('chart-of-accounts.view')->exportable('chart-of-accounts.export');
    Route::post('chart-of-accounts', [AccountController::class, 'store'])->can('chart-of-accounts.create');
    Route::get('chart-of-accounts/connections', [AccountController::class, 'connections'])->can('chart-of-accounts.view');
    Route::get('chart-of-accounts/{id}', [AccountController::class, 'show'])->can('chart-of-accounts.view');
    Route::put('chart-of-accounts/{account}', [AccountController::class, 'update'])->can('chart-of-accounts.update');
    Route::delete('chart-of-accounts/{account}', [AccountController::class, 'destroy'])->can('chart-of-accounts.delete');
    Route::get('journal-entries', [JournalEntryController::class, 'index'])->can('journal-entries.view')->exportable('journal-entries.export');
    Route::post('journal-entries', [JournalEntryController::class, 'store'])->can('journal-entries.create');
    Route::get('journal-entries/{id}', [JournalEntryController::class, 'show'])->can('journal-entries.view');
    Route::post('journal-entries/{id}/reverse', [JournalEntryController::class, 'reverse'])->can('journal-entries.reverse');
    Route::get('journal-entries/{id}/lines', [JournalEntryController::class, 'lines'])->can('journal-entries.view');

    Route::get('asset-categories', [AssetCategoryController::class, 'index'])->can('asset-categories.view');
    Route::post('asset-categories', [AssetCategoryController::class, 'store'])->can('asset-categories.create');
    Route::get('asset-categories/{id}', [AssetCategoryController::class, 'show'])->can('asset-categories.view');
    Route::put('asset-categories/{category}', [AssetCategoryController::class, 'update'])->can('asset-categories.update');
    Route::delete('asset-categories/{category}', [AssetCategoryController::class, 'destroy'])->can('asset-categories.delete');

    Route::get('fixed-assets', [FixedAssetController::class, 'index'])->can('fixed-assets.view')->exportable('fixed-assets.export');
    Route::post('fixed-assets', [FixedAssetController::class, 'store'])->can('fixed-assets.create');
    Route::post('fixed-assets/preview', [FixedAssetController::class, 'preview'])->can('fixed-assets.preview');
    Route::post('fixed-assets/depreciate', [FixedAssetController::class, 'depreciate'])->can('fixed-assets.depreciate');
    Route::get('fixed-assets/summary', [FixedAssetController::class, 'summary'])->can('fixed-assets.summary');
    Route::get('fixed-assets/forecast', [FixedAssetController::class, 'forecast'])->can('fixed-assets.forecast');
    Route::get('fixed-assets/{id}', [FixedAssetController::class, 'show'])->can('fixed-assets.view');
    Route::get('fixed-assets/{id}/schedule', [FixedAssetController::class, 'schedule'])->can('fixed-assets.schedule');
    Route::put('fixed-assets/{asset}', [FixedAssetController::class, 'update'])->can('fixed-assets.update');
    Route::delete('fixed-assets/{asset}', [FixedAssetController::class, 'destroy'])->can('fixed-assets.delete');
    Route::post('fixed-assets/{id}/activate', [FixedAssetController::class, 'activate'])->can('fixed-assets.activate');
    Route::post('fixed-assets/{id}/depreciations/{line}/post', [FixedAssetController::class, 'postDepreciation'])->can('fixed-assets.post-depreciation');
    Route::post('fixed-assets/{id}/modify', [FixedAssetController::class, 'modify'])->can('fixed-assets.modify');
    Route::post('fixed-assets/{id}/pause', [FixedAssetController::class, 'pause'])->can('fixed-assets.pause');
    Route::post('fixed-assets/{id}/resume', [FixedAssetController::class, 'resume'])->can('fixed-assets.resume');
    Route::post('fixed-assets/{id}/dispose', [FixedAssetController::class, 'dispose'])->can('fixed-assets.dispose');
    Route::post('fixed-assets/{id}/cancel', [FixedAssetController::class, 'cancel'])->can('fixed-assets.cancel');

    Route::get('loans', [LoanController::class, 'index'])->can('loans.view')->exportable('loans.export');
    Route::post('loans', [LoanController::class, 'store'])->can('loans.create');
    Route::post('loans/preview', [LoanController::class, 'preview'])->can('loans.preview');
    Route::get('loans/summary', [LoanController::class, 'summary'])->can('loans.summary');
    Route::get('loans/due', [LoanController::class, 'due'])->can('loans.due');
    Route::get('loans/{id}', [LoanController::class, 'show'])->can('loans.view');
    Route::get('loans/{id}/schedule', [LoanController::class, 'schedule'])->can('loans.schedule');
    Route::put('loans/{loan}', [LoanController::class, 'update'])->can('loans.update');
    Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->can('loans.delete');
    Route::post('loans/{id}/activate', [LoanController::class, 'activate'])->can('loans.activate');
    Route::post('loans/{id}/installments/{sequence}/pay', [LoanController::class, 'payInstallment'])->can('loans.pay-installment');
    Route::post('loans/{id}/early-repayment', [LoanController::class, 'earlyRepayment'])->can('loans.early-repay');
    Route::post('loans/{id}/cancel', [LoanController::class, 'cancel'])->can('loans.cancel');

    Route::get('reports/balance-sheet', [FinancialStatementController::class, 'balanceSheet'])->can('balance-sheet.view');
    Route::get('reports/income-statement', [FinancialStatementController::class, 'incomeStatement'])->can('income-statement.view');
});
