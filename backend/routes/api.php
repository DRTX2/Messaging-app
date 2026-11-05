<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;

Broadcast::routes(['middleware' => ['auth:api']]);

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:api', 'throttle:120,1'])->group(function(){
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);

    // Conversation routes (Inbox)
    Route::get('/conversations', [App\Http\Controllers\ConversationController::class, 'index']);
    Route::post('/conversations/direct', [App\Http\Controllers\ConversationController::class, 'direct']);
    Route::get('/conversations/{conversation}/messages', [App\Http\Controllers\ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [App\Http\Controllers\ConversationController::class, 'send']);
    Route::post('/conversations/{conversation}/clear', [App\Http\Controllers\ConversationController::class, 'clear']);
    
    // Group routes
    Route::prefix('groups')->group(function () {
        Route::post('/', [App\Http\Controllers\GroupController::class, 'store']);
        Route::put('/{conversation}', [App\Http\Controllers\GroupController::class, 'update']);
        Route::delete('/{conversation}/leave', [App\Http\Controllers\GroupController::class, 'leave']);
        Route::post('/{conversation}/participants', [App\Http\Controllers\GroupController::class, 'addParticipants']);
        Route::delete('/{conversation}/participants/{userId}', [App\Http\Controllers\GroupController::class, 'removeParticipant']);
    });
    
    // Attachment routes
    Route::post('/attachments', [App\Http\Controllers\AttachmentController::class, 'store'])->middleware('throttle:30,1');

    // Typing indicator
    Route::post('/conversations/{conversation}/typing', [App\Http\Controllers\TypingController::class, 'typing']);

    // Chat routes
    Route::prefix('chat')->group(function () {
        Route::get('/', [ChatController::class, 'users']);
        Route::get('/unread-count', [ChatController::class, 'unreadCount']);
        
        // Specific message actions
        Route::post('/messages/{message}/favorite', [ChatController::class, 'toggleFavorite']);
        Route::delete('/messages/{message}', [ChatController::class, 'deleteMessage']);
        
        // User-specific actions
    });
});