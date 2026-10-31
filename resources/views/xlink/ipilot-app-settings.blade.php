<x-app-layout>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1">iPilot Application Settings</h2>
            <p class="text-muted mb-0">Currency, GIS map defaults, operational preferences, notification policy and content endpoints.</p>
        </div>
        <a href="{{ route('ipilot') }}" class="btn btn-outline-secondary">iPilot Workspace</a>
    </div>

    @if(session('ipilot_settings_saved'))<div class="alert alert-success">{{ session('ipilot_settings_saved') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><strong>Settings were not saved.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <form method="post" action="{{ route('ipilot.app-settings.update') }}">
        @csrf
        <div class="card mb-4">
            <div class="card-header fw-semibold">Billing & Localization</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="currency">Currency</label>
                    <select class="form-select" name="currency" id="currency" required>
                        @foreach($currencies as $currency)
                            <option value="{{ $currency }}" @selected(old('currency', $settings['currency']) === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Updates X-Link's existing site currency setting.</div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">GIS & Map Provider</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Map Provider</label>
                        <select class="form-select" name="map_provider">
                            <option value="woosmap" @selected(old('map_provider',$settings['map_provider'])==='woosmap')>Woosmap</option>
                            <option value="google" @selected(old('map_provider',$settings['map_provider'])==='google')>Google Maps</option>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label" for="map_basemap">Default Map Style</label><select class="form-select" id="map_basemap" name="map_basemap"><option value="auto" @selected(old('map_basemap',$settings['map_basemap'])==='auto')>Follow Admin Theme</option><option value="street" @selected(old('map_basemap',$settings['map_basemap'])==='street')>Street Map</option><option value="satellite" @selected(old('map_basemap',$settings['map_basemap'])==='satellite')>Satellite</option><option value="dark" @selected(old('map_basemap',$settings['map_basemap'])==='dark')>Dark Map</option></select><div class="form-text">Applied to the built-in X-Link Network Map.</div></div>
                    <div class="col-md-3"><label class="form-label">Default Latitude</label><input class="form-control" type="number" step="any" name="map_latitude" value="{{ old('map_latitude',$settings['map_latitude']) }}"></div>
                    <div class="col-md-3"><label class="form-label">Default Longitude</label><input class="form-control" type="number" step="any" name="map_longitude" value="{{ old('map_longitude',$settings['map_longitude']) }}"></div>
                    <div class="col-md-2"><label class="form-label">Default Zoom</label><input class="form-control" type="number" min="1" max="20" name="map_zoom" value="{{ old('map_zoom',$settings['map_zoom']) }}" required></div>
                    <div class="col-md-6">
                        <label class="form-label">Woosmap Public Key</label>
                        <input class="form-control" name="woosmap_public_key" maxlength="500" value="{{ old('woosmap_public_key',$settings['woosmap_public_key']) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Woosmap Private Key</label>
                        <input class="form-control" type="password" name="woosmap_private_key" autocomplete="new-password" placeholder="{{ $settings['woosmap_private_key_configured'] ? 'Already configured; leave blank to keep current key' : 'Enter server-side private key' }}">
                        <div class="form-text">{{ $settings['woosmap_private_key_configured'] ? 'Encrypted key is configured; it is never displayed.' : 'No private key configured.' }}</div>
                        @if($settings['woosmap_private_key_configured'])<label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_woosmap_private_key" value="1"><span class="form-check-label">Remove saved private key</span></label>@endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Google Maps API Key</label>
                        <input class="form-control" type="password" name="google_maps_api_key" autocomplete="new-password" placeholder="{{ $settings['google_maps_key_configured'] ? 'Already configured; leave blank to keep current key' : 'Enter API key' }}">
                        <div class="form-text">{{ $settings['google_maps_key_configured'] ? 'Encrypted key is configured; it is never displayed.' : 'No Google Maps key configured.' }}</div>
                        @if($settings['google_maps_key_configured'])<label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_google_maps_api_key" value="1"><span class="form-check-label">Remove saved Google Maps key</span></label>@endif
                    </div>
                </div>
                <div class="alert alert-warning mt-3 mb-0">The default map style, center and zoom are applied to X-Link’s built-in MapLibre map. Woosmap / Google preferences and encrypted keys are stored for future adapter integration only; they are not loaded into the live map yet.</div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Operational Policies</div>
            <div class="card-body">
                <div class="alert alert-warning">The following policies are stored as configuration preferences for a future workflow rollout. They are not yet connected to all customer, reseller, hotspot or billing actions. Changing a switch here does not itself change customer access or delete/modify router data.</div>
                @php($labels = [
                    'customer_can_buy_internet_time_in_minutes' => 'Allow customers to buy internet time by minute',
                    'customer_can_edit_profile' => 'Allow customers to edit profile',
                    'allow_customer_online_account_recharge' => 'Allow online customer account recharge',
                    'allow_operator_add_advance_balance_to_customer_wallet' => 'Allow operators to add advance balance to customer wallet',
                    'hide_mobile_number_in_all_customers_list' => 'Hide mobile numbers in customer lists',
                    'delete_from_mikrotik_on_customer_delete' => 'Delete MikroTik account when customer is deleted',
                    'auto_delete_inactive_customer_after_6_months' => 'Auto-delete customers inactive for 6 months',
                    'show_complaints_from_operator_and_sub_operator' => 'Show operator and sub-operator complaints',
                    'generate_monthly_bill_for_suspended_customer' => 'Generate monthly bills for suspended customers',
                    'generate_monthly_bill_for_disabled_customer' => 'Generate monthly bills for disabled customers',
                    'allow_reseller_create_customer_without_recharge' => 'Allow reseller customer creation without recharge',
                    'reseller_can_downgrade_customer_package' => 'Allow resellers to downgrade customer packages',
                    'hotspot_user_mobile_must_be_verified' => 'Require mobile verification for hotspot users',
                    'require_hotspot_package_before_access' => 'Require hotspot package before internet access',
                    'grant_internet_access_immediately_after_registration' => 'Grant internet access immediately after registration',
                    'grant_internet_access_before_online_payment' => 'Grant internet access before online payment',
                    'allow_randomized_mac_address' => 'Allow randomized MAC addresses',
                    'auto_mac_bind_for_new_hotspot_account' => 'Auto-bind MAC for new hotspot accounts',
                    'auto_mac_bind_on_recharge_card_payment' => 'Auto-bind MAC after recharge-card payment',
                    'allow_pppoe_customer_hotspot_access' => 'Allow PPPoE customers to use hotspot access',
                ])
                <div class="row g-3">
                    @foreach($policies as $policy)
                        <div class="col-md-6 col-xl-4">
                            <div class="border rounded p-3 h-100">
                                <input type="hidden" name="{{ $policy }}" value="0">
                                <label class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="{{ $policy }}" value="1" @checked((bool)old($policy,$settings[$policy]))>
                                    <span class="form-check-label">{{ $labels[$policy] ?? $policy }}</span>
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Defaults & Notifications</div>
            <div class="card-body row g-3">
                <div class="col-md-3"><label class="form-label">Default Notification Channel</label><select class="form-select" name="default_notification_channel"><option value="sms" @selected(old('default_notification_channel',$settings['default_notification_channel'])==='sms')>SMS</option><option value="whatsapp" @selected(old('default_notification_channel',$settings['default_notification_channel'])==='whatsapp')>WhatsApp</option></select></div>
                <div class="col-md-3"><label class="form-label">Fallback Channel</label><select class="form-select" name="fallback_notification_channel"><option value="sms" @selected(old('fallback_notification_channel',$settings['fallback_notification_channel'])==='sms')>SMS</option><option value="whatsapp" @selected(old('fallback_notification_channel',$settings['fallback_notification_channel'])==='whatsapp')>WhatsApp</option></select></div>
                <div class="col-md-3"><label class="form-label">Daily PPPoE Billing Cut-off</label><input class="form-control" type="time" name="daily_billing_cutoff_time_pppoe" value="{{ old('daily_billing_cutoff_time_pppoe',$settings['daily_billing_cutoff_time_pppoe']) }}" required></div>
                <div class="col-md-3"><label class="form-label">Suspension Hour (0–23)</label><input class="form-control" type="number" min="0" max="23" name="suspension_hour_for_overdue_customer" value="{{ old('suspension_hour_for_overdue_customer',$settings['suspension_hour_for_overdue_customer']) }}" required></div>
                <div class="col-md-6"><label class="form-label">Emergency Balance Limit (BDT)</label><input class="form-control" type="number" step="0.01" min="0" name="emergency_balance_limit_for_internet_time_purchase" value="{{ old('emergency_balance_limit_for_internet_time_purchase',$settings['emergency_balance_limit_for_internet_time_purchase']) }}" required></div>
                <div class="col-md-6"><label class="form-label">Default Identity Verification</label><select class="form-select" name="default_user_identity_verification_method"><option value="mobile_otp" @selected(old('default_user_identity_verification_method',$settings['default_user_identity_verification_method'])==='mobile_otp')>Mobile OTP</option><option value="national_id" @selected(old('default_user_identity_verification_method',$settings['default_user_identity_verification_method'])==='national_id')>National ID</option><option value="manual_review" @selected(old('default_user_identity_verification_method',$settings['default_user_identity_verification_method'])==='manual_review')>Manual Review</option></select></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Content & Media Server Endpoints</div>
            <div class="card-body row g-3">
                <div class="col-md-4"><label class="form-label">FTP / FTPS / SFTP Server</label><input class="form-control" name="ftp_server_address" maxlength="500" value="{{ old('ftp_server_address',$settings['ftp_server_address']) }}" placeholder="ftp://ftp.example.net or 10.0.0.10"></div>
                <div class="col-md-4"><label class="form-label">Live TV Server</label><input class="form-control" name="live_tv_server_address" maxlength="500" value="{{ old('live_tv_server_address',$settings['live_tv_server_address']) }}" placeholder="https://tv.example.net or 10.0.0.20"></div>
                <div class="col-md-4"><label class="form-label">Movie Server</label><input class="form-control" name="movie_server_address" maxlength="500" value="{{ old('movie_server_address',$settings['movie_server_address']) }}" placeholder="https://movies.example.net or 10.0.0.30"></div>
                <div class="col-12"><div class="alert alert-info mb-0">These endpoints are saved as service metadata only. No connection, content request or credential exchange is performed from this settings page.</div></div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save iPilot Settings</button><a class="btn btn-outline-secondary" href="{{ route('ipilot') }}">Cancel</a></div>
    </form>
</div>
</x-app-layout>
