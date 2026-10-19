<?php

namespace Tests\Feature;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\PackageList;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileApiV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['role_permission_matrix' => require base_path('config/role_permission_matrix.php')]);
        config(['mobile_api.token_expiration_days' => 30]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Admin', 'Admin', 'Manager', 'Reseller'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $names = collect(config('role_permission_matrix'))->pluck('permissions')->flatten()->flatten()
            ->merge(['stock-inventory-view', 'stock-inventory-report', 'stock-inventory-movement', 'stock-inventory-manage', 'stock-inventory-delete'])
            ->unique();
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['Admin', 'Manager'] as $name) {
            $permissions = collect(config("role_permission_matrix.{$name}.permissions"))->flatten()->unique();
            if ($name === 'Admin') {
                $permissions = $permissions->merge(['stock-inventory-view', 'stock-inventory-report', 'stock-inventory-movement', 'stock-inventory-manage', 'stock-inventory-delete']);
            } else {
                $permissions = $permissions->merge(['stock-inventory-view', 'stock-inventory-report', 'stock-inventory-movement']);
            }
            Role::findByName($name, 'web')->syncPermissions(
                Permission::where('guard_name', 'web')->whereIn('name', $permissions)->get()
            );
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function manager(): User
    {
        $user = User::factory()->create([
            'email' => 'android.manager@example.test',
            'password' => Hash::make('CorrectHorseBatteryStaple!'),
        ]);
        $user->assignRole(Role::findByName('Manager', 'web'));
        return $user;
    }

    private function createCustomer(string $cid, ?int $packageId = null, ?string $status = 'active', ?int $resellerId = null): CustomersInfo
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => $cid,
            'customer_name' => 'Customer '.$cid,
            'mobile' => '01712345678',
            'email' => strtolower($cid).'@example.test',
            'package_id' => $packageId,
            'status' => $status,
            'reseller_id' => $resellerId,
        ]);

        BillingInfo::create([
            'customer_bill_unique_id' => $cid,
            'monthly_rent' => 1000,
            'billing_type' => 'postpaid',
            'total_amount' => 1000,
            'paid_amount' => 250,
            'due_amount' => 750,
        ]);

        return $customer;
    }

    private function tokenFor(User $user, string $device = 'test-device'): string
    {
        return $user->createToken('android:'.$device, ['mobile-api'], now()->addDays(30))->plainTextToken;
    }

    public function test_login_returns_a_short_lived_bearer_token_and_me_endpoint_works(): void
    {
        $user = $this->manager();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectHorseBatteryStaple!',
            'device_name' => 'Pixel Test Phone',
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('user.email', $user->email);

        $token = $response->json('access_token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $storedToken = PersonalAccessToken::query()->where('tokenable_id', $user->id)->firstOrFail();
        $this->assertSame('android:Pixel Test Phone', $storedToken->name);
        $this->assertTrue($storedToken->expires_at->isFuture());

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles.0', 'Manager');
    }

    public function test_bad_credentials_are_rejected_and_login_requires_device_name(): void
    {
        $user = $this->manager();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Pixel',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectHorseBatteryStaple!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['device_name']);
    }

    public function test_api_rejects_session_authentication_without_mobile_bearer_token(): void
    {
        $this->actingAs($this->manager())->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_customer_list_and_detail_return_only_explicit_safe_fields(): void
    {
        $user = $this->manager();
        $customer = $this->createCustomer('API001');
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('data.0.customer_unique_id', 'API001')
            ->assertJsonPath('data.0.billing.due_amount', 750)
            ->assertJsonMissingPath('data.0.password');

        $this->withToken($token)->getJson('/api/v1/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.customer_unique_id', 'API001')
            ->assertJsonPath('data.pppoe_username', null)
            ->assertJsonMissingPath('data.pppoe_password')
            ->assertJsonMissingPath('data.password');
    }

    public function test_billing_history_and_package_endpoints_are_permission_guarded(): void
    {
        $user = $this->manager();
        $package = PackageList::create(['package' => 'Android Test Plan', 'price' => 1000, 'show_on_site' => true, 'speed' => '20 Mbps']);
        $customer = $this->createCustomer('API002', $package->id);
        CollectionSummary::create([
            'customer_collection_unique_id' => 'API002',
            'collection_date' => now(),
            'collection_amount' => 250,
            'collected_by' => $user->email,
            'payment_status' => 'paid',
            'invoice_no' => 900001,
        ]);
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/customers/'.$customer->id.'/billing')
            ->assertOk()->assertJsonPath('data.due_amount', 750);
        $this->withToken($token)->getJson('/api/v1/customers/'.$customer->id.'/payments')
            ->assertOk()->assertJsonPath('data.0.amount', 250);
        $this->withToken($token)->getJson('/api/v1/packages')
            ->assertOk()->assertJsonPath('data.0.name', 'Android Test Plan');
    }

    public function test_logout_revokes_the_current_device_token(): void
    {
        $token = $this->tokenFor($this->manager());
        $tokenId = (int) Str::before($token, '|');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_reseller_api_is_limited_to_customers_in_its_own_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'android.reseller@example.test',
            'password' => Hash::make('CorrectHorseBatteryStaple!'),
        ]);
        $user->assignRole(Role::findByName('Reseller', 'web'));
        $reseller = Reseller::create(['user_id' => $user->id, 'company' => 'API Test Reseller', 'status' => 'active']);

        $ownCustomer = $this->createCustomer('RSL001', null, 'active', $reseller->id);
        $otherCustomer = $this->createCustomer('OTHER001');
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_unique_id', 'RSL001');

        $this->withToken($token)->getJson('/api/v1/customers/'.$ownCustomer->id)->assertOk();
        $this->withToken($token)->getJson('/api/v1/customers/'.$otherCustomer->id)->assertNotFound();
    }

    public function test_an_authenticated_but_unprivileged_account_cannot_list_customers(): void
    {
        $user = User::factory()->create([
            'email' => 'android.unprivileged@example.test',
            'password' => Hash::make('CorrectHorseBatteryStaple!'),
        ]);
        Role::create(['name' => 'Auditor No Customer Access', 'guard_name' => 'web']);
        $user->assignRole('Auditor No Customer Access');
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/customers')->assertForbidden();
    }
}
