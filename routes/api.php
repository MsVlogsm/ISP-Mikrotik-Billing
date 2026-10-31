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

// iPilot API v2: client-credential token exchange and scoped read-only resources.
Route::prefix('v2')->name('api.v2.')->group(function () {
    Route::post('/auth/token', [\App\Http\Controllers\ApiV2AuthController::class, 'token'])
        ->middleware('throttle:6,1')->name('auth.token');

    Route::middleware([
        'auth:sanctum',
        \App\Http\Middleware\ApplyApiClientRateLimit::class,
        'throttle:600,1',
    ])->group(function () {
        Route::post('/auth/revoke', [\App\Http\Controllers\ApiV2AuthController::class, 'revoke'])->name('auth.revoke');
        Route::get('/customers', [\App\Http\Controllers\ApiV2Controller::class, 'customers'])->name('customers.index');
        Route::get('/network/routers', [\App\Http\Controllers\ApiV2Controller::class, 'routers'])->name('network.routers');
        Route::get('/network/olts', [\App\Http\Controllers\ApiV2Controller::class, 'olts'])->name('network.olts');
        Route::get('/network/onus', [\App\Http\Controllers\ApiV2Controller::class, 'onus'])->name('network.onus');
        Route::get('/reports/summary', [\App\Http\Controllers\ApiV2Controller::class, 'summary'])->name('reports.summary');
    });
});
