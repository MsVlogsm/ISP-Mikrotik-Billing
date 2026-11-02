<x-app-layout>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h3 class="mb-1"><i class="bi bi-phone me-2 text-primary"></i>{{ __('Mobile Banking') }}</h3><small class="text-muted">MFS, payment gateways, collection logs and synchronization</small></div>
    <span class="badge bg-success">Operational</span>
  </div>
  <div class="card mb-3"><div class="card-body py-2">
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm {{ $tab==='overview'?'btn-primary':'btn-outline-primary' }}" href="{{ route('xlink.mobile-banking') }}"><i class="bi bi-grid me-1"></i>Overview</a>
      <a class="btn btn-sm {{ $tab==='logs'?'btn-primary':'btn-outline-primary' }}" href="{{ route('mobile-banking.logs') }}"><i class="bi bi-list-check me-1"></i>Mobile Banking LOG</a>
      <a class="btn btn-sm {{ $tab==='gateways'?'btn-primary':'btn-outline-primary' }}" href="{{ route('mobile-banking.gateways') }}"><i class="bi bi-credit-card me-1"></i>Payment Gateway</a>
      <a class="btn btn-sm {{ $tab==='methods'?'btn-primary':'btn-outline-primary' }}" href="{{ route('mobile-banking.methods') }}"><i class="bi bi-wallet2 me-1"></i>Payment Methods</a>
      <a class="btn btn-sm {{ $tab==='settings'?'btn-primary':'btn-outline-primary' }}" href="{{ route('mobile-banking.settings') }}"><i class="bi bi-sliders me-1"></i>Sync Settings</a>
      <a class="btn btn-sm {{ $tab==='subscriptions'?'btn-primary':'btn-outline-primary' }}" href="{{ route('mobile-banking.subscriptions') }}"><i class="bi bi-arrow-repeat me-1"></i>Stripe Subscriptions</a>
    </div>
  </div></div>
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($tab==='overview')
    <div class="row g-3 mb-3">@foreach($stats as $stat)<div class="col-md-3"><div class="card h-100"><div class="card-body"><small class="text-muted">{{ $stat['label'] }}</small><div class="fs-4 fw-bold mt-2">{{ $stat['value'] }}</div></div></div></div>@endforeach</div>
    <div class="row g-3"><div class="col-lg-6"><div class="card h-100"><div class="card-header fw-bold">Digital Payments</div><div class="card-body"><p class="text-muted">Use the existing secure customer payment flows for bKash, Nagad and SSLCommerz. Successful transactions are recorded in the billing collection ledger.</p><a class="btn btn-success" href="{{ route('payment-collection') }}"><i class="bi bi-cash-coin me-1"></i>Open Collection Desk</a></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-bold">Gateway & Sync Controls</div><div class="card-body"><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="{{ route('mobile-banking.gateways') }}">Gateways</a><a class="btn btn-outline-primary" href="{{ route('mobile-banking.settings') }}">Sync Settings</a><a class="btn btn-outline-primary" href="{{ route('site-settings') }}">Advanced Gateway Credentials</a></div></div></div></div></div>
  @elseif($tab==='logs')
    <div class="card"><div class="card-header d-flex flex-wrap justify-content-between gap-2 align-items-center"><strong>Mobile Banking LOG</strong><form class="row g-2" method="get"><div class="col-auto"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="CID / transaction / invoice"></div><div class="col-auto"><select name="method" class="form-select form-select-sm"><option value="">All methods</option>@foreach(['bkash','nagad','rocket','upay','sslcommerz','stripe'] as $m)<option value="{{ $m }}" @selected(request('method')===$m)>{{ strtoupper($m) }}</option>@endforeach</select></div><div class="col-auto"><select name="status" class="form-select form-select-sm"><option value="">All status</option><option value="paid" @selected(request('status')==='paid')>Paid</option><option value="pending" @selected(request('status')==='pending')>Pending</option><option value="failed" @selected(request('status')==='failed')>Failed</option></select></div><div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div></form></div>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Date</th><th>Customer</th><th>Method</th><th>Transaction</th><th>Invoice</th><th class="text-end">Amount</th><th>Status</th><th>Collected By</th></tr></thead><tbody>
      @forelse($logs as $log)<tr><td>{{ optional($log->collection_date)->format('d-M-Y H:i') ?? $log->collection_date }}</td><td>{{ $log->customer_collection_unique_id }}</td><td><span class="badge bg-light text-dark border">{{ strtoupper($log->payment_method ?: 'n/a') }}</span></td><td><code>{{ $log->transaction_id ?: '—' }}</code></td><td>{{ $log->invoice_no }}</td><td class="text-end fw-semibold">{{ number_format((float)$log->collection_amount,2) }} {{ siteUrlSettings('site_currency') }}</td><td><span class="badge {{ $log->payment_status==='paid'?'bg-success':($log->payment_status==='pending'?'bg-warning text-dark':'bg-danger') }}">{{ ucfirst($log->payment_status ?: 'unknown') }}</span></td><td>{{ $log->collected_by ?: '—' }}</td></tr>@empty<tr><td colspan="8" class="text-center text-muted py-4">No mobile-banking transactions found.</td></tr>@endforelse
      </tbody></table></div><div class="card-footer">{{ $logs->links() }}</div></div>
  @elseif($tab==='gateways')
    <div class="row g-3">@foreach($gateways as $g)<div class="col-lg-4"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><h5 class="mb-1">{{ $g['name'] }}</h5><span class="badge {{ $g['enabled']?'bg-success':'bg-secondary' }}">{{ $g['enabled']?'Enabled':'Disabled' }}</span></div><div class="small text-muted mt-2">Type: {{ $g['type'] }}</div><div class="small text-muted mt-1 text-break">Endpoint: {{ $g['endpoint'] ?: 'Configured through gateway settings' }}</div></div><div class="card-footer bg-transparent"><a class="btn btn-sm btn-outline-primary" href="{{ $g['code']==='stripe' ? route('mobile-banking.subscriptions') : route('site-settings') }}">Configure</a></div></div></div>@endforeach</div>
  @elseif($tab==='subscriptions')
    <div class="row g-3 mb-3">
      <div class="col-md-3"><div class="card h-100"><div class="card-body"><small class="text-muted">Active subscriptions</small><div class="fs-4 fw-bold">{{ number_format($activeSubscriptions) }}</div></div></div></div>
      <div class="col-md-3"><div class="card h-100"><div class="card-body"><small class="text-muted">Past due</small><div class="fs-4 fw-bold">{{ number_format($pastDueSubscriptions) }}</div></div></div></div>
      <div class="col-md-3"><div class="card h-100"><div class="card-body"><small class="text-muted">Webhook failures</small><div class="fs-4 fw-bold">{{ number_format($webhookFailures) }}</div></div></div></div>
      <div class="col-md-3"><div class="card h-100"><div class="card-body"><small class="text-muted">Stripe mode / currency</small><div class="fs-5 fw-bold text-uppercase">{{ $stripeMode }} / {{ $stripeCurrency }}</div></div></div></div>
    </div>

    @if(!$stripeConfigured)
      <div class="alert alert-warning">
        <strong>Stripe is not ready.</strong>
        Recurring checkout stays disabled until the server-side API key and webhook signing secret are configured.
        Existing bKash, Nagad, Rocket and SSLCommerz payment flows are unaffected.
      </div>
      <div class="card mb-3"><div class="card-header fw-bold">Server Environment Configuration</div><div class="card-body">
        <p class="text-muted small mb-2">Add these values to the server's protected <code>.env</code> file. Never put secret keys in the browser or database.</p>
        <pre class="bg-dark text-light rounded p-3 mb-0"><code>STRIPE_ENABLED=false
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_CURRENCY=bdt
STRIPE_MODE=test</code></pre>
        <p class="small text-muted mt-2 mb-0">After saving the secrets, clear Laravel's config cache. Keep <code>STRIPE_ENABLED=false</code> until test webhook delivery and renewal reconciliation pass.</p>
        <div class="border rounded p-3 mt-3">
          <div class="fw-semibold small mb-1">Stripe webhook endpoint</div>
          <code class="text-break">{{ url('/payment/stripe/webhook') }}</code>
          <div class="small text-muted mt-2">Subscribe to these events in Stripe Dashboard:</div>
          <code class="small d-block mt-1">checkout.session.completed</code>
          <code class="small d-block">checkout.session.expired</code>
          <code class="small d-block">customer.subscription.created</code>
          <code class="small d-block">customer.subscription.updated</code>
          <code class="small d-block">customer.subscription.deleted</code>
          <code class="small d-block">customer.subscription.paused</code>
          <code class="small d-block">customer.subscription.resumed</code>
          <code class="small d-block">invoice.paid</code>
          <code class="small d-block">invoice.payment_failed</code>
          <code class="small d-block">invoice.payment_action_required</code>
        </div>
      </div></div>
    @elseif(!$stripeEnabled)
      <div class="alert alert-info">Stripe credentials are present, but checkout is deliberately <strong>disabled</strong>. You can verify and map recurring Prices in this screen. Enable Stripe only after the test webhook and renewal checks pass.</div>
    @else
      <div class="alert alert-success">Stripe API and webhook secrets are present. Checkout switch: <strong>Enabled</strong>. Mode: <strong>{{ strtoupper($stripeMode) }}</strong>.</div>
    @endif

    <div class="row g-3 mb-3">
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header fw-bold">Map a recurring Price to a package</div>
          <div class="card-body">
            <form method="post" action="{{ route('mobile-banking.subscriptions.prices.save') }}">
              @csrf
              <div class="mb-3">
                <label class="form-label">X-Link Package</label>
                <select name="package_id" class="form-select" required>
                  <option value="">Select package</option>
                  @foreach($packages as $package)
                    <option value="{{ $package->id }}" @selected(old('package_id')==$package->id)>{{ $package->package }} — {{ number_format((float)$package->price,2) }} {{ $stripeCurrency }}</option>
                  @endforeach
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Stripe recurring Price ID</label>
                <input name="stripe_price_id" value="{{ old('stripe_price_id') }}" class="form-control" placeholder="price_..." required>
                @error('stripe_price_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
              </div>
              <button class="btn btn-primary" @disabled(!$stripeConfigured)><i class="bi bi-link-45deg me-1"></i>Verify & Map Price</button>
            </form>
            <p class="small text-muted mt-3 mb-0">The server verifies that the Stripe Price is active, recurring, uses {{ $stripeCurrency }} and exactly matches the X-Link package amount before enabling it.</p>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header fw-bold">Package Price Mappings</div>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead><tr><th>Package</th><th>Stripe Price</th><th>Recurring</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                @forelse($priceMappings as $mapping)
                  <tr>
                    <td>{{ $mapping->package?->package ?? 'Deleted package' }}</td>
                    <td><code>{{ $mapping->stripe_price_id }}</code></td>
                    <td>{{ $mapping->interval_count }} {{ $mapping->recurring_interval }}(s)</td>
                    <td>{{ number_format($mapping->unit_amount / 100,2) }} {{ strtoupper($mapping->currency) }}</td>
                    <td><span class="badge {{ $mapping->is_active?'bg-success':'bg-secondary' }}">{{ $mapping->is_active?'Enabled':'Disabled' }}</span></td>
                    <td class="text-nowrap">
                      <form class="d-inline" method="post" action="{{ route('mobile-banking.subscriptions.prices.toggle',$mapping) }}">@csrf
                        <button class="btn btn-sm btn-outline-primary">{{ $mapping->is_active?'Disable':'Re-verify & Enable' }}</button>
                      </form>
                      <form class="d-inline" method="post" action="{{ route('mobile-banking.subscriptions.prices.delete',$mapping) }}" onsubmit="return confirm('Remove this package Price mapping? Existing subscriptions will not be cancelled.')">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                      </form>
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="6" class="text-center text-muted py-4">No Stripe recurring Price mappings configured.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header fw-bold">Recent Stripe Subscriptions</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr><th>Customer</th><th>Package</th><th>Subscription</th><th>Status</th><th>Current Period End</th><th>Cancellation</th><th>Last Invoice</th></tr></thead>
          <tbody>
            @forelse($recentSubscriptions as $subscription)
              <tr>
                <td>{{ $subscription->customer_unique_id }}</td>
                <td>{{ $subscription->package?->package ?? '—' }}</td>
                <td><code>{{ $subscription->stripe_subscription_id ?: $subscription->checkout_session_id ?: 'Checkout pending' }}</code></td>
                <td><span class="badge {{ in_array($subscription->status,['active','trialing'])?'bg-success':(in_array($subscription->status,['past_due','incomplete'])?'bg-warning text-dark':'bg-secondary') }}">{{ str_replace('_',' ',$subscription->status) }}</span></td>
                <td>{{ $subscription->current_period_end?->format('d M Y H:i') ?? '—' }}</td>
                <td>{{ $subscription->cancel_at_period_end?'At period end':'—' }}</td>
                <td><code>{{ $subscription->last_invoice_id ?: '—' }}</code></td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted py-4">No Stripe subscriptions received yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  @elseif($tab==='methods')
    <div class="row g-3"><div class="col-lg-4"><div class="card"><div class="card-header fw-bold">Add Payment Method</div><div class="card-body"><form method="post" action="{{ route('mobile-banking.methods.save') }}">@csrf<div class="mb-2"><label class="form-label">Method Name</label><input required name="name" class="form-control" placeholder="bKash / Nagad / Cash"></div><div class="mb-2"><label class="form-label">Code</label><input required name="code" class="form-control" placeholder="bkash"></div><div class="form-check"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="defaultMethod"><label class="form-check-label" for="defaultMethod">Default method</label></div><button class="btn btn-primary mt-3">Save Method</button></form></div></div></div><div class="col-lg-8"><div class="card"><div class="card-header fw-bold">Payment Methods</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Name</th><th>Code</th><th>Default</th><th>Status</th></tr></thead><tbody>@foreach($methods as $m)<tr><td>{{ $m['name'] }}</td><td><code>{{ $m['code'] }}</code></td><td>{{ !empty($m['default'])?'Yes':'—' }}</td><td><span class="badge bg-success">{{ !empty($m['active'])?'Active':'Disabled' }}</span></td></tr>@endforeach</tbody></table></div></div></div></div>
  @else
    <div class="card"><div class="card-header fw-bold">MFS / Sync Settings</div><div class="card-body"><form method="post" action="{{ route('mobile-banking.settings.update') }}">@csrf<div class="row g-3"><div class="col-md-5"><label class="form-label">Default Provider</label><select name="default_provider" class="form-select"><option value="bkash" @selected($settings['default_provider']==='bkash')>bKash</option><option value="nagad" @selected($settings['default_provider']==='nagad')>Nagad</option><option value="rocket" @selected($settings['default_provider']==='rocket')>Rocket</option><option value="upay" @selected($settings['default_provider']==='upay')>Upay</option></select></div><div class="col-md-7"><label class="form-label">Account Number</label><input name="default_number" value="{{ $settings['default_number'] }}" class="form-control"></div></div><hr><div class="d-flex flex-wrap gap-2"><span class="badge {{ $settings['bkash_enabled']?'bg-success':'bg-secondary' }}">bKash {{ $settings['bkash_enabled']?'Enabled':'Disabled' }}</span><span class="badge {{ $settings['nagad_enabled']?'bg-success':'bg-secondary' }}">Nagad {{ $settings['nagad_enabled']?'Enabled':'Disabled' }}</span><span class="badge {{ $settings['ssl_enabled']?'bg-success':'bg-secondary' }}">SSLCommerz {{ $settings['ssl_enabled']?'Enabled':'Disabled' }}</span></div><button class="btn btn-primary mt-3"><i class="bi bi-save me-1"></i>Save Sync Settings</button></form></div></div>
  @endif
</div>
</x-app-layout>
