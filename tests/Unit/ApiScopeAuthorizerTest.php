<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ApiScopeAuthorizer;
use Mockery;
use Tests\TestCase;

class ApiScopeAuthorizerTest extends TestCase
{
    public function test_super_admin_can_use_only_known_api_scopes(): void
    {
        $owner = Mockery::mock(User::class)->makePartial();
        $owner->shouldReceive('hasRole')->with('Super Admin')->andReturn(true);

        $this->assertTrue(ApiScopeAuthorizer::ownerCanUse($owner, ['customers:read', 'network:read']));
        $this->assertFalse(ApiScopeAuthorizer::ownerCanUse($owner, ['customers:write']));
        $this->assertFalse(ApiScopeAuthorizer::ownerCanUse($owner, []));
    }

    public function test_non_super_admin_cannot_mint_scopes_outside_current_module_permissions(): void
    {
        $owner = Mockery::mock(User::class)->makePartial();
        $owner->shouldReceive('hasRole')->with('Super Admin')->andReturn(false);
        $owner->shouldReceive('hasAnyPermission')->andReturnUsing(
            fn ($permissions) => in_array('network-inventory', (array) $permissions, true)
        );

        $this->assertTrue(ApiScopeAuthorizer::ownerCanUse($owner, ['network:read']));
        $this->assertFalse(ApiScopeAuthorizer::ownerCanUse($owner, ['customers:read']));
        $this->assertFalse(ApiScopeAuthorizer::ownerCanUse($owner, ['network:read', 'billing:read']));
    }
}
