<x-app-layout>
<x-slot name="header">Ledger Summary</x-slot>

<form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="{{ $from }}"></div>
    <div class="col-md-3"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="{{ $to }}"></div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Apply</button></div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body"><small>Posted Credit (paid only)</small><h3 class="text-success">৳{{ number_format($creditTotal, 2) }}</h3></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><small>Debit</small><h3 class="text-danger">৳{{ number_format($debitTotal, 2) }}</h3></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><small>Net</small><h3>৳{{ number_format($creditTotal - $debitTotal, 2) }}</h3></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h5>Posted Credit Entries</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Customer</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                @forelse($credits as $r)
                    <tr>
                        <td>{{ optional($r->collection_date)->format('Y-m-d') ?? $r->collection_date }}</td>
                        <td>{{ optional($r->customer)->customer_name ?? $r->customer_collection_unique_id }}</td>
                        <td>{{ $r->payment_method ?: '—' }}</td>
                        <td>{{ $r->transaction_id ?: $r->invoice_no ?: '—' }}</td>
                        <td class="text-end {{ (float) $r->collection_amount < 0 ? 'text-danger' : 'text-success' }}">৳{{ number_format((float) $r->collection_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">No posted credit entry found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <small class="text-muted">Up to 100 entries shown. Posted Credit total includes all matching paid entries in the selected date range.</small>
    </div>
</div>

@if($adjustments->isNotEmpty())
    <div class="card mb-3">
        <div class="card-body">
            <h5>Non-posted adjustments</h5>
            <p class="small text-muted">These audit entries do not contribute to Posted Credit or Net.</p>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Customer</th><th>Status</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @foreach($adjustments as $r)
                        @php($status = strtolower(trim((string) $r->payment_status)))
                        <tr>
                            <td>{{ optional($r->collection_date)->format('Y-m-d') ?? $r->collection_date }}</td>
                            <td>{{ optional($r->customer)->customer_name ?? $r->customer_collection_unique_id }}</td>
                            <td>{{ $status !== '' ? ucfirst($status) : 'Unspecified' }}</td>
                            <td>{{ $r->payment_type ?: '—' }}</td>
                            <td class="text-end {{ (float) $r->collection_amount < 0 ? 'text-danger' : 'text-muted' }}">৳{{ number_format((float) $r->collection_amount, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <small class="text-muted">Adjustment amount (audit only): ৳{{ number_format($adjustmentTotal, 2) }}</small>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-body">
        <h5>Debit Entries</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Category</th><th>Title</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                @forelse($debits as $r)
                    <tr>
                        <td>{{ optional($r->expense_date)->format('Y-m-d') ?? $r->expense_date }}</td>
                        <td>{{ $r->category_label }}</td>
                        <td>{{ $r->title }}</td>
                        <td class="text-end text-danger">৳{{ number_format((float) $r->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">No ledger debit entry found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <small class="text-muted">Up to 100 entries shown. Debit total includes all matching expenses in the selected date range.</small>
    </div>
</div>
</x-app-layout>
