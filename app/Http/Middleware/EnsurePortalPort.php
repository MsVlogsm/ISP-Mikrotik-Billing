<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalPort
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

        $allowedPort = $port === 8082 || ($port === 443 && $request->isSecure());

        if (! in_array($host, ['bill.xlinkbd.net', 'portal.bill.xlinkbd.net'], true) || ! $allowedPort) {
            abort(404);
        }

        return $next($request);
    }
}
