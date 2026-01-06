<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// login + logut
// jwt
// refactorizar(agregar servicios)
// crear front con angular, agregar auth, luego mensages.

Route::middleware('auth:sanctum')->group(function(){
    Route::get('/chat', [ChatController::class, 'index']);
    Route::get('/chat/{user}', [ChatController::class, 'show']);
    Route::post('/chat/{user}', [ChatController::class, 'store']);
});