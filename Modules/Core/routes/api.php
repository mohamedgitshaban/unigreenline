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
    Route::get('users', [UserController::class, 'index'])->can('users.view')->exportable('users.export');
    Route::post('users', [UserController::class, 'store'])->can('users.create');
    Route::get('users/{user}', [UserController::class, 'show'])->can('users.view');
    Route::put('users/{user}', [UserController::class, 'update'])->can('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->can('users.delete');

    Route::get('permissions', [RoleController::class, 'permissions'])->can('permissions.view');
    Route::get('roles', [RoleController::class, 'index'])->can('roles.view');
    Route::post('roles', [RoleController::class, 'store'])->can('roles.create');
    Route::get('roles/{role}', [RoleController::class, 'show'])->can('roles.view');
    Route::put('roles/{role}', [RoleController::class, 'update'])->can('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->can('roles.delete');
    Route::put('roles/{role}/users', [RoleController::class, 'assignUsers'])->can('roles.assign-users');

    Route::get('audit-log', [AuditLogController::class, 'index'])->can('audit-log.view')->exportable('audit-log.export');
    Route::post('audit-log/verify-integrity', [AuditLogController::class, 'verifyIntegrity'])->can('audit-log.verify');
    Route::get('audit-log/{id}', [AuditLogController::class, 'show'])->can('audit-log.view');

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::put('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);

    Route::get('discarded-actions', [DiscardedActionController::class, 'index'])->can('discarded-actions.view')->exportable('discarded-actions.export');
    Route::post('discarded-actions', [DiscardedActionController::class, 'store']);
    Route::get('discarded-actions/{id}', [DiscardedActionController::class, 'show'])->can('discarded-actions.view');

    Route::get('governorates', [CityController::class, 'governorates']);
    Route::get('governorates/{governorate}/cities', [CityController::class, 'cities']);
});
