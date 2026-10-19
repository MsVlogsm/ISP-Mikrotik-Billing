<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MobileApiController;
use App\Http\Controllers\Payment\WebhookPaymentController;
use App\Http\Middleware\RequireMobileApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Backward-compatible Sanctum route retained for existing integrations.
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/payment/bkash/ipn', [WebhookPaymentController::class, 'handleBkashIpn']);
Route::middleware('auth:sanctum')->post('/payment/mfs/sms-receiver', [WebhookPaymentController::class, 'handleSmsReceiver']);

// Native Android clients use versioned JSON endpoints and Sanctum Bearer tokens.
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:'.config('mobile_api.login_per_minute', 10).',1')->name('auth.login');

    Route::middleware(['auth:sanctum', RequireMobileApiToken::class])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        Route::get('/dashboard', [MobileApiController::class, 'dashboard'])->name('dashboard');
        Route::get('/customers', [MobileApiController::class, 'customers'])->name('customers.index');
        Route::get('/customers/{customer}', [MobileApiController::class, 'customer'])->whereNumber('customer')->name('customers.show');
        Route::get('/customers/{customer}/billing', [MobileApiController::class, 'billing'])->whereNumber('customer')->name('customers.billing');
        Route::get('/customers/{customer}/payments', [MobileApiController::class, 'paymentHistory'])->whereNumber('customer')->name('customers.payments');
        Route::get('/packages', [MobileApiController::class, 'packages'])->name('packages.index');
    });
});
