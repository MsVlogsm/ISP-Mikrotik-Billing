<?php

namespace App\Http\Controllers;

use App\Models\IntegrationApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ApiV2AuthController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:80'],
            'client_secret' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $client = IntegrationApiClient::where('client_id', $data['client_id'])->first();
        if (
            ! $client
            || ! $client->is_active
            || ! hash_equals((string) $client->client_secret_hash, hash('sha256', $data['client_secret']))
        ) {
            return response()->json(['success' => false, 'message' => 'Invalid API client credentials.'], 401);
        }

        $owner = $client->owner;
        if (! $owner || ! $owner->hasAnyRole(['Super Admin', 'Admin', 'Manager'])) {
            return response()->json(['success' => false, 'message' => 'API client owner is not permitted to use this API.'], 403);
        }

        $deviceName = preg_replace('/[^A-Za-z0-9 ._:-]/', '', trim((string) ($data['device_name'] ?? 'iPilot integration'))) ?: 'iPilot integration';
        $expiresAt = now()->addHour();
        $issued = $owner->createToken('api-client:'.$client->client_id, $client->scopes ?? [], $expiresAt);
        $client->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'success' => true,
            'token_type' => 'Bearer',
            'access_token' => $issued->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'scopes' => $client->scopes ?? [],
        ]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token) return response()->json(['success' => false, 'message' => 'A valid bearer token is required.'], 401);
        $token->delete();
        return response()->json(['success' => true, 'message' => 'Access token revoked.']);
    }
}
