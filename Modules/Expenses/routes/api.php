<?php

use Illuminate\Support\Facades\Route;
use Modules\Expenses\Http\Controllers\ExpenseCategoryController;
use Modules\Expenses\Http\Controllers\ExpenseController;
use Modules\Expenses\Http\Controllers\ExpensesDashboardController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('dashboards/expenses', [ExpensesDashboardController::class, 'show'])->defaults('module', 'expenses')->can('expenses-dashboard.view');
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->can('expense-categories.view');
    Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->can('expense-categories.create');
    Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->can('expense-categories.update');
    Route::delete('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->can('expense-categories.delete');

    Route::get('expenses', [ExpenseController::class, 'index'])->can('expenses.view')->exportable('expenses.export');
    Route::post('expenses', [ExpenseController::class, 'store'])->can('expenses.create');
    Route::post('expenses/receipts', [ExpenseController::class, 'uploadReceipt'])->can('expenses.upload-receipt');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->can('expenses.view');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->can('expenses.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->can('expenses.delete');
    Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->can('expenses.approve');
    Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->can('expenses.reject');
    Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt'])->can('expenses.view');
});
