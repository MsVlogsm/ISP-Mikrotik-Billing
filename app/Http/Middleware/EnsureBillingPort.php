<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBillingPort
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $port = $request->getPort();

        // Preserve the real public port when the app is behind Nginx/Apache
        // or another reverse proxy that forwards X-Forwarded-Port.
        $forwardedPort = $request->header('X-Forwarded-Port');
        if ($forwardedPort !== null && ctype_digit((string) $forwardedPort)) {
            $port = (int) $forwardedPort;
        }

        if (! in_array($host, ['bill.xlinkbd.net', 'billing.bill.xlinkbd.net'], true) || $port !== 8081) {
            abort(404);
        }

        return $next($request);
    }
}
