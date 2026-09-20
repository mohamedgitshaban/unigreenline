<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\FinancialStatementController;
use Modules\Accounting\Http\Controllers\JournalEntryController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('chart-of-accounts', [AccountController::class, 'index']);
    Route::get('journal-entries', [JournalEntryController::class, 'index']);

    Route::get('reports/balance-sheet', [FinancialStatementController::class, 'balanceSheet']);
    Route::get('reports/income-statement', [FinancialStatementController::class, 'incomeStatement']);
});
