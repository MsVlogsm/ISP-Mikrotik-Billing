<?php

namespace App\Http\Middleware;

use App\Models\IntegrationApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ApplyApiClientRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return response()->json(['success' => false, 'message' => 'A valid bearer token is required.'], 401);
        }

        $clientId = str_starts_with((string) $token->name, 'api-client:')
            ? substr((string) $token->name, strlen('api-client:'))
            : '';

        $client = $clientId !== '' ? IntegrationApiClient::where('client_id', $clientId)->first() : null;
        if (! $client || ! $client->is_active || $client->user_id !== $request->user()->id) {
            $token->delete();
            return response()->json(['success' => false, 'message' => 'API client is inactive or revoked.'], 403);
        }

        $key = 'integration-api-client:'.$client->id;
        if (RateLimiter::tooManyAttempts($key, max(10, (int) $client->rate_limit_per_minute))) {
            $retryAfter = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => 'API client rate limit exceeded.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, 60);
        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
