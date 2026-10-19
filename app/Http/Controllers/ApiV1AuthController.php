<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class ApiV1AuthController extends Controller
{
    /**
     * Authenticate an approved X-Link staff account and issue a short-lived,
     * read-only Sanctum token suitable for secure client storage.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()
            ->where('email', mb_strtolower(trim($validated['email'])))
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The supplied credentials are invalid.',
            ], 401);
        }

        // Do not give resellers a token that can read the whole customer list.
        if (! $user->hasAnyRole(['Super Admin', 'Admin', 'Manager'])) {
            return response()->json([
                'success' => false,
                'message' => 'This account is not permitted to use the staff Android API.',
            ], 403);
        }

        // Do not bypass confirmed two-factor authentication in the mobile API.
        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            return response()->json([
                'success' => false,
                'status' => 'two_factor_required',
                'message' => 'This account has two-factor authentication enabled; API two-factor verification must be configured before mobile sign-in.',
            ], 403);
        }

        $expiresAt = now()->addHours(12);
        $deviceName = trim((string) ($validated['device_name'] ?? 'Android app'));
        $deviceName = preg_replace('/[^A-Za-z0-9 ._:-]/', '', $deviceName) ?: 'Android app';

        $issuedToken = $user->createToken(
            'xlink-android: '.$deviceName,
            ['api:customers:read'],
            $expiresAt
        );

        return response()->json([
            'success' => true,
            'token_type' => 'Bearer',
            'access_token' => $issuedToken->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'abilities' => ['api:customers:read'],
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'success' => false,
                'message' => 'A valid API bearer token is required.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $request->user()->getKey(),
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'roles' => $request->user()->getRoleNames()->values(),
            ],
            'abilities' => $token->abilities ?? [],
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'success' => false,
                'message' => 'A valid API bearer token is required.',
            ], 401);
        }

        $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'API token revoked.',
        ]);
    }
}
