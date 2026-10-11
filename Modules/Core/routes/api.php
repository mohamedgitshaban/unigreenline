<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AuditLogController;
use Modules\Core\Http\Controllers\Auth\AuthController;
use Modules\Core\Http\Controllers\CityController;
use Modules\Core\Http\Controllers\DiscardedActionController;
use Modules\Core\Http\Controllers\NotificationController;
use Modules\Core\Http\Controllers\RoleController;
use Modules\Core\Http\Controllers\UserController;

Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);

    Route::get('permissions', [RoleController::class, 'permissions']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::get('roles/{role}', [RoleController::class, 'show']);
    Route::put('roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    Route::put('roles/{role}/users', [RoleController::class, 'assignUsers']);

    Route::get('audit-log', [AuditLogController::class, 'index']);
    Route::post('audit-log/verify-integrity', [AuditLogController::class, 'verifyIntegrity']);
    Route::get('audit-log/{id}', [AuditLogController::class, 'show']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::put('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);

    Route::get('discarded-actions', [DiscardedActionController::class, 'index']);
    Route::post('discarded-actions', [DiscardedActionController::class, 'store']);
    Route::get('discarded-actions/{id}', [DiscardedActionController::class, 'show']);

    Route::get('governorates', [CityController::class, 'governorates']);
    Route::get('governorates/{governorate}/cities', [CityController::class, 'cities']);
});
