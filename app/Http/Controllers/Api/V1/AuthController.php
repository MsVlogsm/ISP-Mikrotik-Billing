<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($validated['email'])))->first();
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'The supplied login credentials are invalid.'], 401);
        }

        if (! $this->canUseMobileApi($user)) {
            return response()->json([
                'message' => 'This account does not have permission to use the mobile API.',
            ], 403);
        }

        // Keep one active token per app/device name to simplify revocation on re-login.
        $deviceName = config('mobile_api.device_name_prefix', 'android:').trim($validated['device_name']);
        $user->tokens()->where('name', $deviceName)->delete();

        $expiresAt = now()->addDays((int) config('mobile_api.token_expiration_days', 30));
        $newToken = $user->createToken($deviceName, ['mobile-api'], $expiresAt);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $newToken->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function canUseMobileApi(User $user): bool
    {
        if ($user->hasRole('Super Admin') || $user->hasRole('Reseller')) {
            return true;
        }

        return $user->hasAnyPermission([
            'view-customer', 'all-customer', 'payment-collection', 'payment-history',
            'stock-inventory-view', 'network-inventory', 'mikrotik-setup',
        ]);
    }

    private function userPayload(User $user): array
    {
        $permissions = $user->hasRole('Super Admin')
            ? \Spatie\Permission\Models\Permission::query()->orderBy('name')->pluck('name')->all()
            : $user->getAllPermissions()->pluck('name')->unique()->sort()->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $permissions,
        ];
    }
}
