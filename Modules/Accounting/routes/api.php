<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\FinancialStatementController;
use Modules\Accounting\Http\Controllers\JournalEntryController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('chart-of-accounts', [AccountController::class, 'index']);
    Route::post('chart-of-accounts', [AccountController::class, 'store']);
    Route::get('chart-of-accounts/{id}', [AccountController::class, 'show']);
    Route::put('chart-of-accounts/{account}', [AccountController::class, 'update']);
    Route::delete('chart-of-accounts/{account}', [AccountController::class, 'destroy']);
    Route::get('journal-entries', [JournalEntryController::class, 'index']);
    Route::get('journal-entries/{id}', [JournalEntryController::class, 'show']);
    Route::get('journal-entries/{id}/lines', [JournalEntryController::class, 'lines']);

    Route::get('reports/balance-sheet', [FinancialStatementController::class, 'balanceSheet']);
    Route::get('reports/income-statement', [FinancialStatementController::class, 'incomeStatement']);
});
