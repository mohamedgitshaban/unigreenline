<?php

use Illuminate\Support\Facades\Route;
use Modules\Expenses\Http\Controllers\ExpenseCategoryController;
use Modules\Expenses\Http\Controllers\ExpenseController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index']);
    Route::post('expense-categories', [ExpenseCategoryController::class, 'store']);
    Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update']);

    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expenses', [ExpenseController::class, 'store']);
    Route::post('expenses/receipts', [ExpenseController::class, 'uploadReceipt']);
    Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
    Route::put('expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
    Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve']);
    Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject']);
    Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt']);
});
