<x-app-layout>
    <x-slot name="header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="customer-profile-eyebrow">CUSTOMER MANAGEMENT</div>
                <h2 class="h4 mb-0 fw-bold">Customer Profile</h2>
            </div>
            <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary btn-sm customer-back-link">
                <i class="bi bi-arrow-left me-1"></i>Customer List
            </a>
        </div>
    </x-slot>

    @php
        $status = strtolower((string) $customer->status);
        $statusLabel = ucfirst($status ?: 'unknown');
        $statusClass = match ($status) {
            'active' => 'success',
            'pending' => 'warning',
            'free' => 'info',
            'inactive' => 'secondary',
            'disable' => 'danger',
            default => 'secondary',
        };

        $billing = $customer->billing;
        $ppp = $customer->pppUser;
        $displayCustomerId = $customer->displayCustomerUniqueId();
        $editId = encrypt($customer->customer_unique_id);
        $customerName = $customer->customer_name ?: 'Unnamed customer';
        $ownerName = $customer->reseller?->company ?: 'X-Link LTD';

        $address = $customer->customerAddress
            ->map(fn ($item) => trim(implode(', ', array_filter([
                $item->input_type_text,
                $item->input_type_dropdown,
                $item->input_type_textarea,
            ]))))
            ->filter()
            ->implode(' · ');
        $address = $address ?: ($customer->address ?: '—');

        $expiryDate = $billing?->auto_disable_date
            ? \Carbon\Carbon::parse($billing->auto_disable_date)
            : null;
        $isExpired = $expiryDate
            && $expiryDate->isPast()
            && ! in_array($status, ['disable', 'inactive'], true);
        $daysToExpiry = $expiryDate
            ? (int) now()->startOfDay()->diffInDays($expiryDate->copy()->startOfDay(), false)
            : null;
        $expiryLabel = $expiryDate ? $expiryDate->format('d M Y, h:i A') : 'Not set';

        $monthlyRent = (float) ($billing?->monthly_rent ?? 0);
        $paidAmount = (float) ($billing?->paid_amount ?? 0);
        $dueAmount = (float) ($billing?->due_amount ?? 0);
        $totalAmount = (float) ($billing?->total_amount ?? 0);
        $packageName = $customer->package?->package ?: $ppp?->profile ?: '—';
        $connectionType = $ppp?->service ? strtoupper((string) $ppp->service) : ($ppp?->username ? 'PPPoE' : '—');
        $rxPower = $mapping?->rx_power;
        $rxClass = $rxPower === null ? 'muted' : ((float) $rxPower >= -20 ? 'good' : ((float) $rxPower >= -27 ? 'warn' : 'bad'));
        $initials = collect(preg_split('/\s+/u', trim($customerName)))
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_substr($part, 0, 1))
            ->implode('');
    @endphp

    <div class="container-fluid customer-profile-page pb-4">
        {{-- Profile identity and current service state --}}
        <section class="customer-profile-hero mb-3">
            <div class="customer-profile-identity">
                <div class="customer-profile-avatar">
                    @if($customer->photo_url)
                        <img src="{{ asset('storage/'.$customer->photo_url) }}" alt="{{ $customerName }}">
                    @else
                        <span>{{ $initials ?: 'C' }}</span>
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="customer-profile-id">CUSTOMER #{{ $displayCustomerId }}</div>
                    <h1>{{ $customerName }}</h1>
                    <div class="customer-profile-subline">
                        @if($ppp?->username)
                            <span><i class="bi bi-person-badge"></i>{{ $ppp->username }}</span>
                        @endif
                        @if($remoteIp)
                            <span><i class="bi bi-globe2"></i>{{ $remoteIp }}</span>
                        @endif
                        @if($customer->official?->distribution_location)
                            <span><i class="bi bi-geo-alt"></i>{{ $customer->official->distribution_location }}</span>
                        @endif
                    </div>
                    <div class="customer-profile-badges mt-2">
                        <span class="profile-status profile-status--{{ $statusClass }}">
                            <span></span>{{ $statusLabel }}
                        </span>
                        @if($ppp?->status)
                            <span class="profile-chip"><i class="bi bi-router"></i>PPP {{ ucfirst((string) $ppp->status) }}</span>
                        @endif
                        @if($customer->trashed())
                            <span class="profile-chip profile-chip--muted">Archived customer</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="customer-profile-expiry {{ $isExpired ? 'is-expired' : '' }}">
                <span>{{ $isExpired ? 'Expiry passed' : 'Service expiry' }}</span>
                <strong>{{ $expiryDate ? $expiryDate->format('d M Y') : '—' }}</strong>
                <small>
                    @if($expiryDate && $isExpired)
                        {{ abs((int) $daysToExpiry) }} day(s) overdue
                    @elseif($expiryDate && $daysToExpiry !== null)
                        {{ $daysToExpiry }} day(s) remaining
                    @else
                        Expiry date not configured
                    @endif
                </small>
            </div>
        </section>

        {{-- Fast customer snapshot --}}
        <section class="customer-snapshot-grid mb-3" aria-label="Customer summary">
            <div class="customer-snapshot-card">
                <span class="snapshot-icon snapshot-icon--blue"><i class="bi bi-wallet2"></i></span>
                <div><small>Monthly Bill</small><strong>{{ number_format($monthlyRent, 2) }} ৳</strong><em>{{ $packageName }}</em></div>
            </div>
            <div class="customer-snapshot-card">
                <span class="snapshot-icon snapshot-icon--{{ $dueAmount > 0 ? 'red' : 'green' }}"><i class="bi bi-cash-stack"></i></span>
                <div><small>Outstanding Balance</small><strong class="{{ $dueAmount > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($dueAmount, 2) }} ৳</strong><em>{{ number_format($paidAmount, 2) }} ৳ paid</em></div>
            </div>
            <div class="customer-snapshot-card">
                <span class="snapshot-icon snapshot-icon--{{ $rxClass }}"><i class="bi bi-soundwave"></i></span>
                <div><small>ONU RX Power</small><strong>{{ $rxPower !== null ? number_format((float) $rxPower, 2).' dBm' : '—' }}</strong><em>{{ $mapping?->olt?->name ?: 'ONU not mapped' }}</em></div>
            </div>
            <div class="customer-snapshot-card">
                <span class="snapshot-icon snapshot-icon--violet"><i class="bi bi-calendar-check"></i></span>
                <div><small>Connection Since</small><strong>{{ $customer->connection_date ? \Carbon\Carbon::parse($customer->connection_date)->format('d M Y') : '—' }}</strong><em>{{ $billing?->billing_type ? ucfirst((string) $billing->billing_type).' billing' : 'Billing cycle' }}</em></div>
            </div>
        </section>

        {{-- Keep the actions visible and grouped like the reference profile page. --}}
        <section class="customer-profile-actions mb-3">
            <div class="customer-profile-actions-label"><i class="bi bi-lightning-charge-fill me-1"></i>Customer Actions</div>
            <div class="customer-profile-actions-list">
                @unless($customer->trashed())
                    <a class="profile-action profile-action--primary" href="{{ route('customers.edit', $editId) }}"><i class="bi bi-pencil-square"></i>Edit Customer</a>
                    <a class="profile-action" href="{{ route('ai-engineer') }}?customer={{ urlencode($customer->customer_unique_id) }}"><i class="bi bi-cpu"></i>AI Diagnose</a>
                @endunless
                <a class="profile-action" href="{{ route('customer.paid-ledger', encrypt($customer->customer_unique_id)) }}"><i class="bi bi-journal-check"></i>Billing Ledger</a>
                <button class="profile-action" type="button" onclick="window.print()"><i class="bi bi-printer"></i>Print Profile</button>
                <button class="profile-action" type="button" onclick="navigator.clipboard?.writeText(@js($displayCustomerId))"><i class="bi bi-copy"></i>Copy Customer ID</button>
            </div>
        </section>

        <nav class="customer-profile-nav mb-3" aria-label="Customer profile sections">
            <a href="#customer-overview"><i class="bi bi-person-lines-fill"></i>Overview</a>
            <a href="#customer-billing"><i class="bi bi-receipt"></i>Billing</a>
            <a href="#customer-connection"><i class="bi bi-router"></i>Connection</a>
            <a href="#customer-onu"><i class="bi bi-diagram-3"></i>ONU / OLT</a>
            <a href="#customer-account"><i class="bi bi-card-list"></i>Account</a>
            <a href="#customer-activity"><i class="bi bi-clock-history"></i>Activity</a>
        </nav>

        <div class="row g-3">
            {{-- Main identity --}}
            <div class="col-xl-7" id="customer-overview">
                <section class="customer-detail-card h-100">
                    <div class="customer-card-heading">
                        <span class="customer-card-icon"><i class="bi bi-person-vcard"></i></span>
                        <div><h3>Customer Information</h3><p>Identity, contact and registration details</p></div>
                    </div>
                    <div class="customer-detail-grid">
                        <div class="customer-detail-item"><span>Customer ID</span><strong>#{{ $displayCustomerId }}</strong></div>
                        <div class="customer-detail-item"><span>Account Status</span><strong><span class="profile-status profile-status--{{ $statusClass }}"><span></span>{{ $statusLabel }}</span></strong></div>
                        <div class="customer-detail-item"><span>Customer Name</span><strong>{{ $customerName }}</strong></div>
                        <div class="customer-detail-item"><span>Mobile Number</span><strong>@if($customer->mobile)<a href="tel:{{ $customer->mobile }}">{{ $customer->mobile }}</a>@else — @endif</strong></div>
                        <div class="customer-detail-item"><span>Alternative Mobile</span><strong>@if($customer->alternative_mobile)<a href="tel:{{ $customer->alternative_mobile }}">{{ $customer->alternative_mobile }}</a>@else — @endif</strong></div>
                        <div class="customer-detail-item"><span>Email Address</span><strong>@if($customer->email)<a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>@else — @endif</strong></div>
                        <div class="customer-detail-item"><span>Identification No.</span><strong>{{ $customer->identification_no ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Connection Date</span><strong>{{ $customer->connection_date ? \Carbon\Carbon::parse($customer->connection_date)->format('d M Y') : '—' }}</strong></div>
                        <div class="customer-detail-item customer-detail-item--wide"><span>Address</span><strong>{{ $address }}</strong></div>
                    </div>
                </section>
            </div>

            {{-- PPP and router details --}}
            <div class="col-xl-5" id="customer-connection">
                <section class="customer-detail-card h-100">
                    <div class="customer-card-heading">
                        <span class="customer-card-icon customer-card-icon--blue"><i class="bi bi-router"></i></span>
                        <div><h3>Connection &amp; PPPoE</h3><p>Router account and live address details</p></div>
                    </div>
                    <div class="customer-detail-grid">
                        <div class="customer-detail-item"><span>Connection Type</span><strong>{{ $connectionType }}</strong></div>
                        <div class="customer-detail-item"><span>PPP Status</span><strong>{{ $ppp?->status ? ucfirst((string) $ppp->status) : '—' }}</strong></div>
                        <div class="customer-detail-item"><span>PPPoE Username</span><strong>{{ $ppp?->username ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Password</span><strong class="customer-masked-value">••••••••</strong></div>
                        <div class="customer-detail-item"><span>Router / MikroTik</span><strong>{{ $ppp?->router_name ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Current IP Address</span><strong>{{ $remoteIp ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Bandwidth</span><strong>{{ $ppp?->bandwidth ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Service Profile</span><strong>{{ $ppp?->profile ?: '—' }}</strong></div>
                    </div>
                </section>
            </div>

            {{-- Billing snapshot --}}
            <div class="col-xl-7" id="customer-billing">
                <section class="customer-detail-card h-100">
                    <div class="customer-card-heading customer-card-heading--between">
                        <div class="d-flex align-items-center gap-2">
                            <span class="customer-card-icon customer-card-icon--green"><i class="bi bi-receipt-cutoff"></i></span>
                            <div><h3>Billing Information</h3><p>Current bill, credits and service expiry</p></div>
                        </div>
                        <a class="customer-card-link" href="{{ route('customer.paid-ledger', encrypt($customer->customer_unique_id)) }}">View Ledger <i class="bi bi-arrow-up-right"></i></a>
                    </div>
                    <div class="customer-billing-highlight">
                        <div><span>Monthly Plan</span><strong>{{ $packageName }}</strong></div>
                        <div><span>Monthly Bill</span><strong>{{ number_format($monthlyRent, 2) }} ৳</strong></div>
                        <div><span>Current Due</span><strong class="{{ $dueAmount > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($dueAmount, 2) }} ৳</strong></div>
                    </div>
                    <div class="customer-detail-grid customer-detail-grid--billing">
                        <div class="customer-detail-item"><span>Previous Due</span><strong>{{ number_format((float) ($billing?->previous_due ?? 0), 2) }} ৳</strong></div>
                        <div class="customer-detail-item"><span>Discount</span><strong>{{ number_format((float) ($billing?->discount ?? 0), 2) }} ৳</strong></div>
                        <div class="customer-detail-item"><span>Advance Credit</span><strong>{{ number_format((float) ($billing?->advance ?? 0), 2) }} ৳</strong></div>
                        <div class="customer-detail-item"><span>Additional Charge</span><strong>{{ number_format((float) ($billing?->additional_charge ?? 0), 2) }} ৳</strong></div>
                        <div class="customer-detail-item"><span>VAT</span><strong>{{ number_format((float) ($billing?->vat ?? 0), 2) }} ৳</strong></div>
                        <div class="customer-detail-item"><span>Total Bill</span><strong>{{ number_format($totalAmount, 2) }} ৳</strong></div>
                        <div class="customer-detail-item"><span>Paid Amount</span><strong class="text-success">{{ number_format($paidAmount, 2) }} ৳</strong></div>
                        <div class="customer-detail-item"><span>Expiry Date</span><strong class="{{ $isExpired ? 'text-danger' : '' }}">{{ $expiryLabel }}</strong></div>
                        <div class="customer-detail-item"><span>Auto Disable</span><strong>{{ $billing?->auto_disable ? 'Enabled' : 'Disabled' }}</strong></div>
                        <div class="customer-detail-item"><span>Billing Type</span><strong>{{ $billing?->billing_type ? ucfirst((string) $billing->billing_type) : '—' }}</strong></div>
                    </div>
                </section>
            </div>

            {{-- Optical network information --}}
            <div class="col-xl-5" id="customer-onu">
                <section class="customer-detail-card h-100">
                    <div class="customer-card-heading">
                        <span class="customer-card-icon customer-card-icon--violet"><i class="bi bi-diagram-3"></i></span>
                        <div><h3>ONU / OLT Information</h3><p>Optical device mapping and signal status</p></div>
                    </div>
                    @if($mapping)
                        <div class="customer-onu-statusbar">
                            <span><i class="bi bi-router"></i>{{ $mapping->olt?->name ?: $mapping->olt?->hostname ?: 'OLT' }}</span>
                            <span class="profile-status profile-status--{{ strtolower((string) $mapping->status) === 'online' ? 'success' : 'secondary' }}"><span></span>{{ $mapping->status ? ucfirst((string) $mapping->status) : 'Mapped' }}</span>
                        </div>
                        <div class="customer-detail-grid">
                            <div class="customer-detail-item"><span>PON Port</span><strong>{{ $mapping->pon_port ?: '—' }}</strong></div>
                            <div class="customer-detail-item"><span>ONU ID</span><strong>{{ $mapping->onu_id ?: '—' }}</strong></div>
                            <div class="customer-detail-item"><span>ONU Serial</span><strong>{{ $mapping->onu_serial ?: '—' }}</strong></div>
                            <div class="customer-detail-item"><span>ONU MAC</span><strong class="customer-mono">{{ $mapping->onu_mac ?: '—' }}</strong></div>
                            <div class="customer-detail-item"><span>ONU Type / Model</span><strong>{{ $mapping->onu_type ?: '—' }}</strong></div>
                            <div class="customer-detail-item"><span>ONU IP</span><strong>{{ $mapping->onu_ip ?: '—' }}</strong></div>
                            <div class="customer-detail-item"><span>RX Power</span><strong><span class="optical-value optical-value--{{ $rxClass }}">{{ $rxPower !== null ? number_format((float) $rxPower, 2).' dBm' : '—' }}</span></strong></div>
                            <div class="customer-detail-item"><span>TX Power</span><strong>{{ $mapping->tx_power !== null ? number_format((float) $mapping->tx_power, 2).' dBm' : '—' }}</strong></div>
                            <div class="customer-detail-item customer-detail-item--wide"><span>Last Seen</span><strong>{{ $mapping->last_seen_at?->format('d M Y, h:i:s A') ?: '—' }}</strong></div>
                        </div>
                    @else
                        <div class="customer-empty-state">
                            <span><i class="bi bi-router"></i></span>
                            <strong>No ONU mapping found</strong>
                            <p>This customer is not linked to an OLT / ONU record yet.</p>
                        </div>
                    @endif
                </section>
            </div>

            {{-- Account and personal contact information --}}
            <div class="col-xl-7" id="customer-account">
                <section class="customer-detail-card h-100">
                    <div class="customer-card-heading">
                        <span class="customer-card-icon customer-card-icon--amber"><i class="bi bi-person-lines-fill"></i></span>
                        <div><h3>Account &amp; Contact</h3><p>Ownership and additional customer information</p></div>
                    </div>
                    <div class="customer-detail-grid">
                        <div class="customer-detail-item"><span>Owner / Reseller</span><strong>{{ $ownerName }}</strong></div>
                        <div class="customer-detail-item"><span>Contact Person</span><strong>{{ $customer->contact_person ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Parents Name</span><strong>{{ $customer->parents_name ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Spouse Name</span><strong>{{ $customer->spouse_name ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Profession</span><strong>{{ $customer->profession ?: '—' }}</strong></div>
                        <div class="customer-detail-item"><span>Created</span><strong>{{ $customer->created_at?->format('d M Y, h:i A') ?: '—' }}</strong></div>
                    </div>
                </section>
            </div>

            {{-- Service inventory is kept as its own quick reference section. --}}
            <div class="col-xl-5">
                <section class="customer-detail-card h-100">
                    <div class="customer-card-heading">
                        <span class="customer-card-icon customer-card-icon--blue"><i class="bi bi-box-seam"></i></span>
                        <div><h3>Service Inventory</h3><p>Provisioned customer service record</p></div>
                    </div>
                    <div class="customer-inventory-list">
                        <div><span>Inventory Type</span><strong>Internet Service</strong></div>
                        <div><span>Service Status</span><strong>{{ $statusLabel }}</strong></div>
                        <div><span>Connection</span><strong>{{ $connectionType }}</strong></div>
                        <div><span>PPPoE User ID</span><strong>{{ $ppp?->username ?: '—' }}</strong></div>
                        <div><span>Router / MikroTik</span><strong>{{ $ppp?->router_name ?: '—' }}</strong></div>
                        <div><span>Service Profile</span><strong>{{ $ppp?->profile ?: '—' }}</strong></div>
                        <div><span>IP Address</span><strong>{{ $remoteIp ?: '—' }}</strong></div>
                        <div><span>Bandwidth</span><strong>{{ $ppp?->bandwidth ?: '—' }}</strong></div>
                        <div><span>Package</span><strong>{{ $packageName }}</strong></div>
                        <div><span>ONU MAC</span><strong class="customer-mono">{{ $mapping?->onu_mac ?: '—' }}</strong></div>
                        <div><span>OLT / PON</span><strong>{{ $mapping ? (($mapping->olt?->name ?: $mapping->olt?->hostname ?: 'OLT').' / '.($mapping->pon_port ?: '—')) : '—' }}</strong></div>
                        <div><span>ONU Status</span><strong>{{ $mapping?->status ? ucfirst((string) $mapping->status) : 'Not Mapped' }}</strong></div>
                    </div>
                </section>
            </div>

            {{-- Customer-specific audit trail --}}
            <div class="col-12" id="customer-activity">
                <section class="customer-detail-card">
                    <div class="customer-card-heading">
                        <span class="customer-card-icon customer-card-icon--slate"><i class="bi bi-clock-history"></i></span>
                        <div><h3>Activity Log</h3><p>Recent actions recorded against this customer</p></div>
                        <span class="customer-activity-count">{{ $activities->count() }} recent</span>
                    </div>
                    @forelse($activities as $activity)
                        <div class="customer-activity-item">
                            <span class="customer-activity-dot"></span>
                            <div class="min-w-0">
                                <strong>{{ $activity->description }}</strong>
                                <small>{{ $activity->causer?->name ?: 'System' }} <span>·</span> {{ $activity->created_at?->format('d M Y, h:i A') }} <span>·</span> {{ $activity->created_at?->diffForHumans() }}</small>
                            </div>
                        </div>
                    @empty
                        <div class="customer-empty-state customer-empty-state--compact">
                            <span><i class="bi bi-clock-history"></i></span>
                            <strong>No customer activity yet</strong>
                            <p>Customer-specific changes and system events will appear here when recorded.</p>
                        </div>
                    @endforelse
                </section>
            </div>
        </div>
    </div>

    @push('styles')
    <style>
        .customer-profile-page {
            --cp-surface: var(--bs-body-bg, #fff);
            --cp-border: var(--bs-border-color, #e7ebf1);
            --cp-text: var(--bs-body-color, #243247);
            --cp-muted: var(--bs-secondary-color, #78869a);
            color: var(--cp-text);
        }
        .customer-profile-eyebrow { color:#64748b; font-size:.68rem; font-weight:800; letter-spacing:.13em; margin-bottom:.2rem; }
        .customer-back-link { border-radius:8px; font-weight:650; }
        .customer-profile-hero {
            display:flex; align-items:center; justify-content:space-between; gap:1.25rem;
            padding:1.2rem 1.35rem; border:1px solid #dce7f5; border-left:4px solid #2563eb;
            border-radius:14px; background:linear-gradient(110deg,#f8fbff,#eef5ff);
        }
        .customer-profile-identity { display:flex; align-items:center; gap:1rem; min-width:0; }
        .customer-profile-avatar {
            width:60px; height:60px; flex:0 0 60px; border-radius:15px; overflow:hidden;
            display:flex; align-items:center; justify-content:center; color:#fff;
            background:linear-gradient(145deg,#2563eb,#0f766e); font-size:1.35rem; font-weight:800;
            box-shadow:0 5px 14px rgba(37,99,235,.18);
        }
        .customer-profile-avatar img { width:100%; height:100%; object-fit:cover; }
        .customer-profile-id { color:#64748b; font-size:.69rem; font-weight:800; letter-spacing:.1em; }
        .customer-profile-hero h1 { color:#172b4d; font-size:clamp(1.2rem,2vw,1.65rem); font-weight:800; letter-spacing:-.025em; margin:.1rem 0 .25rem; }
        .customer-profile-subline { display:flex; flex-wrap:wrap; gap:.45rem 1rem; color:#64748b; font-size:.78rem; }
        .customer-profile-subline span { display:inline-flex; align-items:center; gap:.32rem; }
        .customer-profile-badges { display:flex; flex-wrap:wrap; gap:.4rem; }
        .profile-status,.profile-chip { display:inline-flex; align-items:center; gap:.38rem; padding:.27rem .58rem; border-radius:999px; font-size:.7rem; font-weight:750; white-space:nowrap; }
        .profile-status > span { width:.42rem; height:.42rem; display:inline-block; border-radius:50%; background:currentColor; }
        .profile-status--success { color:#137447; background:#dcfce7; }
        .profile-status--warning { color:#925b06; background:#fef3c7; }
        .profile-status--danger { color:#b42336; background:#fee2e2; }
        .profile-status--info { color:#075985; background:#e0f2fe; }
        .profile-status--secondary { color:#596579; background:#e9eef5; }
        .profile-chip { color:#42536b; background:#e9f0f8; }
        .profile-chip--muted { color:#667085; background:#edf0f4; }
        .customer-profile-expiry { min-width:145px; padding:.7rem .85rem; border:1px solid #dbe6f3; border-radius:11px; background:#fff; text-align:right; }
        .customer-profile-expiry > span,.customer-profile-expiry small { display:block; color:#718096; font-size:.7rem; }
        .customer-profile-expiry strong { display:block; color:#1e3a5f; font-size:1.05rem; font-weight:850; }
        .customer-profile-expiry.is-expired { border-color:#fecaca; background:#fff7f7; }
        .customer-profile-expiry.is-expired strong { color:#b42336; }
        .customer-snapshot-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.7rem; }
        .customer-snapshot-card { min-width:0; display:flex; align-items:center; gap:.7rem; padding:.78rem .85rem; border:1px solid var(--cp-border); border-radius:12px; background:var(--cp-surface); box-shadow:0 2px 8px rgba(15,23,42,.025); }
        .snapshot-icon { width:37px; height:37px; flex:0 0 37px; display:flex; align-items:center; justify-content:center; border-radius:10px; font-size:1rem; }
        .snapshot-icon--blue { color:#2457a6; background:#e8f0ff; }
        .snapshot-icon--green,.snapshot-icon--good { color:#137447; background:#dcfce7; }
        .snapshot-icon--red,.snapshot-icon--bad { color:#b42336; background:#fee2e2; }
        .snapshot-icon--warn { color:#925b06; background:#fef3c7; }
        .snapshot-icon--violet { color:#6941a5; background:#f0eaff; }
        .snapshot-icon--muted { color:#64748b; background:#eef2f6; }
        .customer-snapshot-card small { display:block; color:var(--cp-muted); font-size:.68rem; font-weight:650; }
        .customer-snapshot-card strong { display:block; overflow:hidden; color:var(--cp-text); font-size:.98rem; font-weight:850; line-height:1.25; text-overflow:ellipsis; white-space:nowrap; }
        .customer-snapshot-card em { display:block; overflow:hidden; color:var(--cp-muted); font-size:.66rem; font-style:normal; text-overflow:ellipsis; white-space:nowrap; }
        .customer-profile-actions { padding:.75rem .85rem; border:1px solid var(--cp-border); border-radius:12px; background:var(--cp-surface); }
        .customer-profile-actions-label { margin-bottom:.55rem; color:var(--cp-muted); font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
        .customer-profile-actions-list { display:flex; flex-wrap:wrap; gap:.42rem; }
        .profile-action { display:inline-flex; align-items:center; justify-content:center; gap:.42rem; min-height:34px; padding:.42rem .7rem; border:1px solid var(--cp-border); border-radius:8px; background:var(--cp-surface); color:var(--cp-text); font-size:.74rem; font-weight:700; text-decoration:none; cursor:pointer; }
        .profile-action:hover { color:#1d4ed8; border-color:#9db9e8; background:#f5f8ff; }
        .profile-action--primary { color:#fff; border-color:#2457a6; background:#2457a6; }
        .profile-action--primary:hover { color:#fff; background:#194887; border-color:#194887; }
        .customer-profile-nav { display:flex; align-items:center; gap:.35rem; overflow-x:auto; padding:.35rem; border:1px solid var(--cp-border); border-radius:10px; background:var(--cp-surface); scrollbar-width:thin; }
        .customer-profile-nav a { display:inline-flex; align-items:center; gap:.38rem; flex:0 0 auto; padding:.4rem .65rem; border-radius:7px; color:var(--cp-muted); font-size:.72rem; font-weight:700; text-decoration:none; }
        .customer-profile-nav a:hover { color:#2457a6; background:#edf4ff; }
        .customer-detail-card { height:100%; padding:1rem 1.05rem; border:1px solid var(--cp-border); border-radius:13px; background:var(--cp-surface); box-shadow:0 3px 12px rgba(15,23,42,.035); scroll-margin-top:1rem; }
        .customer-card-heading { display:flex; align-items:center; gap:.65rem; margin-bottom:.8rem; }
        .customer-card-heading--between { justify-content:space-between; }
        .customer-card-icon { width:34px; height:34px; flex:0 0 34px; display:flex; align-items:center; justify-content:center; border-radius:9px; color:#2457a6; background:#eaf1ff; font-size:.95rem; }
        .customer-card-icon--blue { color:#2457a6; background:#eaf1ff; }
        .customer-card-icon--green { color:#137447; background:#e1f6ea; }
        .customer-card-icon--violet { color:#6941a5; background:#f0eaff; }
        .customer-card-icon--amber { color:#925b06; background:#fff3d6; }
        .customer-card-icon--slate { color:#475569; background:#edf1f5; }
        .customer-card-heading h3 { margin:0; color:var(--cp-text); font-size:.91rem; font-weight:850; }
        .customer-card-heading p { margin:.1rem 0 0; color:var(--cp-muted); font-size:.68rem; }
        .customer-card-link { display:inline-flex; align-items:center; gap:.25rem; color:#2457a6; font-size:.7rem; font-weight:750; text-decoration:none; white-space:nowrap; }
        .customer-detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); border-top:1px solid var(--cp-border); }
        .customer-detail-item { min-width:0; padding:.57rem .62rem; border-bottom:1px solid var(--cp-border); }
        .customer-detail-item:nth-child(odd) { border-right:1px solid var(--cp-border); }
        .customer-detail-item--wide { grid-column:1/-1; border-right:0 !important; }
        .customer-detail-item > span { display:block; margin-bottom:.16rem; color:var(--cp-muted); font-size:.66rem; font-weight:650; }
        .customer-detail-item > strong { display:block; overflow-wrap:anywhere; color:var(--cp-text); font-size:.77rem; font-weight:750; line-height:1.35; }
        .customer-detail-item > strong a { color:inherit; text-decoration:none; }
        .customer-detail-item > strong a:hover { color:#2457a6; text-decoration:underline; }
        .customer-masked-value { letter-spacing:.11em; color:var(--cp-muted) !important; }
        .customer-billing-highlight { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem; margin-bottom:.65rem; }
        .customer-billing-highlight > div { min-width:0; padding:.62rem .7rem; border:1px solid var(--cp-border); border-radius:9px; background:var(--bs-tertiary-bg,#f8fafc); }
        .customer-billing-highlight span { display:block; color:var(--cp-muted); font-size:.65rem; font-weight:650; }
        .customer-billing-highlight strong { display:block; overflow:hidden; color:var(--cp-text); font-size:.82rem; font-weight:850; text-overflow:ellipsis; white-space:nowrap; }
        .customer-detail-grid--billing .customer-detail-item { padding:.48rem .58rem; }
        .customer-onu-statusbar { display:flex; align-items:center; justify-content:space-between; gap:.5rem; margin-bottom:.55rem; padding:.55rem .65rem; border:1px solid var(--cp-border); border-radius:8px; background:var(--bs-tertiary-bg,#f8fafc); }
        .customer-onu-statusbar > span:first-child { display:inline-flex; align-items:center; gap:.35rem; overflow:hidden; color:var(--cp-text); font-size:.73rem; font-weight:750; text-overflow:ellipsis; white-space:nowrap; }
        .optical-value { display:inline-flex; align-items:center; padding:.17rem .45rem; border-radius:5px; font-weight:850; }
        .optical-value--good { color:#137447; background:#dcfce7; }
        .optical-value--warn { color:#925b06; background:#fef3c7; }
        .optical-value--bad { color:#b42336; background:#fee2e2; }
        .optical-value--muted { color:#64748b; background:#eef2f6; }
        .customer-empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:170px; padding:1.2rem; text-align:center; }
        .customer-empty-state > span { width:40px; height:40px; display:flex; align-items:center; justify-content:center; margin-bottom:.5rem; border-radius:11px; background:var(--bs-tertiary-bg,#f1f5f9); color:var(--cp-muted); font-size:1.1rem; }
        .customer-empty-state strong { color:var(--cp-text); font-size:.8rem; }
        .customer-empty-state p { margin:.2rem 0 0; color:var(--cp-muted); font-size:.7rem; }
        .customer-empty-state--compact { min-height:115px; }
        .customer-inventory-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }
        .customer-inventory-list > div { display:flex; flex-direction:column; gap:.15rem; min-width:0; padding:.47rem .55rem; border-bottom:1px solid var(--cp-border); }
        .customer-inventory-list > div:nth-child(odd) { border-right:1px solid var(--cp-border); }
        .customer-inventory-list span { color:var(--cp-muted); font-size:.64rem; }
        .customer-inventory-list strong { color:var(--cp-text); font-size:.73rem; font-weight:750; overflow-wrap:anywhere; }
        .customer-activity-count { margin-left:auto; padding:.22rem .48rem; border-radius:999px; color:var(--cp-muted); background:var(--bs-tertiary-bg,#f1f5f9); font-size:.65rem; font-weight:750; }
        .customer-activity-item { display:flex; align-items:flex-start; gap:.6rem; padding:.62rem .15rem; border-top:1px solid var(--cp-border); }
        .customer-activity-dot { width:.48rem; height:.48rem; flex:0 0 .48rem; margin-top:.28rem; border-radius:50%; background:#16a34a; box-shadow:0 0 0 3px rgba(22,163,74,.1); }
        .customer-activity-item strong { display:block; color:var(--cp-text); font-size:.76rem; }
        .customer-activity-item small { display:block; margin-top:.12rem; color:var(--cp-muted); font-size:.66rem; }
        .customer-activity-item small span { margin:0 .2rem; }
        html[data-bs-theme="dark"] .customer-profile-hero { border-color:#34465f; background:linear-gradient(110deg,#17263b,#1d3554); }
        html[data-bs-theme="dark"] .customer-profile-hero h1 { color:#f1f6ff; }
        html[data-bs-theme="dark"] .customer-profile-id, html[data-bs-theme="dark"] .customer-profile-subline { color:#b5c4d8; }
        html[data-bs-theme="dark"] .customer-profile-expiry { border-color:#40536d; background:#1b2a3e; }
        html[data-bs-theme="dark"] .customer-profile-expiry strong { color:#f1f6ff; }
        html[data-bs-theme="dark"] .customer-profile-expiry.is-expired { border-color:#7f3946; background:#3a202b; }
        html[data-bs-theme="dark"] .profile-chip { color:#d7e3f4; background:#2a3b52; }
        html[data-bs-theme="dark"] .profile-chip--muted { color:#c2cddd; background:#344256; }
        html[data-bs-theme="dark"] .profile-action:hover, html[data-bs-theme="dark"] .customer-profile-nav a:hover { color:#c5dcff; background:#263a55; }
        html[data-bs-theme="dark"] .customer-billing-highlight > div, html[data-bs-theme="dark"] .customer-onu-statusbar { background:#18283c; }
        html[data-bs-theme="dark"] .profile-status--success { color:#86efac; background:#16452f; }
        html[data-bs-theme="dark"] .profile-status--warning { color:#fde68a; background:#554014; }
        html[data-bs-theme="dark"] .profile-status--danger { color:#fda4af; background:#55232d; }
        html[data-bs-theme="dark"] .profile-status--info { color:#7dd3fc; background:#123c56; }
        html[data-bs-theme="dark"] .profile-status--secondary { color:#d0d9e5; background:#39485c; }
        html[data-bs-theme="dark"] .snapshot-icon--blue, html[data-bs-theme="dark"] .customer-card-icon--blue { color:#a8caff; background:#243d62; }
        html[data-bs-theme="dark"] .snapshot-icon--green, html[data-bs-theme="dark"] .snapshot-icon--good, html[data-bs-theme="dark"] .customer-card-icon--green { color:#86efac; background:#174631; }
        html[data-bs-theme="dark"] .snapshot-icon--red, html[data-bs-theme="dark"] .snapshot-icon--bad { color:#fda4af; background:#542832; }
        html[data-bs-theme="dark"] .snapshot-icon--warn { color:#fde68a; background:#554014; }
        html[data-bs-theme="dark"] .snapshot-icon--violet, html[data-bs-theme="dark"] .customer-card-icon--violet { color:#d8b4fe; background:#40285f; }
        html[data-bs-theme="dark"] .customer-card-icon--amber { color:#fde68a; background:#554014; }
        html[data-bs-theme="dark"] .customer-card-icon--slate, html[data-bs-theme="dark"] .snapshot-icon--muted { color:#d6e0ec; background:#344256; }
        html[data-bs-theme="dark"] .optical-value--good { color:#86efac; background:#16452f; }
        html[data-bs-theme="dark"] .optical-value--warn { color:#fde68a; background:#554014; }
        html[data-bs-theme="dark"] .optical-value--bad { color:#fda4af; background:#55232d; }
        @media(max-width:1199.98px) { .customer-snapshot-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media(max-width:767.98px) {
            .customer-profile-hero { align-items:flex-start; flex-direction:column; padding:1rem; }
            .customer-profile-expiry { width:100%; text-align:left; }
            .customer-profile-avatar { width:48px; height:48px; flex-basis:48px; border-radius:12px; }
            .customer-profile-actions-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }
            .profile-action { min-width:0; padding:.42rem .45rem; font-size:.69rem; }
            .customer-detail-grid { grid-template-columns:1fr; }
            .customer-detail-item:nth-child(odd) { border-right:0; }
            .customer-detail-item--wide { grid-column:auto; }
            .customer-billing-highlight { grid-template-columns:1fr; }
            .customer-card-heading--between { align-items:flex-start; }
            .customer-inventory-list { grid-template-columns:1fr; }
            .customer-inventory-list > div:nth-child(odd) { border-right:0; }
            .customer-snapshot-grid { gap:.45rem; }
            .customer-snapshot-card { align-items:flex-start; gap:.45rem; padding:.62rem; }
            .snapshot-icon { width:31px; height:31px; flex-basis:31px; font-size:.85rem; }
            .customer-snapshot-card strong { font-size:.82rem; }
            .customer-snapshot-card em { font-size:.61rem; }
        }
        @media print {
            .customer-profile-nav,.customer-profile-actions,.customer-back-link { display:none!important; }
            .customer-profile-page { padding:0!important; }
            .customer-profile-hero,.customer-detail-card,.customer-snapshot-card { break-inside:avoid; box-shadow:none!important; }
            .customer-profile-hero { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .customer-detail-card { border-color:#d5dbe5!important; }
        }
    </style>
    @endpush
</x-app-layout>
