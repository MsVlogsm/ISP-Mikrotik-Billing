<x-app-layout>
<x-slot name="header">Income &amp; Collection</x-slot>

<form class="row g-2 mb-3" method="get">
    <div class="col-md-3">
        <label class="form-label">From</label>
        <input class="form-control" type="date" name="from" value="{{ $from }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">To</label>
        <input class="form-control" type="date" name="to" value="{{ $to }}">
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Apply</button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <small>Posted Income (paid collections)</small>
        <h3>৳{{ number_format((float) $rows->sum('collection_amount'), 2) }}</h3>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <small>Own Customer Income</small>
        <h3>৳{{ number_format((float) $own, 2) }}</h3>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <small>Reseller Income</small>
        <h3>৳{{ number_format((float) $reseller, 2) }}</h3>
    </div></div></div>
</div>

<div class="card">
    <div class="card-body">
        <h5>Posted Collection Transactions</h5>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr>
                    <th>Date</th><th>Customer</th><th>Status</th><th>Method</th><th>Reference</th>
                    <th class="text-end">Amount</th>
                </tr></thead>
                <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td>{{ optional($r->collection_date)->format('Y-m-d') ?? $r->collection_date }}</td>
                        <td>{{ optional($r->customer)->customer_name ?? $r->customer_collection_unique_id }}</td>
                        <td><span class="badge bg-success-subtle text-success">Paid</span></td>
                        <td>{{ $r->payment_method ?: $r->payment_type ?: '—' }}</td>
                        <td>{{ $r->transaction_id ?: $r->invoice_no ?: '—' }}</td>
                        <td class="text-end {{ (float) $r->collection_amount < 0 ? 'text-danger' : '' }}">
                            ৳{{ number_format((float) $r->collection_amount, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No paid collection found for this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($adjustments->isNotEmpty())
    <div class="card mt-3">
        <div class="card-body">
            <h5>Non-posted adjustments</h5>
            <p class="small text-muted">Reversed, reconciled, pending and unspecified-status records are shown for audit. They are excluded from posted income totals.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr>
                        <th>Date</th><th>Customer</th><th>Status</th><th>Type</th><th>Method / Reference</th>
                        <th class="text-end">Amount</th>
                    </tr></thead>
                    <tbody>
                    @foreach($adjustments as $r)
                        @php($status = strtolower(trim((string) $r->payment_status)))
                        <tr>
                            <td>{{ optional($r->collection_date)->format('Y-m-d') ?? $r->collection_date }}</td>
                            <td>{{ optional($r->customer)->customer_name ?? $r->customer_collection_unique_id }}</td>
                            <td>
                                <span class="badge {{ $status === 'reversed' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-emphasis' }}">
                                    {{ $status !== '' ? ucfirst($status) : 'Unspecified' }}
                                </span>
                            </td>
                            <td>{{ $r->payment_type ?: '—' }}</td>
                            <td>{{ $r->payment_method ?: '—' }} / {{ $r->transaction_id ?: $r->invoice_no ?: '—' }}</td>
                            <td class="text-end {{ (float) $r->collection_amount < 0 ? 'text-danger' : 'text-muted' }}">
                                ৳{{ number_format((float) $r->collection_amount, 2) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
</x-app-layout>
