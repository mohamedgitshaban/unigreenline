<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\FinancialStatementController;
use Modules\Accounting\Http\Controllers\JournalEntryController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
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

    Route::get('reports/balance-sheet', [FinancialStatementController::class, 'balanceSheet'])->can('balance-sheet.view');
    Route::get('reports/income-statement', [FinancialStatementController::class, 'incomeStatement'])->can('income-statement.view');
});
