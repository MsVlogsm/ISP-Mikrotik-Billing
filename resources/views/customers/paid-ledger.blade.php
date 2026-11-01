<x-app-layout>
    <x-slot name="header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><span class="text-muted small">CUSTOMER BILLING</span><h2 class="h4 mb-0 fw-bold">Paid Ledger</h2></div>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('customer.details', encrypt($customer->customer_unique_id)) }}"><i class="bi bi-arrow-left me-1"></i>Back to Customer Profile</a>
        </div>
    </x-slot>
    <div class="container-fluid pb-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div><div class="small text-muted">CUSTOMER</div><h3 class="h5 mb-1">{{ $customer->customer_name ?: 'Unnamed Customer' }}</h3><div class="text-muted small">{{ $customer->displayCustomerUniqueId() }} · {{ $customer->pppUser?->username ?: 'No PPPoE username' }}</div></div>
                <div class="text-end"><div class="small text-muted">Ledger Periods</div><div class="h4 mb-0">{{ $paidLedger->count() }}</div></div>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Total Billed</div><div class="h5 mb-0">{{ number_format($totals['bill'],2) }} ৳</div></div></div></div>
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Received</div><div class="h5 mb-0 text-success">{{ number_format($totals['received'],2) }} ৳</div></div></div></div>
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Return / Refund</div><div class="h5 mb-0 text-danger">{{ number_format($totals['returned'],2) }} ৳</div></div></div></div>
            <div class="col-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Net Paid</div><div class="h5 mb-0">{{ number_format($totals['net_paid'],2) }} ৳</div></div></div></div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2"><strong>Month-wise Paid Ledger</strong><span class="small text-muted">Based on monthly bill summaries and confirmed paid transactions</span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Month</th><th class="text-end">Bill Amount</th><th class="text-end">Received</th><th class="text-end">Return / Refund</th><th class="text-end">Net Paid</th><th class="text-end">Due / Advance</th><th class="text-center">Transactions</th></tr></thead>
                    <tbody>
                    @forelse($paidLedger as $row)
                        <tr>
                            <td class="fw-semibold">{{ $row['month']->format('F Y') }}</td>
                            <td class="text-end">{{ $row['has_bill'] ? number_format($row['bill'],2).' ৳' : '—' }}</td>
                            <td class="text-end text-success">{{ number_format($row['received'],2) }} ৳</td>
                            <td class="text-end text-danger">{{ number_format($row['returned'],2) }} ৳</td>
                            <td class="text-end fw-semibold">{{ number_format($row['net_paid'],2) }} ৳</td>
                            <td class="text-end {{ $row['balance'] === null ? 'text-muted' : ($row['balance'] > 0 ? 'text-danger' : ($row['balance'] < 0 ? 'text-success' : '')) }}">@if($row['balance'] === null) — @elseif($row['balance'] > 0) {{ number_format($row['balance'],2) }} ৳ Due @elseif($row['balance'] < 0) {{ number_format(abs($row['balance']),2) }} ৳ Advance @else 0.00 ৳ @endif</td>
                            <td class="text-center">{{ $row['transactions'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-5">No monthly billing or paid transaction history found for this customer.</td></tr>
                    @endforelse
                    </tbody>
                    @if($paidLedger->isNotEmpty())
                        <tfoot class="table-light"><tr class="fw-bold"><td>Total</td><td class="text-end">{{ number_format($totals['bill'],2) }} ৳</td><td class="text-end">{{ number_format($totals['received'],2) }} ৳</td><td class="text-end">{{ number_format($totals['returned'],2) }} ৳</td><td class="text-end">{{ number_format($totals['net_paid'],2) }} ৳</td><td></td><td class="text-center">{{ $totals['transactions'] }}</td></tr></tfoot>
                    @endif
                </table>
            </div>
            <div class="card-footer bg-transparent small text-muted">A month with no saved bill summary may still appear when a payment was recorded. In that case, Bill Amount and Due / Advance are shown as — rather than estimated.</div>
        </div>
    </div>
</x-app-layout>
