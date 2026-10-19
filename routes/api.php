<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Payment\WebhookPaymentController;
use App\Http\Controllers\ApiV1Controller;
use App\Http\Controllers\ApiV1AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/payment/bkash/ipn', [WebhookPaymentController::class, 'handleBkashIpn']);
Route::middleware('auth:sanctum')->post('/payment/mfs/sms-receiver', [WebhookPaymentController::class, 'handleSmsReceiver']);

// Versioned API for the X-Link Android app / trusted server-side integration.
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/auth/login', [ApiV1AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
        Route::get('/auth/me', [ApiV1AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [ApiV1AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/customers', [ApiV1Controller::class, 'customers'])->name('customers.index');
        Route::post('/payments', [ApiV1Controller::class, 'createPayment'])->middleware('throttle:30,1')->name('payments.store');
    });
});


