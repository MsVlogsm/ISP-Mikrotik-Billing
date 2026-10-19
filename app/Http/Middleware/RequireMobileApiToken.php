<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/** Enforce a current, unexpired personal-access-token for native mobile clients. */
class RequireMobileApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        $validToken = $user
            && $token instanceof PersonalAccessToken
            && ! ($token instanceof TransientToken)
            && $token->can('mobile-api')
            && (! $token->expires_at || $token->expires_at->isFuture())
            // Re-query storage so deleted/revoked tokens are rejected immediately, including long-lived workers.
            && PersonalAccessToken::query()
                ->whereKey($token->getKey())
                ->where('tokenable_id', $user->getKey())
                ->where('tokenable_type', $user->getMorphClass())
                ->exists();

        abort_unless($validToken, 401, 'A valid Android/mobile API bearer token is required.');

        return $next($request);
    }
}
