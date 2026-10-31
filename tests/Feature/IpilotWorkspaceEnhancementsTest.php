<?php

namespace Tests\Feature;

use App\Models\MainSiteData;
use App\Models\User;
use App\Models\ZabbixServer;
use App\Services\PublicWebhookDestinationResolver;
use App\Services\ZabbixMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IpilotWorkspaceEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($this->admin);
    }

    public function test_application_settings_render_and_persist_sensitive_keys_encrypted(): void
    {
        $this->get(route('ipilot.app-settings'))
            ->assertOk()
            ->assertSee('iPilot Application Settings')
            ->assertSee('Operational Policies');

        $payload = [
            'currency' => 'USD',
            'map_provider' => 'google',
            'map_basemap' => 'satellite',
            'map_latitude' => '23.8103',
            'map_longitude' => '90.4125',
            'map_zoom' => 12,
            'woosmap_public_key' => 'public-demo-key',
            'woosmap_private_key' => 'private-demo-key',
            'google_maps_api_key' => 'google-demo-key',
            'default_notification_channel' => 'sms',
            'fallback_notification_channel' => 'whatsapp',
            'daily_billing_cutoff_time_pppoe' => '00:30',
            'emergency_balance_limit_for_internet_time_purchase' => '500',
            'suspension_hour_for_overdue_customer' => 2,
            'default_user_identity_verification_method' => 'mobile_otp',
            'ftp_server_address' => 'ftp://ftp.example.test',
            'live_tv_server_address' => 'https://tv.example.test',
            'movie_server_address' => 'https://movies.example.test',
        ];

        $policies = [
            'customer_can_buy_internet_time_in_minutes',
            'customer_can_edit_profile',
            'allow_customer_online_account_recharge',
            'allow_operator_add_advance_balance_to_customer_wallet',
            'hide_mobile_number_in_all_customers_list',
            'delete_from_mikrotik_on_customer_delete',
            'auto_delete_inactive_customer_after_6_months',
            'show_complaints_from_operator_and_sub_operator',
            'generate_monthly_bill_for_suspended_customer',
            'generate_monthly_bill_for_disabled_customer',
            'allow_reseller_create_customer_without_recharge',
            'reseller_can_downgrade_customer_package',
            'hotspot_user_mobile_must_be_verified',
            'require_hotspot_package_before_access',
            'grant_internet_access_immediately_after_registration',
            'grant_internet_access_before_online_payment',
            'allow_randomized_mac_address',
            'auto_mac_bind_for_new_hotspot_account',
            'auto_mac_bind_on_recharge_card_payment',
            'allow_pppoe_customer_hotspot_access',
        ];
        foreach ($policies as $policy) $payload[$policy] = false;
        $payload['customer_can_edit_profile'] = true;
        $payload['allow_customer_online_account_recharge'] = true;

        $this->post(route('ipilot.app-settings.update'), $payload)
            ->assertRedirect(route('ipilot.app-settings'))
            ->assertSessionHas('ipilot_settings_saved');

        $this->assertSame('USD', MainSiteData::getValue('site_currency'));
        $this->assertSame('google', MainSiteData::getValue('ipilot_gis_map_provider'));
        $this->assertSame('satellite', MainSiteData::getValue('ipilot_gis_default_basemap'));
        $this->assertTrue((bool) MainSiteData::getValue('customer_can_edit_profile'));
        $this->assertFalse((bool) MainSiteData::getValue('delete_from_mikrotik_on_customer_delete'));

        $privateCipher = MainSiteData::getValue('ipilot_woosmap_private_key');
        $googleCipher = MainSiteData::getValue('ipilot_google_maps_api_key');
        $this->assertNotSame('private-demo-key', $privateCipher);
        $this->assertNotSame('google-demo-key', $googleCipher);
        $this->assertSame('private-demo-key', Crypt::decryptString($privateCipher));
        $this->assertSame('google-demo-key', Crypt::decryptString($googleCipher));

        $this->get(route('ipilot.app-settings'))
            ->assertOk()
            ->assertDontSee('private-demo-key')
            ->assertDontSee('google-demo-key');
    }

    public function test_application_settings_reject_invalid_map_coordinates_and_service_schemes(): void
    {
        $payload = [
            'currency' => 'BDT',
            'map_provider' => 'woosmap',
            'map_basemap' => 'street',
            'map_latitude' => '120',
            'map_longitude' => '90',
            'map_zoom' => 13,
            'woosmap_public_key' => '',
            'default_notification_channel' => 'sms',
            'fallback_notification_channel' => 'sms',
            'daily_billing_cutoff_time_pppoe' => '00:00',
            'emergency_balance_limit_for_internet_time_purchase' => 0,
            'suspension_hour_for_overdue_customer' => 0,
            'default_user_identity_verification_method' => 'manual_review',
            'ftp_server_address' => 'https://not-ftp.example.test',
            'live_tv_server_address' => 'http://insecure.example.test',
            'movie_server_address' => '',
        ];
        foreach ([
            'customer_can_buy_internet_time_in_minutes',
            'customer_can_edit_profile',
            'allow_customer_online_account_recharge',
            'allow_operator_add_advance_balance_to_customer_wallet',
            'hide_mobile_number_in_all_customers_list',
            'delete_from_mikrotik_on_customer_delete',
            'auto_delete_inactive_customer_after_6_months',
            'show_complaints_from_operator_and_sub_operator',
            'generate_monthly_bill_for_suspended_customer',
            'generate_monthly_bill_for_disabled_customer',
            'allow_reseller_create_customer_without_recharge',
            'reseller_can_downgrade_customer_package',
            'hotspot_user_mobile_must_be_verified',
            'require_hotspot_package_before_access',
            'grant_internet_access_immediately_after_registration',
            'grant_internet_access_before_online_payment',
            'allow_randomized_mac_address',
            'auto_mac_bind_for_new_hotspot_account',
            'auto_mac_bind_on_recharge_card_payment',
            'allow_pppoe_customer_hotspot_access',
        ] as $policy) $payload[$policy] = false;

        $this->from(route('ipilot.app-settings'))
            ->post(route('ipilot.app-settings.update'), $payload)
            ->assertSessionHasErrors(['map_latitude']);

        $payload['map_latitude'] = '23.5';
        $payload['ftp_server_address'] = 'ftp://ftp.example.test';
        $this->from(route('ipilot.app-settings'))
            ->post(route('ipilot.app-settings.update'), $payload)
            ->assertSessionHasErrors(['live_tv_server_address']);

        $this->assertSame('BDT', MainSiteData::getValue('site_currency', 'BDT'));
    }

    public function test_gis_workspace_pages_render_without_external_map_assets(): void
    {
        $this->get(route('ipilot.gis.outside-plant'))
            ->assertOk()->assertSeeText('Network & Outside Plant');
        $this->get(route('ipilot.gis.operations'))
            ->assertOk()->assertSee('Network Operations');
        $this->get(route('ipilot.gis.planning'))
            ->assertOk()->assertSeeText('Planning & Serviceability');
    }

    public function test_zabbix_sync_counts_unresolved_problems_and_updates_last_check(): void
    {
        $server = new ZabbixServer([
            'created_by' => $this->admin->id,
            'name' => 'Test Zabbix',
            'api_url' => 'https://zabbix.example.test',
            'is_active' => true,
        ]);
        $server->api_token = 'read-only-test-token';
        $server->save();

        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'result' => '7.0.1', 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'result' => '17', 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'result' => '3', 'id' => 1]);

        $result = app(ZabbixMonitoringService::class)->syncServer($server);

        $this->assertSame('7.0.1', $result['version']);
        $this->assertSame(17, $result['hosts']);
        $this->assertSame(3, $result['active_problems']);
        $this->assertNotNull($server->fresh()->last_checked_at);
        $this->assertNull($server->fresh()->last_error);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api_jsonrpc.php')
            && ($request['method'] ?? null) === 'problem.get'
            && ! array_key_exists('recent', $request['params'] ?? []));
    }

    public function test_webhook_destination_resolver_blocks_private_reserved_and_local_addresses(): void
    {
        $resolver = app(PublicWebhookDestinationResolver::class);
        foreach ([
            'https://127.0.0.1/hook',
            'https://10.10.0.5/hook',
            'https://192.168.1.10/hook',
            'https://169.254.169.254/latest/meta-data',
            'https://[::1]/hook',
            'https://[fc00::1]/hook',
            'https://[fe80::1]/hook',
        ] as $url) {
            try {
                $resolver->resolve($url);
                $this->fail('Expected private destination to be rejected: '.$url);
            } catch (\RuntimeException $exception) {
                $message = strtolower($exception->getMessage());
                $this->assertTrue(str_contains($message, 'https') || str_contains($message, 'private') || str_contains($message, 'reserved'));
            }
        }
    }

    public function test_webhook_destination_resolver_returns_a_pinned_public_ip_target(): void
    {
        $target = app(PublicWebhookDestinationResolver::class)->resolve('https://1.1.1.1:8443/webhook');
        $this->assertSame('1.1.1.1', $target['host']);
        $this->assertSame(8443, $target['port']);
        $this->assertSame('1.1.1.1', $target['ip']);
        $this->assertSame('1.1.1.1:8443:1.1.1.1', $target['curl_resolve']);
    }

    public function test_terminal_vlans_command_only_returns_read_only_limit_information(): void
    {
        $this->postJson(route('ipilot.terminal.chat'), ['command' => '/vlan'])
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('reply', 'VLAN read-only note: this iPilot release does not yet have a verified VLAN inventory data source. No MikroTik CLI/API command is executed from this terminal. Use the existing MikroTik Interface / VLAN setup screen for separately reviewed configuration.');
    }
}
