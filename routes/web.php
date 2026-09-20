<?php

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', [ApiDocsController::class, 'index'])->name('docs.index');
Route::get('/docs/{page}', [ApiDocsController::class, 'show'])->name('docs.show');
