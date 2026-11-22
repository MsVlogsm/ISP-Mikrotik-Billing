<?php

namespace App\Services;

use App\Models\User;

/**
 * API client scopes may narrow, but must never expand, the owning staff
 * account's current module permissions. Unknown scopes fail closed.
 */
class ApiScopeAuthorizer
{
    private const REQUIREMENTS = [
        'customers:read' => [['view-customer', 'all-customer']],
        'customers:contact:read' => [
            ['view-customer', 'all-customer'],
            ['customer-official-info'],
        ],
        'billing:read' => [['customer-billing-info', 'payment-collection-report', 'payment-history']],
        'packages:read' => [['package-setup']],
        'reports:read' => [
            ['view-customer', 'all-customer'],
            ['network-inventory', 'mikrotik-setup'],
        ],
        'network:read' => [['network-inventory', 'mikrotik-setup']],
    ];

    public static function allowedScopesFor(User $owner): array
    {
        if ($owner->hasRole('Super Admin')) {
            return array_keys(self::REQUIREMENTS);
        }

        return array_values(array_filter(array_keys(self::REQUIREMENTS), function (string $scope) use ($owner): bool {
            foreach (self::REQUIREMENTS[$scope] as $permissionGroup) {
                if (! $owner->hasAnyPermission($permissionGroup)) {
                    return false;
                }
            }

            return true;
        }));
    }

    public static function ownerCanUse(User $owner, array $scopes): bool
    {
        if ($scopes === []) {
            return false;
        }

        $allowed = self::allowedScopesFor($owner);
        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
