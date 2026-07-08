<?php

use App\Http\Controllers\Api\MasothueIngestionController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/ingestions/masothue', [MasothueIngestionController::class, 'store']);
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'store']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
