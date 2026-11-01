<x-app-layout>
    <x-slot name="header">
        <div class="d-flex justify-content-between align-items-center">
            <div><span class="text-muted small">CUSTOMER PROFILE</span><h2 class="h4 mb-0 fw-bold">Customer / User Information</h2></div>
            <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Customer List</a>
        </div>
    </x-slot>
    @php
        $status = strtolower((string) $customer->status);
        $statusClass = match ($status) { 'active'=>'bg-success','pending'=>'bg-warning text-dark','free'=>'bg-info text-dark',default=>'bg-danger' };
        $billing=$customer->billing; $ppp=$customer->pppUser;
        $address=$customer->customerAddress->map(fn($a)=>implode(' ',array_filter([$a->input_type_dropdown,$a->input_type_test,$a->input_type_textarea])))->filter()->implode(', ');
        $displayCustomerId = $customer->displayCustomerUniqueId();
        $editId=encrypt($customer->customer_unique_id);
    @endphp
    <div class="container-fluid pb-4">
        <div class="customer-hero mb-3">
            <div><div class="small opacity-75">#{{ $displayCustomerId }}</div>
                <h1 class="h3 fw-bold mb-1">{{ $customer->customer_name }}</h1>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-light text-dark">{{ $status }}</span>
                    @if($remoteIp)<span class="badge bg-dark">IP {{ $remoteIp }}</span>@endif
                    @if($ppp?->username)<span class="badge bg-dark">PPPoE {{ $ppp->username }}</span>@endif
                </div>
            </div>
            <div class="text-end"><span class="badge {{ $statusClass }} px-3 py-2">{{ ucfirst($status) }}</span>
                @if($billing?->auto_disable_date)<div class="small mt-2 opacity-75">Expiry {{ \Carbon\Carbon::parse($billing->auto_disable_date)->format('d M Y H:i') }}</div>@endif
            </div>
        </div>
        <div class="actions-card mb-3"><div class="section-label">Actions</div><div class="d-flex flex-wrap gap-2">
            @unless($customer->trashed())
                <a class="action-btn" href="{{ route('customers.edit',$editId) }}"><i class="bi bi-pencil-square"></i>Edit</a>
                <a class="action-btn" href="{{ route('ai-engineer') }}?customer={{ urlencode($customer->customer_unique_id) }}"><i class="bi bi-cpu"></i>AI Diagnose</a>
            @endunless
            <button class="action-btn" type="button" onclick="window.print()"><i class="bi bi-printer-fill"></i>Print Profile</button>
            <button class="action-btn" type="button" onclick="navigator.clipboard?.writeText(@js($displayCustomerId))"><i class="bi bi-copy"></i>Copy ID</button>
        </div></div>
        <div class="row g-3">
            <div class="col-xl-7"><div class="info-card h-100"><div class="card-title">Customer Information</div><div class="info-grid">
                <div><span>Customer ID</span><strong>#{{ $displayCustomerId }}</strong></div>
                <div><span>Status</span><strong><span class="badge {{ $statusClass }}">{{ ucfirst($status) }}</span></strong></div>
                <div><span>Customer Name</span><strong>{{ $customer->customer_name ?: '—' }}</strong></div>
                <div><span>Mobile Number</span><strong>{{ $customer->mobile ?: '—' }}</strong></div>
                <div><span>Email</span><strong>{{ $customer->email ?: '—' }}</strong></div>
                <div><span>Alternative Mobile</span><strong>{{ $customer->alternative_mobile ?: '—' }}</strong></div>
                <div><span>Identification No</span><strong>{{ $customer->identification_no ?: '—' }}</strong></div>
                <div><span>Connection Date</span><strong>{{ $customer->connection_date ? \Carbon\Carbon::parse($customer->connection_date)->format('d M Y') : '—' }}</strong></div>
                <div class="full"><span>Address</span><strong>{{ $address ?: ($customer->address ?: '—') }}</strong></div>
            </div></div></div>
            <div class="col-xl-5"><div class="info-card h-100"><div class="card-title">Service / PPPoE</div><div class="info-grid">
                <div><span>Username</span><strong>{{ $ppp?->username ?: '—' }}</strong></div>
                <div><span>Password</span><strong>••••••••</strong></div>
                <div><span>PPP Status</span><strong>{{ $ppp?->status ? ucfirst($ppp->status) : '—' }}</strong></div>
                <div><span>Router</span><strong>{{ $ppp?->router_name ?: '—' }}</strong></div>
                <div><span>Remote IP</span><strong>{{ $remoteIp ?: '—' }}</strong></div>
                <div><span>Bandwidth</span><strong>{{ $ppp?->bandwidth ?: '—' }}</strong></div>
                <div><span>Package</span><strong>{{ $customer->package?->package ?: '—' }}</strong></div>
                <div><span>Profile</span><strong>{{ $ppp?->profile ?: '—' }}</strong></div>
            </div></div></div>
            <div class="col-xl-7"><div class="info-card h-100"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div class="card-title mb-0">Billing Information</div><a class="btn btn-sm btn-outline-primary" href="{{ route('customer.paid-ledger', encrypt($customer->customer_unique_id)) }}"><i class="bi bi-journal-check me-1"></i>Paid Ledger</a></div><div class="info-grid">
                <div><span>Package Plan</span><strong>{{ $customer->package?->package ?: '—' }}</strong></div>
                <div><span>Monthly Bill</span><strong>{{ number_format((float)($billing?->monthly_rent??0),2) }} ৳</strong></div>
                <div><span>Previous Due</span><strong>{{ number_format((float)($billing?->previous_due??0),2) }} ৳</strong></div>
                <div><span>Discount</span><strong>{{ number_format((float)($billing?->discount??0),2) }} ৳</strong></div>
                <div><span>Advance</span><strong>{{ number_format((float)($billing?->advance??0),2) }} ৳</strong></div>
                <div><span>Additional Charge</span><strong>{{ number_format((float)($billing?->additional_charge??0),2) }} ৳</strong></div>
                <div><span>Total Bill</span><strong>{{ number_format((float)($billing?->total_amount??0),2) }} ৳</strong></div>
                <div><span>Total Due</span><strong class="{{ (float)($billing?->due_amount??0)>0?'text-danger':'text-success' }}">{{ number_format((float)($billing?->due_amount??0),2) }} ৳</strong></div>
                <div><span>Expiry Date</span><strong>{{ $billing?->auto_disable_date ? \Carbon\Carbon::parse($billing->auto_disable_date)->format('d M Y H:i') : '—' }}</strong></div>
                <div><span>Auto Disable</span><strong>{{ !empty($billing?->auto_disable)?'Enabled':'Disabled' }}</strong></div>
            </div></div></div>
            <div class="col-xl-5"><div class="info-card h-100"><div class="card-title"><i class="bi bi-diagram-3 me-2"></i>ONU / OLT Information</div>
                @if($mapping)
                    <div class="info-grid">
                        <div><span>OLT Name</span><strong>{{ $mapping->olt?->name ?: $mapping->olt?->hostname ?: '—' }}</strong></div>
                        <div><span>PON Port</span><strong>{{ $mapping->pon_port ?: '—' }}</strong></div>
                        <div><span>ONU ID</span><strong>{{ $mapping->onu_id ?: '—' }}</strong></div>
                        <div><span>ONU Serial</span><strong>{{ $mapping->onu_serial ?: '—' }}</strong></div>
                        <div><span>ONU MAC</span><strong>{{ $mapping->onu_mac ?: '—' }}</strong></div>
                        <div><span>ONU Type</span><strong>{{ $mapping->onu_type ?: '—' }}</strong></div>
                        <div><span>RX Power</span><strong>{{ $mapping->rx_power!==null?$mapping->rx_power.' dBm':'—' }}</strong></div>
                        <div><span>TX Power</span><strong>{{ $mapping->tx_power!==null?$mapping->tx_power.' dBm':'—' }}</strong></div>
                        <div><span>ONU IP</span><strong>{{ $mapping->onu_ip ?: '—' }}</strong></div>
                        <div><span>Last Seen</span><strong>{{ $mapping->last_seen_at?->format('d M Y H:i:s') ?: '—' }}</strong></div>
                    </div>
                @else <div class="empty-state"><i class="bi bi-router"></i>No OLT/ONU mapping is linked to this customer yet.</div> @endif
            </div></div>
            <div class="col-xl-7"><div class="info-card h-100"><div class="card-title">Account / Contact</div><div class="info-grid">
                <div><span>Owner</span><strong>{{ $customer->reseller?->company ?: 'X-Link LTD' }}</strong></div>
                <div><span>Contact Person</span><strong>{{ $customer->contact_person ?: '—' }}</strong></div>
                <div><span>Parents Name</span><strong>{{ $customer->parents_name ?: '—' }}</strong></div>
                <div><span>Spouse Name</span><strong>{{ $customer->spouse_name ?: '—' }}</strong></div>
                <div><span>Profession</span><strong>{{ $customer->profession ?: '—' }}</strong></div>
                <div><span>Created</span><strong>{{ $customer->created_at?->format('d M Y H:i') ?: '—' }}</strong></div>
            </div></div></div>
            <div class="col-12"><div class="info-card"><div class="card-title"><i class="bi bi-box-seam me-2"></i>Customer Inventory</div>
                <div class="inventory-grid">
                    <div><span>Inventory Type</span><strong>Internet Service</strong></div>
                    <div><span>Service Status</span><strong>{{ ucfirst($status) }}</strong></div>
                    <div><span>Connection</span><strong>{{ $ppp?->service ? strtoupper($ppp->service) : ($ppp?->username ? 'PPPoE' : '—') }}</strong></div>
                    <div><span>PPPoE User ID</span><strong>{{ $ppp?->username ?: '—' }}</strong></div>
                    <div><span>Router / MikroTik</span><strong>{{ $ppp?->router_name ?: '—' }}</strong></div>
                    <div><span>Service Profile</span><strong>{{ $ppp?->profile ?: '—' }}</strong></div>
                    <div><span>IP Address</span><strong>{{ $remoteIp ?: '—' }}</strong></div>
                    <div><span>Bandwidth</span><strong>{{ $ppp?->bandwidth ?: '—' }}</strong></div>
                    <div><span>Package</span><strong>{{ $customer->package?->package ?: '—' }}</strong></div>
                    <div><span>Monthly Bill</span><strong>{{ number_format((float)($billing?->monthly_rent ?? 0),2) }} ৳</strong></div>
<div><span>ONU MAC</span><strong>{{ $mapping?->onu_mac ?: '—' }}</strong></div>
                    <div><span>OLT / PON</span><strong>{{ $mapping ? (($mapping->olt?->name ?: $mapping->olt?->hostname ?: 'OLT').' / '.($mapping->pon_port ?: '—')) : '—' }}</strong></div>
                    <div><span>ONU Status</span><strong>{{ $mapping?->status ? ucfirst($mapping->status) : 'Not Mapped' }}</strong></div>
                </div>
            </div></div>
            <div class="col-xl-5"><div class="info-card h-100"><div class="card-title"><i class="bi bi-clock-history me-2"></i>Activity Log</div>
                @forelse($activities as $activity)
                    <div class="activity-item"><div class="activity-dot"></div><div><strong>{{ $activity->description }}</strong>
                        <div class="small text-muted">{{ $activity->causer?->name ?: 'System' }} · {{ $activity->created_at?->diffForHumans() }}</div>
                    </div></div>
                @empty <div class="empty-state py-4"><i class="bi bi-clock-history"></i>No customer-specific activity is recorded yet.</div> @endforelse
            </div></div>
        </div>
    </div>
    @push('styles')
    <style>
        .customer-hero{background:linear-gradient(110deg,#b90f2f,#ed1b3b);color:#fff;border-radius:14px;padding:22px 24px;display:flex;justify-content:space-between;gap:20px;box-shadow:0 8px 22px rgba(0,0,0,.12)}
        .actions-card,.info-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 3px 12px rgba(0,0,0,.05)}
        .actions-card{padding:14px 16px}.section-label,.card-title{font-weight:700;color:#253047;margin-bottom:12px}
        .action-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid #cfe0f0;background:#fff;color:#243447;border-radius:8px;padding:8px 12px;text-decoration:none;font-size:.88rem;cursor:pointer}
        .action-btn:hover{background:#f4f8fb}.info-card{padding:16px}.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));border-top:1px solid #edf0f3}
        .info-grid>div{padding:11px 8px;border-bottom:1px solid #edf0f3;min-width:0}.info-grid>div:nth-child(odd){border-right:1px solid #edf0f3}
        .info-grid>div.full{grid-column:1/-1;border-right:0}.info-grid span{display:block;color:#778195;font-size:.76rem;margin-bottom:3px}.info-grid strong{display:block;color:#273449;font-size:.9rem;word-break:break-word}
        .inventory-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-top:1px solid #edf0f3}
        .inventory-grid>div{padding:12px 10px;border-bottom:1px solid #edf0f3;border-right:1px solid #edf0f3;min-width:0}
        .inventory-grid span{display:block;color:#778195;font-size:.76rem;margin-bottom:3px}.inventory-grid strong{display:block;color:#273449;font-size:.9rem;word-break:break-word}
        .empty-state{text-align:center;color:#7b8494;padding:28px 10px}.empty-state i{font-size:2rem;display:block;margin-bottom:8px}
        .activity-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #edf0f3}.activity-item:last-child{border-bottom:0}.activity-dot{width:9px;height:9px;border-radius:50%;background:#198754;margin-top:6px;flex:0 0 auto}
        @media(max-width:991px){.inventory-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:767px){.inventory-grid{grid-template-columns:1fr}.customer-hero{display:block}.customer-hero .text-end{text-align:left!important;margin-top:12px}.info-grid{grid-template-columns:1fr}.info-grid>div:nth-child(odd){border-right:0}}
        @media print{.actions-card,.btn,.navbar,.sidebar{display:none!important}.customer-hero{box-shadow:none;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    </style>
    @endpush
</x-app-layout>
