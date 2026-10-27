<?php

use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\AccountSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => 'NexaCart API',
]));

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])
    ->middleware('throttle:10,1');

Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:10,1');
    
Route::get('/email/verify/{id}/{hash}', [
    AccountSettingsController::class,
    'verifyEmail',
])->middleware(['signed', 'throttle:verification-link'])
  ->name('nexacart.verification.verify');

Route::post(
    '/stripe/webhook',
    \App\Http\Controllers\StripeWebhookController::class
);