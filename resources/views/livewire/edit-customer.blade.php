<div class="p-1">
<x-slot name="header"><h2 class="h4 fw-bold">{{ __('Edit Customer') }}</h2></x-slot>
<form wire:submit.prevent="saveEditForm">
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<div class="mb-3">
<ul class="nav nav-tabs" id="edit-customer-tabs-{{ md5((string)$customerId) }}" role="tablist">
<li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-edit-tab="details-{{ md5((string)$customerId) }}">Customer Details</button></li>
<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-edit-tab="network-{{ md5((string)$customerId) }}"><i class="bi bi-geo-alt me-1"></i>Network Location</button></li>
</ul>
</div>
<div class="edit-customer-tab-content" style="min-height:120px;">
<div class="edit-customer-tab-pane" id="details-{{ md5((string)$customerId) }}">
<div class="row g-3">
<div class="col-12">
<x-mikrotik.section-form :class="'row'">
<x-slot name="title"><span class="text-success fw-bold"><i class="bi bi-person me-2"></i>Customer Information</span></x-slot>
<x-slot name="aside">
<x-mikrotik.form-group label="Customer Name" name="fields.customer.customer_name" type="text" required="true"/>
<x-mikrotik.form-group label="Customer Unique Id" name="fields.customer.customer_unique_id" type="text" readonly="true"/>
<x-mikrotik.form-group label="Email Address" name="fields.customer.email" type="text"/>
<x-mikrotik.form-group label="Identification No" name="fields.customer.identification_no" type="text"/>
<x-mikrotik.form-group label="Mobile Number" name="fields.customer.mobile" type="text"/>
<x-mikrotik.form-group label="Alternate Mobile Number" name="fields.customer.alternative_mobile" type="text"/>
<x-mikrotik.form-group label="Profession Details" name="fields.customer.profession" type="text"/>
</x-slot>
<x-section-border/>
</x-mikrotik.section-form>
</div>
<div class="col-12">
<x-mikrotik.section-form :class="'row'">
<x-slot name="title"><span class="text-success fw-bold"><i class="bi bi-geo-alt me-2"></i>Customer Address</span></x-slot>
<x-slot name="aside">
@foreach($addressFields as $addressField)
<x-mikrotik.form-group label="{{ __($addressField['label']) }}" type="{{ $addressField['input_type'] }}" name="fields.customerAddress.{{ $addressField['label'] }}" :options="json_decode($addressField['dropdown_list'] ?: '[]')" required="{{ $addressField['required'] ? '*' : '' }}"/>
@endforeach
</x-slot>
<x-section-border/>
</x-mikrotik.section-form>
</div>
@if(!auth()->user()->hasRole('Reseller'))
<div class="col-12">
<x-mikrotik.section-form :class="'row'">
<x-slot name="title"><span class="text-success fw-bold"><i class="bi bi-hdd-network me-2"></i>Service Access</span></x-slot>
<x-slot name="aside">
<x-mikrotik.form-group label="Connection Date" name="fields.pppUser.connection_date" type="date"/>
<x-mikrotik.form-group label="Router Name" name="fields.pppUser.router_name" type="dropdown" :options="$routers->pluck('router_name')->toArray()"/>
<x-mikrotik.form-group label="Service Type" name="fields.pppUser.service" type="dropdownKey" :options="['static'=>'Static','pppoe'=>'PPPoE']" required="true"/>
<x-mikrotik.form-group label="Profile" name="fields.pppUser.profile" type="text"/>
<x-mikrotik.form-group label="PPPoE User ID" name="fields.pppUser.username" type="text" required="true"/>
<x-mikrotik.form-group label="PPPoE Password" name="fields.pppUser.password" type="text"/>
<x-mikrotik.form-group label="PPPoE Remote IP / Static IP" name="fields.pppUser.ppp_remote_ip" type="text"/>
<x-mikrotik.form-group label="Interface Name" name="fields.pppUser.interface" type="text"/>
<x-mikrotik.form-group label="IP Address" name="fields.pppUser.ip_address" type="text"/>
<x-mikrotik.form-group label="Subnet / Prefix" name="fields.pppUser.subnet_prefix" type="text"/>
<x-mikrotik.form-group label="Gateway" name="fields.pppUser.gateway" type="text"/>
<x-mikrotik.form-group label="MAC Address" name="fields.pppUser.caller_id" type="text"/>
<x-mikrotik.form-group label="Bandwidth" name="fields.pppUser.bandwidth" type="text"/>
<x-mikrotik.form-group label="Comments / Remarks / Special Note" name="fields.pppUser.comment" type="text"/>
</x-slot>
<div class="row g-3">
<div class="col-md-4"><x-mikrotik.form-group label="Auto Temporary Disable" name="fields.pppUser.auto_disable" type="checkbox"/></div>
<div class="col-md-4"><x-mikrotik.form-group label="Expire Date" name="fields.pppUser.auto_disable_date" type="date"/></div>
<div class="col-md-4"><x-mikrotik.form-group label="Auto Temporary Month" name="fields.pppUser.auto_disable_month" type="text"/></div>
</div>
<x-section-border/>
</x-mikrotik.section-form>
</div>
@endif
<div class="col-12">
<x-mikrotik.section-form :class="'row'">
<x-slot name="title"><span class="text-success fw-bold"><i class="bi bi-cash-stack me-2"></i>Billing Information</span></x-slot>
<x-slot name="aside">
<x-mikrotik.form-group
    label="Package Plan"
    name="fields.pppUser.package_name"
    type="dropdown"
    placeholder="Select Package Plan"
    :options="array_merge(['Free Customer'], $packageLists?->unique()->values()->toArray() ?? [])"
    wChange="loadPackagePrice"
/>
<x-mikrotik.form-group label="Monthly Charge" name="fields.billing.monthly_rent" type="number" required="true"/>
<x-mikrotik.form-group label="Due Amount" name="fields.billing.due_amount" type="number"/>
<x-mikrotik.form-group label="Additional Charge" name="fields.billing.additional_charge" type="number"/>
<x-mikrotik.form-group label="Discount" name="fields.billing.discount" type="number"/>
<x-mikrotik.form-group label="Advance" name="fields.billing.advance" type="number"/>
<x-mikrotik.form-group label="VAT (%)" name="fields.billing.vat" type="number"/>
<x-mikrotik.form-group label="Total Amount" name="fields.billing.total_amount" type="number"/>
</x-slot>
<x-section-border/>
</x-mikrotik.section-form>
</div>
<div class="col-12">
<x-mikrotik.section-form :class="'row'">
<x-slot name="title"><span class="text-success fw-bold"><i class="bi bi-briefcase me-2"></i>Office Information</span></x-slot>
<x-slot name="aside">
<x-mikrotik.form-group label="Billing Type" type="radio" name="fields.official.billing_type" :options="['prepaid'=>'Prepaid','postpaid'=>'Postpaid']"/>
<x-mikrotik.form-group label="Type of Connection" type="radio" name="fields.official.connection_type" :options="['fiber'=>'Fiber','wired'=>'Wired','wireless'=>'Wireless']"/>
<x-mikrotik.form-group label="Type of Connectivity" type="radio" name="fields.official.connectivity_type" :options="['shared'=>'Shared','dedicated'=>'Dedicated']"/>
<x-mikrotik.form-group label="Type of Client" type="dropdownKey" name="fields.official.client_type" :options="['home'=>'Home','commercial'=>'Commercial','Corporate'=>'Corporate','business'=>'Business']"/>
<x-mikrotik.form-group label="Description" type="text" name="fields.official.description"/>
<x-mikrotik.form-group label="Note" type="text" name="fields.official.note"/>
<x-mikrotik.form-group label="Connected By" type="dropdownKey" name="fields.official.connected_by" :options="$userLists->pluck('name','id')->toArray()"/>
<x-mikrotik.form-group label="Security Deposit" type="text" name="fields.official.security_deposit"/>
</x-slot>
<x-section-border/>
</x-mikrotik.section-form>
</div>
</div>
</div>
<div class="edit-customer-tab-pane d-none" id="network-{{ md5((string)$customerId) }}">
<div class="card border-0 shadow-sm">
<div class="card-header bg-white"><strong><i class="bi bi-geo-alt me-2"></i>Network Location</strong><small class="d-block text-muted">Set the customer's exact network location. Click on the map or enter coordinates manually.</small></div>
<div class="card-body">
<div class="row g-3">
<div class="col-lg-4">
<div class="mb-3"><label class="form-label">Latitude</label><input id="network-location-lat" type="text" class="form-control" wire:model.live="fields.customer.latitude" placeholder="e.g. 23.8103"></div>
<div class="mb-3"><label class="form-label">Longitude</label><input id="network-location-lng" type="text" class="form-control" wire:model.live="fields.customer.longitude" placeholder="e.g. 90.4125"></div>
<div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i>Map changes update Latitude/Longitude automatically. Use <strong>Save Changes</strong> below to store the location.</div>
</div>
<div class="col-lg-8"><div id="customer-network-map" wire:ignore style="height:420px;border:1px solid #dee2e6;border-radius:10px;overflow:hidden;background:#f8f9fa;"></div></div>
</div>
</div>
</div>
</div>
</div>
<div class="col-12 d-flex justify-content-end gap-2 mt-3">
<button type="button" wire:click="$dispatch('close-edit-customer')" class="btn btn-outline-secondary">Cancel</button>
<button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save Changes</button>
</div>
</form>
@script
<script>
(function () {
    const root = document.currentScript?.closest('[wire\\:id]') || document;
    const tabButtons = root.querySelectorAll('[data-edit-tab]');
    const tabPanes = root.querySelectorAll('.edit-customer-tab-pane');
    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            tabButtons.forEach((b) => b.classList.remove('active'));
            tabPanes.forEach((p) => p.classList.add('d-none'));
            button.classList.add('active');
            const target = root.querySelector('#' + CSS.escape(button.dataset.editTab));
            if (target) target.classList.remove('d-none');
            if (button.dataset.editTab?.startsWith('network-')) {
                setTimeout(() => window.__xlinkCustomerMap?.invalidateSize(), 80);
            }
        });
    });

    const mapEl = root.querySelector('#customer-network-map');
    const latEl = root.querySelector('#network-location-lat');
    const lngEl = root.querySelector('#network-location-lng');
    if (!mapEl || !latEl || !lngEl || typeof L === 'undefined' || mapEl.dataset.initialized === '1') return;
    mapEl.dataset.initialized = '1';
    const initialLat = Number.parseFloat(latEl.value);
    const initialLng = Number.parseFloat(lngEl.value);
    const hasCoords = Number.isFinite(initialLat) && Number.isFinite(initialLng);
    const center = hasCoords ? [initialLat, initialLng] : [23.8103, 90.4125];
    const map = L.map(mapEl).setView(center, hasCoords ? 16 : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
    let marker = hasCoords ? L.marker(center).addTo(map) : null;
    window.__xlinkCustomerMap = map;

    const syncMarker = () => {
        const lat = Number.parseFloat(latEl.value);
        const lng = Number.parseFloat(lngEl.value);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const point = [lat, lng];
        if (!marker) marker = L.marker(point).addTo(map);
        else marker.setLatLng(point);
        map.setView(point, Math.max(map.getZoom(), 16));
    };
    map.on('click', (event) => {
        latEl.value = event.latlng.lat.toFixed(7);
        lngEl.value = event.latlng.lng.toFixed(7);
        latEl.dispatchEvent(new Event('input', { bubbles: true }));
        lngEl.dispatchEvent(new Event('input', { bubbles: true }));
        syncMarker();
    });
    latEl.addEventListener('input', syncMarker);
    lngEl.addEventListener('input', syncMarker);
})();
</script>
@endscript
</div>
