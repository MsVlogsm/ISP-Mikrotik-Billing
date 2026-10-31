<?php

namespace App\Http\Controllers;

use App\Models\MainSiteData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class IpilotApplicationSettingsController extends Controller
{
    private const CURRENCIES = ['BDT', 'USD', 'INR', 'PKR', 'NPR', 'KES', 'EUR', 'GBP', 'AED', 'MYR', 'PHP', 'NGN'];
    private const POLICIES = [
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

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin']), 403);
    }

    public function index()
    {
        $this->authorizeAdmin();

        $settings = [
            'currency' => MainSiteData::getValue('site_currency', 'BDT'),
            'map_provider' => MainSiteData::getValue('ipilot_gis_map_provider', 'woosmap'),
            'map_basemap' => MainSiteData::getValue('ipilot_gis_default_basemap', 'auto'),
            'map_latitude' => MainSiteData::getValue('ipilot_gis_default_latitude', ''),
            'map_longitude' => MainSiteData::getValue('ipilot_gis_default_longitude', ''),
            'map_zoom' => MainSiteData::getValue('ipilot_gis_default_zoom', 13),
            'woosmap_public_key' => MainSiteData::getValue('ipilot_woosmap_public_key', ''),
            'woosmap_private_key_configured' => $this->secretConfigured('ipilot_woosmap_private_key'),
            'google_maps_key_configured' => $this->secretConfigured('ipilot_google_maps_api_key'),
            'default_notification_channel' => MainSiteData::getValue('default_notification_channel', 'sms'),
            'fallback_notification_channel' => MainSiteData::getValue('fallback_notification_channel', 'whatsapp'),
            'daily_billing_cutoff_time_pppoe' => MainSiteData::getValue('daily_billing_cutoff_time_pppoe', '00:00'),
            'emergency_balance_limit_for_internet_time_purchase' => MainSiteData::getValue('emergency_balance_limit_for_internet_time_purchase', 0),
            'suspension_hour_for_overdue_customer' => MainSiteData::getValue('suspension_hour_for_overdue_customer', 0),
            'default_user_identity_verification_method' => MainSiteData::getValue('default_user_identity_verification_method', 'manual_review'),
            'ftp_server_address' => MainSiteData::getValue('ftp_server_address', ''),
            'live_tv_server_address' => MainSiteData::getValue('live_tv_server_address', ''),
            'movie_server_address' => MainSiteData::getValue('movie_server_address', ''),
        ];

        foreach (self::POLICIES as $policy) {
            $settings[$policy] = (bool) MainSiteData::getValue($policy, false);
        }

        return view('xlink.ipilot-app-settings', [
            'settings' => $settings,
            'currencies' => self::CURRENCIES,
            'policies' => self::POLICIES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $rules = [
            'currency' => ['required', 'string', 'in:'.implode(',', self::CURRENCIES)],
            'map_provider' => ['required', 'string', 'in:woosmap,google'],
            'map_basemap' => ['required', 'string', 'in:auto,street,satellite,dark'],
            'map_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'map_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'map_zoom' => ['required', 'integer', 'between:1,20'],
            'woosmap_public_key' => ['nullable', 'string', 'max:500'],
            'woosmap_private_key' => ['nullable', 'string', 'max:2000'],
            'google_maps_api_key' => ['nullable', 'string', 'max:2000'],
            'remove_woosmap_private_key' => ['nullable', 'boolean'],
            'remove_google_maps_api_key' => ['nullable', 'boolean'],
            'default_notification_channel' => ['required', 'in:sms,whatsapp'],
            'fallback_notification_channel' => ['required', 'in:sms,whatsapp'],
            'daily_billing_cutoff_time_pppoe' => ['required', 'date_format:H:i'],
            'emergency_balance_limit_for_internet_time_purchase' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'suspension_hour_for_overdue_customer' => ['required', 'integer', 'between:0,23'],
            'default_user_identity_verification_method' => ['required', 'in:mobile_otp,national_id,manual_review'],
            'ftp_server_address' => ['nullable', 'string', 'max:500'],
            'live_tv_server_address' => ['nullable', 'string', 'max:500'],
            'movie_server_address' => ['nullable', 'string', 'max:500'],
        ];

        foreach (self::POLICIES as $policy) $rules[$policy] = ['required', 'boolean'];

        $data = $request->validate($rules);

        foreach ([
            'ftp_server_address' => ['ftp', 'ftps', 'sftp'],
            'live_tv_server_address' => ['https'],
            'movie_server_address' => ['https'],
        ] as $field => $schemes) {
            if (! $this->validServiceAddress((string) ($data[$field] ?? ''), $schemes)) {
                return back()->withErrors([$field => 'Enter a host/IP or a URL using: '.implode(', ', $schemes).'.'])->withInput();
            }
        }

        MainSiteData::setValue('site_currency', $data['currency']);
        MainSiteData::setValue('ipilot_gis_map_provider', $data['map_provider']);
        MainSiteData::setValue('ipilot_gis_default_basemap', $data['map_basemap']);
        MainSiteData::setValue('ipilot_gis_default_latitude', $data['map_latitude'] ?? '');
        MainSiteData::setValue('ipilot_gis_default_longitude', $data['map_longitude'] ?? '');
        MainSiteData::setValue('ipilot_gis_default_zoom', (int) $data['map_zoom']);
        MainSiteData::setValue('ipilot_woosmap_public_key', trim((string) ($data['woosmap_public_key'] ?? '')));

        $this->saveSecret('ipilot_woosmap_private_key', $data['woosmap_private_key'] ?? '', $request->boolean('remove_woosmap_private_key'));
        $this->saveSecret('ipilot_google_maps_api_key', $data['google_maps_api_key'] ?? '', $request->boolean('remove_google_maps_api_key'));

        foreach ([
            'default_notification_channel',
            'fallback_notification_channel',
            'daily_billing_cutoff_time_pppoe',
            'emergency_balance_limit_for_internet_time_purchase',
            'suspension_hour_for_overdue_customer',
            'default_user_identity_verification_method',
            'ftp_server_address',
            'live_tv_server_address',
            'movie_server_address',
        ] as $key) {
            MainSiteData::setValue($key, $data[$key] ?? '');
        }

        foreach (self::POLICIES as $policy) {
            MainSiteData::setValue($policy, (bool) $data[$policy]);
        }

        return redirect()->route('ipilot.app-settings')
            ->with('ipilot_settings_saved', 'iPilot application settings saved. Sensitive keys are encrypted at rest.');
    }

    private function secretConfigured(string $key): bool
    {
        $value = MainSiteData::getValue($key, '');
        return is_string($value) && $value !== '';
    }

    private function saveSecret(string $key, string $value, bool $remove): void
    {
        if ($remove) {
            MainSiteData::setValue($key, '');
            return;
        }

        $value = trim($value);
        if ($value !== '') MainSiteData::setValue($key, Crypt::encryptString($value));
    }

    private function validServiceAddress(string $value, array $allowedSchemes): bool
    {
        $value = trim($value);
        if ($value === '') return true;
        if (preg_match('/\s/', $value)) return false;

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $value)) {
            $parts = parse_url($value);
            return is_array($parts)
                && in_array(strtolower((string) ($parts['scheme'] ?? '')), $allowedSchemes, true)
                && ! empty($parts['host'])
                && ! isset($parts['user'], $parts['pass']);
        }

        return (bool) preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*)?$/', $value);
    }
}
