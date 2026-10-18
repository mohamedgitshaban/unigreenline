<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatUserController;
use Modules\Chat\Http\Controllers\ConversationController;
use Modules\Chat\Http\Controllers\MessageAttachmentController;
use Modules\Chat\Http\Controllers\MessageController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('chat-users', [ChatUserController::class, 'index'])->can('chat-users.view');

    Route::get('conversations', [ConversationController::class, 'index'])->can('conversations.view');
    Route::post('conversations', [ConversationController::class, 'store'])->can('conversations.create');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->can('conversations.view');
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->can('conversations.read');

    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->can('messages.view');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->can('messages.create');

    Route::get('message-attachments/{messageAttachment}', [MessageAttachmentController::class, 'show'])->can('messages.view')->name('message-attachments.show');
});
