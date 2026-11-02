<?php

use App\Http\Middleware\CheckSiteStatus;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust only explicitly configured reverse proxies when present.
        // Set TRUSTED_PROXIES to the proxy IP/CIDR list in production.
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR |
                    Request::HEADER_X_FORWARDED_HOST |
                    Request::HEADER_X_FORWARDED_PORT |
                    Request::HEADER_X_FORWARDED_PROTO
            );
        }
        $middleware->web(append: [
            \App\Http\Middleware\SetLocaleFromSession::class,
        ]);
        $middleware->append(CheckSiteStatus::class);
        $middleware->alias([
            'reseller'           => \App\Http\Middleware\EnsureUserIsReseller::class,
            'mac-reseller'       => \App\Http\Middleware\EnsureUserIsMacReseller::class,
            'restrict.profile'   => \App\Http\Middleware\RestrictToProfileIfNoPermissions::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            '/payment/bkash/callback',
            '/payment/nagad/callback',
            '/payment/sslcommerz/callback',
            '/payment/mock/submit',
            '/payment/stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
