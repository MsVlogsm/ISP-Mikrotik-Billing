<x-app-layout>
    <x-slot name="header"><div class="d-flex justify-content-between align-items-center"><div><div class="small text-muted">CUSTOMER ACTIONS</div><h2 class="h4 mb-0">{{ $customer->customer_name }} · {{ $customer->customer_unique_id }}</h2></div><a class="btn btn-outline-secondary btn-sm" href="{{ route('customer.details',encrypt($customer->customer_unique_id)) }}">Back to Profile</a></div></x-slot>
    @php($url=route('customer.actions.handle',['id'=>encrypt($customer->customer_unique_id),'action'=>$action]))
    <div class="container-fluid py-3">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
        @if($errors->any())<div class="alert alert-danger"><strong>Please check:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="row g-3">
            <div class="col-lg-3"><div class="card"><div class="card-header fw-bold">All Actions</div><div class="list-group list-group-flush">
                @foreach(['owner'=>'Change Customer Owner','class'=>'Change Customer Class','billing-date'=>'Change Billing Date','password'=>'Change PPPoE Password','ledger'=>'Customer Billing Ledger','cash-credit'=>'Cash / Credit / Return','recharge'=>'Monthly Recharge','grace'=>'Extra Grace','invoice'=>'Invoice','pos-print'=>'POS Print','sms'=>'Send SMS','online-graph'=>'Online Graph','ticket'=>'Create Ticket','ticket-history'=>'Ticket History','wifi-login'=>'WiFi Router Login'] as $key=>$label)
                    <a class="list-group-item list-group-item-action {{ $action===$key?'active':'' }}" href="{{ route('customer.actions',['id'=>encrypt($customer->customer_unique_id),'action'=>$key]) }}">{{ $label }}</a>
                @endforeach
            </div></div></div>
            <div class="col-lg-9"><div class="card shadow-sm"><div class="card-header fw-bold">{{ ['owner'=>'Change Customer Owner','class'=>'Change Customer Class','billing-date'=>'Change Billing Date','password'=>'Change PPPoE Password','ledger'=>'Customer Billing Ledger','cash-credit'=>'Cash / Credit / Return','recharge'=>'Monthly Recharge','grace'=>'Extra Grace','invoice'=>'Invoice','pos-print'=>'POS Print','sms'=>'Send SMS','online-graph'=>'Online Graph','ticket'=>'Create Ticket','ticket-history'=>'Ticket History','wifi-login'=>'WiFi Router Login'][$action] ?? 'Customer Action' }}</div><div class="card-body">
                @if($action==='owner')
                    <form method="POST" action="{{ $url }}">@csrf
                        <div class="row g-3"><div class="col-md-6"><label class="form-label">New Owner / Reseller</label><select class="form-select" name="reseller_id"><option value="">Company / Default</option>@foreach($resellers as $r)<option value="{{ $r->id }}" @selected($customer->reseller_id==$r->id)>{{ $r->company ?: ($r->user->name ?? 'Reseller #'.$r->id) }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">POP / Area</label><select class="form-select" name="pop_area"><option value="">Keep current / Default</option>@foreach(['DC','NOC','POP'] as $area)<option value="{{ $area }}" @selected($customer->official?->distribution_location===$area)>{{ $area }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label">Package</label><select class="form-select" name="package_id"><option value="">Keep current</option>@foreach($packages as $p)<option value="{{ $p->id }}" @selected($customer->package_id==$p->id)>{{ $p->package }} · {{ $p->price }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Billing / Expiry Date (expiry reductions/corrections only; extension requires payment)</label><input type="date" name="billing_date" class="form-control" value="{{ $customer->billing?->auto_disable_date ? \Carbon\Carbon::parse($customer->billing->auto_disable_date)->format('Y-m-d') : '' }}"></div><div class="col-md-6"><label class="form-label">Reason</label><input name="reason" class="form-control" maxlength="500" placeholder="Reason for ownership change"></div></div><button class="btn btn-primary mt-3">Change Owner</button>
                    </form>
                @elseif($action==='class')
                    <form method="POST" action="{{ $url }}">@csrf<label class="form-label">Customer Class</label><select name="client_type" class="form-select" required><option value="home" @selected(($customer->official?->client_type??'home')==='home')>Home — Standard billing</option><option value="commercial" @selected($customer->official?->client_type==='commercial')>Commercial</option><option value="Corporate" @selected($customer->official?->client_type==='Corporate')>Corporate</option><option value="business" @selected($customer->official?->client_type==='business')>Business</option></select><div class="form-text">Updates the customer class stored in the official customer record.</div><button class="btn btn-primary mt-3">Save Class</button></form>
                @elseif($action==='billing-date')
                    <form method="POST" action="{{ $url }}">@csrf<div class="row g-3"><div class="col-md-6"><label class="form-label">New Expiry Date</label><input type="date" name="expiry_date" class="form-control" required value="{{ $customer->billing?->auto_disable_date ? \Carbon\Carbon::parse($customer->billing->auto_disable_date)->format('Y-m-d') : '' }}"></div><div class="col-md-6"><label class="form-label">Charge (required to extend prepaid expiry)</label><input type="number" min="0" step="0.01" name="charge" class="form-control" value="0"></div><div class="col-md-6"><label class="form-label">Payment Method</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="rocket">Rocket</option><option value="bank">Bank</option><option value="other">Other</option></select></div><div class="col-md-6"><label class="form-label">Money Receipt No.</label><input name="receipt_no" class="form-control"></div></div><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="send_sms" value="1" id="smsDate"><label class="form-check-label" for="smsDate">Send customer SMS</label></div><button class="btn btn-primary mt-3">Change Date &amp; Charge</button></form>
                @elseif($action==='password')
                    <div class="alert alert-info">PPPoE account: <strong>{{ $customer->pppUser?->username ?? 'No PPPoE account linked' }}</strong></div><form method="POST" action="{{ $url }}">@csrf<div class="row g-3"><div class="col-md-6"><label class="form-label">New Password</label><input type="password" name="password" class="form-control" minlength="4" maxlength="128" required autocomplete="new-password"></div><div class="col-md-6"><label class="form-label">Confirm Password</label><input type="password" name="confirm_password" class="form-control" required autocomplete="new-password"></div></div><button class="btn btn-primary mt-3" {{ !$customer->pppUser?'disabled':'' }}>Save Password</button></form>
                @elseif($action==='cash-credit')
                    <form method="POST" action="{{ $url }}">@csrf<div class="row g-3"><div class="col-md-6"><label class="form-label">Transaction Type</label><select name="type" class="form-select"><option value="cash_received">Cash Received</option><option value="credit">Credit</option><option value="return">Return / Reverse Payment</option></select></div><div class="col-md-6"><label class="form-label">Amount (৳)</label><input type="number" min="0.01" step="0.01" name="amount" class="form-control" required></div><div class="col-md-6"><label class="form-label">Method</label><select name="payment_method" class="form-select" required><option value="cash">Cash</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="rocket">Rocket</option><option value="bank">Bank</option><option value="other">Other</option></select></div><div class="col-md-6"><label class="form-label">Receipt No.</label><input name="receipt_no" class="form-control"></div><div class="col-12"><label class="form-label">Details</label><textarea name="details" class="form-control" rows="2"></textarea></div></div><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="send_sms" value="1" id="smsCash"><label class="form-check-label" for="smsCash">Send customer SMS</label></div><button class="btn btn-primary mt-3">Save Transaction</button></form>
                @elseif($action==='recharge')
                    <div class="row mb-3"><div class="col"><div class="border rounded p-3">Monthly plan <strong>{{ number_format((float)($customer->billing?->monthly_rent??0),2) }} ৳</strong></div></div><div class="col"><div class="border rounded p-3">Current expiry <strong>{{ $customer->billing?->auto_disable_date ? \Carbon\Carbon::parse($customer->billing->auto_disable_date)->format('d M Y') : '—' }}</strong></div></div></div><form method="POST" action="{{ $url }}">@csrf<div class="row g-3"><div class="col-md-4"><label class="form-label">Months</label><select name="months" class="form-select">@for($m=1;$m<=24;$m++)<option value="{{ $m }}">{{ $m }} Month(s)</option>@endfor</select></div><div class="col-md-4"><label class="form-label">Payment</label><select name="payment_mode" class="form-select"><option value="receive_payment">Receive payment</option>@if($customer->billing?->billing_type !== 'prepaid')<option value="extend_only">Extend only</option>@endif</select></div><div class="col-md-4"><label class="form-label">Method</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="rocket">Rocket</option><option value="bank">Bank</option><option value="other">Other</option></select></div><div class="col-12"><label class="form-label">Receipt No.</label><input name="receipt_no" class="form-control"></div></div><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="send_sms" value="1" id="smsRecharge"><label class="form-check-label" for="smsRecharge">Send customer SMS</label></div><button class="btn btn-success mt-3">Recharge Now</button></form>
                @elseif($action==='grace')
                    <p class="text-muted">Current grace until: {{ $customer->billing?->extra_date ? \Carbon\Carbon::parse($customer->billing->extra_date)->format('d M Y H:i') : 'Not set' }}</p><form method="POST" action="{{ $url }}">@csrf<div class="row g-3"><div class="col-md-4"><label class="form-label">Days</label><input type="number" name="days" class="form-control" min="0" max="90" value="0" required></div><div class="col-md-4"><label class="form-label">Hours</label><input type="number" name="hours" class="form-control" min="0" max="23" value="0" required></div><div class="col-md-12"><label class="form-label">Note / Reason</label><textarea name="note" class="form-control" rows="2"></textarea></div></div><button class="btn btn-info mt-3">Apply Grace</button></form>
                @elseif($action==='sms')
                    <p>Send to: <strong>{{ $customer->mobile ?: 'No mobile number' }}</strong></p><form method="POST" action="{{ $url }}">@csrf<label class="form-label">Template</label><select name="template_id" class="form-select mb-3"><option value="">Custom SMS</option>@foreach($smsTemplates as $smsTemplate)<option value="{{ $smsTemplate->id }}">{{ $smsTemplate->template_name }}</option>@endforeach</select><label class="form-label">Message (required for Custom SMS)</label><textarea name="message" class="form-control" rows="5" maxlength="1000" placeholder="Write a message, or choose an SMS template above"></textarea><button class="btn btn-success mt-3" {{ !$customer->mobile?'disabled':'' }}>Confirm &amp; Send</button></form>
                @elseif($action==='ticket')
                    <form method="POST" action="{{ $url }}">@csrf<div class="row g-3"><div class="col-md-4"><label class="form-label">Type</label><select name="ticket_type" class="form-select"><option value="complain">Complaint</option><option value="task">Task</option><option value="sales">Sales</option><option value="legacy_sales">Other</option></select></div><div class="col-md-4"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low">Low</option><option value="medium" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div><div class="col-md-4"><label class="form-label">Subject</label><input name="subject" class="form-control" required maxlength="190" value="Connection Problem"></div><div class="col-md-6"><label class="form-label">Topic</label><select name="topic" class="form-select"><option value="">Select topic (optional)</option>@foreach($templates as $ticketTemplate)<option value="{{ $ticketTemplate->name }}">{{ ucfirst($ticketTemplate->type) }} — {{ $ticketTemplate->name }}</option>@endforeach</select></div><div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="5" required></textarea></div></div><button class="btn btn-warning mt-3">Create Ticket</button></form>
                @elseif($action==='wifi-login')
                    <form method="POST" action="{{ $url }}">@csrf<div class="row g-3"><div class="col-md-6"><label class="form-label">Customer IP</label><input name="customer_ip" class="form-control" value="{{ $wifi->customer_ip ?? $customer->pppUser?->ppp_remote_ip ?? $customer->pppUser?->ip_address }}"></div><div class="col-md-6"><label class="form-label">WiFi Port</label><input type="number" name="wifi_port" class="form-control" min="1" max="65535" value="{{ $wifi->wifi_port ?? 80 }}" required></div><div class="col-md-6"><label class="form-label">Username</label><input name="username" class="form-control" value="{{ $wifi->username ?? '' }}"></div><div class="col-md-6"><label class="form-label">Password</label><input name="password" class="form-control" value="" placeholder="Leave blank to keep saved password" autocomplete="new-password"></div><div class="col-md-6"><label class="form-label">Local Login URL</label><input type="url" name="local_login_url" class="form-control" value="{{ $wifi->local_login_url ?? '' }}" placeholder="http://192.168.1.1"></div><div class="col-md-6"><label class="form-label">Remote Login URL</label><input type="url" name="remote_login_url" class="form-control" value="{{ $wifi->remote_login_url ?? '' }}" placeholder="https://router.example"></div></div><button class="btn btn-primary mt-3">Save Login Data</button>@if(!empty($wifi?->local_login_url)) <a class="btn btn-outline-primary mt-3" href="{{ $wifi->local_login_url }}" target="_blank" rel="noopener">Open Local Login</a>@endif @if(!empty($wifi?->remote_login_url)) <a class="btn btn-outline-success mt-3" href="{{ $wifi->remote_login_url }}" target="_blank" rel="noopener">Open Remote Login</a>@endif</form>
                @elseif($action==='ledger')
                    <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Date</th><th>Type</th><th>Method</th><th>Receipt</th><th>Details</th><th class="text-end">Amount</th><th>Status</th></tr></thead><tbody>@forelse($collections as $c)<tr><td>{{ $c->collection_date }}</td><td>{{ $c->payment_type ?: 'Collection' }}</td><td>{{ $c->payment_method ?: '—' }}</td><td>{{ $c->transaction_id ?: '—' }}</td><td>{{ $c->details ?: '—' }}</td><td class="text-end">{{ number_format((float)$c->collection_amount,2) }}</td><td>{{ $c->payment_status ?: '—' }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">No transactions recorded.</td></tr>@endforelse</tbody></table></div>
                @elseif($action==='ticket-history')
                    <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Ticket</th><th>Subject</th><th>Type</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead><tbody>@forelse($tickets as $t)<tr><td>{{ $t->ticket_no }}</td><td>{{ $t->subject }}</td><td>{{ $t->ticket_type }}</td><td>{{ $t->priority }}</td><td>{{ $t->status }}</td><td>{{ $t->created_at?->format('d M Y H:i') }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">No tickets for this customer.</td></tr>@endforelse</tbody></table></div>
                @elseif($action==='online-graph')
                    <div id="customer-live-traffic" data-url="{{ route('customer.live-traffic', ['id' => encrypt($customer->customer_unique_id)]) }}">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Status</div><div id="traffic-status" class="fs-5 fw-bold text-secondary">Checking…</div></div></div>
                            <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Download</div><div id="traffic-rx" class="fs-5 fw-bold text-success">0 Mbps</div></div></div>
                            <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Upload</div><div id="traffic-tx" class="fs-5 fw-bold text-primary">0 Mbps</div></div></div>
                            <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Session Usage</div><div id="traffic-total" class="fs-6 fw-bold">0 MB ↓ / 0 MB ↑</div></div></div>
                        </div>
                        <div class="border rounded p-2 bg-body-tertiary">
                            <div class="d-flex justify-content-between align-items-center px-2 py-1">
                                <strong><i class="bi bi-activity me-1"></i>Real-time Internet Usage</strong>
                                <span id="traffic-updated" class="small text-muted">Waiting for MikroTik…</span>
                            </div>
                            <svg id="customer-traffic-chart" viewBox="0 0 1000 360" preserveAspectRatio="none" role="img" aria-label="Customer real-time internet usage graph" style="width:100%;height:360px;display:block;">
                                <rect x="0" y="0" width="1000" height="360" fill="transparent"></rect>
                                <g id="traffic-grid"></g>
                                <polyline id="traffic-rx-line" fill="none" stroke="#198754" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points=""></polyline>
                                <polyline id="traffic-tx-line" fill="none" stroke="#0d6efd" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points=""></polyline>
                            </svg>
                            <div class="d-flex justify-content-center gap-4 small mt-1"><span class="text-success">● Download</span><span class="text-primary">● Upload</span></div>
                        </div>
                        <div id="traffic-message" class="alert alert-info mt-3 mb-0">Live traffic is read directly from the customer's PPPoE interface on MikroTik.</div>
                    </div>
                    <script>
                        (() => {
                            const root = document.getElementById('customer-live-traffic');
                            if (!root || root.dataset.initialized === '1') return;
                            root.dataset.initialized = '1';
                            const url = root.dataset.url;
                            const rx = [], tx = [], maxPoints = 60;
                            const el = id => document.getElementById(id);
                            const fmt = bits => bits >= 1000000 ? (bits / 1000000).toFixed(2) + ' Mbps' : bits >= 1000 ? (bits / 1000).toFixed(2) + ' Kbps' : Math.round(bits) + ' bps';
                            function draw() {
                                const all = rx.concat(tx);
                                const max = Math.max(1, ...all) * 1.15;
                                const line = values => values.map((v,i) => {
                                    const x = values.length <= 1 ? 500 : 20 + (i * 960 / (values.length - 1));
                                    const y = 330 - ((v / max) * 290);
                                    return `${x.toFixed(1)},${y.toFixed(1)}`;
                                }).join(' ');
                                el('traffic-rx-line').setAttribute('points', line(rx));
                                el('traffic-tx-line').setAttribute('points', line(tx));
                            }
                            function update(d) {
                                const t = d.traffic || {};
                                const rxBits = Number(t['rx-bits-per-second'] || 0);
                                const txBits = Number(t['tx-bits-per-second'] || 0);
                                rx.push(rxBits); tx.push(txBits);
                                if (rx.length > maxPoints) rx.shift();
                                if (tx.length > maxPoints) tx.shift();
                                el('traffic-rx').textContent = fmt(rxBits);
                                el('traffic-tx').textContent = fmt(txBits);
                                el('traffic-total').textContent = `${Number(t['rx-mb'] || 0).toFixed(2)} MB ↓ / ${Number(t['tx-mb'] || 0).toFixed(2)} MB ↑`;
                                el('traffic-status').textContent = d.online ? 'Online' : 'Offline';
                                el('traffic-status').className = 'fs-5 fw-bold ' + (d.online ? 'text-success' : 'text-danger');
                                el('traffic-updated').textContent = new Date().toLocaleTimeString();
                                el('traffic-message').className = d.online ? 'alert alert-success mt-3 mb-0' : 'alert alert-warning mt-3 mb-0';
                                el('traffic-message').textContent = d.online ? `Live PPPoE traffic: ${d.username} · ${t.interface || ''}` : 'Customer is not currently connected, or the PPPoE interface is unavailable.';
                                draw();
                            }
                            async function poll() {
                                try {
                                    const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
                                    const d = await res.json();
                                    if (!res.ok || !d.ok) throw new Error(d.message || 'Live traffic unavailable');
                                    update(d);
                                } catch (e) {
                                    el('traffic-status').textContent = 'Unavailable';
                                    el('traffic-status').className = 'fs-5 fw-bold text-warning';
                                    el('traffic-message').className = 'alert alert-warning mt-3 mb-0';
                                    el('traffic-message').textContent = e.message || 'Unable to read MikroTik traffic.';
                                }
                            }
                            poll();
                            const timer = setInterval(() => { if (document.body.contains(root)) poll(); else clearInterval(timer); }, 2000);
                        })();
                    </script>
                @else
                    <div class="alert alert-info">Use the links below for this customer.</div><a class="btn btn-primary" href="{{ route('customer.actions.invoice',['id'=>encrypt($customer->customer_unique_id)]) }}">Open Invoice</a><a class="btn btn-outline-primary" href="{{ route('customer.actions.print',['id'=>encrypt($customer->customer_unique_id)]) }}" target="_blank">POS Print</a>
                @endif
            </div></div></div>
        </div>
    </div>
</x-app-layout>
