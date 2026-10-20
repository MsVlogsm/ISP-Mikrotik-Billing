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

        // The billing application is also served securely on the canonical HTTPS host.
        // Keep the legacy :8081 endpoint working while allowing authenticated dashboard
        // and billing routes on bill.xlinkbd.net:443 only when the request is actually HTTPS.
        $isCanonicalHttps = $host === 'bill.xlinkbd.net'
            && $port === 443
            && $request->isSecure();

        if (! in_array($host, ['bill.xlinkbd.net', 'billing.bill.xlinkbd.net'], true)
            || ($port !== 8081 && ! $isCanonicalHttps)) {
            abort(404);
        }

        return $next($request);
    }
}
