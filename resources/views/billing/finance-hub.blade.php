<x-app-layout>
    <x-slot name="header">Billing & Finance</x-slot>

    @push('styles')
    <style>
        .finance-dashboard{--fin-ink:#172554;--fin-muted:#64748b;--fin-line:#e7edf5;--fin-bg:#f5f7fb;color:#172033}
        .finance-dashboard .finance-hero{position:relative;overflow:hidden;border-radius:24px;padding:clamp(1.35rem,3vw,2.25rem);background:linear-gradient(120deg,#111c3b 0%,#183a69 54%,#126b78 100%);color:#fff;box-shadow:0 18px 42px rgba(15,35,76,.16)}
        .finance-dashboard .finance-hero:before,.finance-dashboard .finance-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none}
        .finance-dashboard .finance-hero:before{width:290px;height:290px;right:-35px;top:-150px;border:1px solid rgba(255,255,255,.18);box-shadow:0 0 0 28px rgba(255,255,255,.035),0 0 0 58px rgba(255,255,255,.025)}
        .finance-dashboard .finance-hero:after{width:150px;height:150px;right:21%;bottom:-118px;background:rgba(95,220,201,.13);filter:blur(2px)}
        .finance-dashboard .finance-hero-content{position:relative;z-index:1}
        .finance-dashboard .finance-overline{font-size:.72rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:#a6e8e4}
        .finance-dashboard .finance-hero h2{font-size:clamp(1.7rem,3vw,2.45rem);font-weight:800;letter-spacing:-.035em;color:#fff;margin:.45rem 0 .5rem}
        .finance-dashboard .finance-hero p{color:rgba(255,255,255,.75);max-width:660px;margin:0}
        .finance-dashboard .finance-hero-date{display:inline-flex;align-items:center;gap:.5rem;color:#e4f4ff;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);padding:.55rem .8rem;border-radius:12px;font-size:.82rem}
        .finance-dashboard .btn-finance-light{border:0;background:#fff;color:#19345a;font-weight:750;border-radius:11px;padding:.7rem 1rem}
        .finance-dashboard .btn-finance-glass{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.28);color:#fff;font-weight:700;border-radius:11px;padding:.7rem 1rem}
        .finance-dashboard .finance-section-head{display:flex;justify-content:space-between;align-items:end;gap:1rem;margin:1.7rem 0 .85rem}
        .finance-dashboard .finance-section-head h3{font-size:1.05rem;font-weight:800;letter-spacing:-.02em;margin:0;color:#182747}
        .finance-dashboard .finance-section-head p{font-size:.82rem;color:var(--fin-muted);margin:.25rem 0 0}
        .finance-dashboard .finance-stat{height:100%;border:1px solid var(--fin-line);border-radius:18px;background:#fff;padding:1.15rem;box-shadow:0 5px 18px rgba(21,41,74,.035);transition:transform .18s ease,box-shadow .18s ease}
        .finance-dashboard .finance-stat:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(21,41,74,.08)}
        .finance-dashboard .finance-stat-top{display:flex;justify-content:space-between;align-items:center;gap:.8rem}
        .finance-dashboard .finance-stat-label{font-size:.78rem;font-weight:750;color:#65738b}
        .finance-dashboard .finance-stat-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:13px;font-size:1.2rem;flex:none}
        .finance-dashboard .finance-stat-value{font-size:clamp(1.35rem,2vw,1.8rem);font-weight:850;letter-spacing:-.04em;color:#192947;margin:.8rem 0 .25rem;overflow-wrap:anywhere}
        .finance-dashboard .finance-stat-note{font-size:.75rem;color:#8290a5}
        .finance-dashboard .finance-icon-teal{background:#e3f8f4;color:#087e74}.finance-dashboard .finance-icon-blue{background:#eaf1ff;color:#315bc6}
        .finance-dashboard .finance-icon-amber{background:#fff4dc;color:#b77905}.finance-dashboard .finance-icon-red{background:#ffebed;color:#cf3b52}
        .finance-dashboard .finance-icon-violet{background:#f1eaff;color:#7648ce}.finance-dashboard .finance-icon-green{background:#e4f8ea;color:#19834b}
        .finance-dashboard .finance-panel{height:100%;border:1px solid var(--fin-line);border-radius:19px;background:#fff;box-shadow:0 5px 18px rgba(21,41,74,.035);overflow:hidden}
        .finance-dashboard .finance-panel-head{padding:1.1rem 1.2rem;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center;gap:1rem}
        .finance-dashboard .finance-panel-head h3{font-size:.98rem;font-weight:800;color:#1b2b4a;margin:0}
        .finance-dashboard .finance-panel-head p{font-size:.75rem;color:#8995a8;margin:.25rem 0 0}
        .finance-dashboard .finance-panel-body{padding:1.1rem 1.2rem}
        .finance-dashboard .finance-link{font-size:.79rem;font-weight:750;text-decoration:none;color:#315bc6;white-space:nowrap}
        .finance-dashboard .finance-link:hover{text-decoration:underline}
        .finance-dashboard .finance-trend-chart{height:205px;display:flex;align-items:stretch;gap:.7rem;padding:.4rem .1rem 0}
        .finance-dashboard .finance-month-group{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:end;gap:.6rem}
        .finance-dashboard .finance-bars{height:164px;width:100%;display:flex;align-items:end;justify-content:center;gap:4px;border-bottom:1px solid #edf1f6;padding:0 3px}
        .finance-dashboard .finance-bar{width:min(26px,42%);min-height:3px;border-radius:6px 6px 2px 2px;transition:opacity .15s}
        .finance-dashboard .finance-bar:hover{opacity:.72}
        .finance-dashboard .finance-bar-revenue{background:linear-gradient(180deg,#48cbb8,#138f9b)}
        .finance-dashboard .finance-bar-expenses{background:linear-gradient(180deg,#a9b9d8,#647ca7)}
        .finance-dashboard .finance-month-label{font-size:.71rem;color:#718097;font-weight:700}
        .finance-dashboard .finance-legend{display:flex;gap:1rem;flex-wrap:wrap;font-size:.75rem;color:#687991}
        .finance-dashboard .finance-legend span{display:inline-flex;gap:.4rem;align-items:center}
        .finance-dashboard .finance-legend i{width:9px;height:9px;border-radius:3px;display:inline-block}
        .finance-dashboard .finance-method{margin-bottom:1rem}.finance-dashboard .finance-method:last-child{margin-bottom:0}
        .finance-dashboard .finance-method-head{display:flex;justify-content:space-between;gap:.75rem;font-size:.8rem;margin-bottom:.4rem}
        .finance-dashboard .finance-method-head strong{color:#213251}.finance-dashboard .finance-method-head span{color:#75839a}
        .finance-dashboard .finance-progress{height:7px;background:#eef2f7;border-radius:99px;overflow:hidden}
        .finance-dashboard .finance-progress span{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#4d6ee8,#13a89a)}
        .finance-dashboard .finance-mini-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}
        .finance-dashboard .finance-mini-metric{background:#f7f9fc;border:1px solid #edf1f6;border-radius:13px;padding:.85rem}
        .finance-dashboard .finance-mini-metric span{display:block;font-size:.72rem;color:#6c7b92;margin-bottom:.3rem}
        .finance-dashboard .finance-mini-metric strong{font-size:1rem;color:#1b2d4e;font-weight:850}
        .finance-dashboard .finance-table{width:100%;border-collapse:separate;border-spacing:0}
        .finance-dashboard .finance-table th{font-size:.69rem;text-transform:uppercase;letter-spacing:.04em;color:#7d8aa0;background:#fafbfd;padding:.75rem .8rem;border-bottom:1px solid #edf1f6;white-space:nowrap}
        .finance-dashboard .finance-table td{font-size:.79rem;color:#33425e;padding:.8rem;border-bottom:1px solid #f0f3f8;vertical-align:middle}
        .finance-dashboard .finance-table tbody tr:last-child td{border-bottom:0}
        .finance-dashboard .finance-table tbody tr:hover td{background:#fbfcff}
        .finance-dashboard .finance-customer-name{font-weight:750;color:#213252}
        .finance-dashboard .finance-subtext{font-size:.7rem;color:#8a97aa;margin-top:.15rem}
        .finance-dashboard .finance-amount{font-weight:800;color:#1d3154;white-space:nowrap}
        .finance-dashboard .finance-negative{color:#c43f52}
        .finance-dashboard .finance-status{display:inline-flex;align-items:center;border-radius:99px;padding:.25rem .55rem;font-size:.68rem;font-weight:800;background:#edf7f1;color:#24804d}
        .finance-dashboard .finance-status-muted{background:#f1f4f8;color:#64748b}
        .finance-dashboard .finance-empty{text-align:center;color:#8996aa;padding:2rem 1rem;font-size:.83rem}
        .finance-dashboard .finance-shortcut{height:100%;display:flex;align-items:center;gap:.85rem;padding:1rem;border:1px solid var(--fin-line);background:#fff;border-radius:15px;text-decoration:none;transition:transform .18s ease,border-color .18s ease}
        .finance-dashboard .finance-shortcut:hover{transform:translateY(-2px);border-color:#a8baf7}
        .finance-dashboard .finance-shortcut-icon{width:40px;height:40px;border-radius:12px;background:#edf3ff;color:#315bc6;display:grid;place-items:center;flex:none;font-size:1.12rem}
        .finance-dashboard .finance-shortcut strong{font-size:.84rem;color:#213252;display:block}.finance-dashboard .finance-shortcut small{font-size:.72rem;color:#7b8aa1;display:block;margin-top:.2rem}
        .finance-dashboard .finance-footnote{font-size:.73rem;color:#8290a5}
        @media(max-width:767.98px){.finance-dashboard .finance-hero{border-radius:18px}.finance-dashboard .finance-stat{padding:.95rem}.finance-dashboard .finance-stat-value{font-size:1.25rem}.finance-dashboard .finance-mini-metrics{grid-template-columns:1fr}.finance-dashboard .finance-trend-chart{gap:.35rem}.finance-dashboard .finance-panel-head,.finance-dashboard .finance-panel-body{padding:.9rem}.finance-dashboard .finance-table th,.finance-dashboard .finance-table td{padding:.65rem}.finance-dashboard .finance-section-head{align-items:start;flex-direction:column}}
        @media(prefers-reduced-motion:reduce){.finance-dashboard *{transition:none!important}}
    </style>
    @endpush

    @php
        $todayTotal = (float) $collectionToday + (float) $hotspotToday;
        $monthMethodTotal = max(1, (float) $paymentMethods->sum('total'));
        $currencyLabel = $currency ?: '৳';
        $formatMoney = fn ($amount) => number_format((float) $amount, 2).' '.$currencyLabel;
    @endphp

    <div class="finance-dashboard container-fluid px-3 px-xl-4 pb-4">
        <section class="finance-hero mb-4">
            <div class="finance-hero-content">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="finance-overline"><i class="bi bi-bar-chart-line-fill me-2"></i>Finance Control Center</div>
                        <h2>Billing &amp; Finance</h2>
                        <p>Collection performance, outstanding balances, operational expenses and financial health in one independent workspace.</p>
                    </div>
                    <div class="finance-hero-date"><i class="bi bi-calendar3"></i>{{ $now->format('l, d M Y') }}</div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a href="{{ route('payment-collection') }}" class="btn btn-finance-light"><i class="bi bi-cash-coin me-2"></i>Open Collection Desk</a>
                    <a href="{{ route('income-summary') }}" class="btn btn-finance-glass"><i class="bi bi-graph-up-arrow me-2"></i>Income Report</a>
                </div>
            </div>
        </section>

        <div class="row g-3 mb-2">
            <div class="col-6 col-xl-3"><div class="finance-stat">
                <div class="finance-stat-top"><span class="finance-stat-label">Collected Today</span><span class="finance-stat-icon finance-icon-teal"><i class="bi bi-wallet2"></i></span></div>
                <div class="finance-stat-value">{{ $formatMoney($todayTotal) }}</div>
                <div class="finance-stat-note">Collection ledger + hotspot sales</div>
            </div></div>
            <div class="col-6 col-xl-3"><div class="finance-stat">
                <div class="finance-stat-top"><span class="finance-stat-label">This Month's Revenue</span><span class="finance-stat-icon finance-icon-blue"><i class="bi bi-graph-up-arrow"></i></span></div>
                <div class="finance-stat-value">{{ $formatMoney($monthRevenue) }}</div>
                <div class="finance-stat-note">Collection and hotspot sales</div>
            </div></div>
            <div class="col-6 col-xl-3"><div class="finance-stat">
                <div class="finance-stat-top"><span class="finance-stat-label">Outstanding Due</span><span class="finance-stat-icon finance-icon-red"><i class="bi bi-exclamation-circle"></i></span></div>
                <div class="finance-stat-value">{{ $formatMoney($outstandingDue) }}</div>
                <div class="finance-stat-note">{{ number_format($dueCustomersCount) }} customers with due balance</div>
            </div></div>
            <div class="col-6 col-xl-3"><div class="finance-stat">
                <div class="finance-stat-top"><span class="finance-stat-label">Monthly Recurring Rent</span><span class="finance-stat-icon finance-icon-violet"><i class="bi bi-calendar-check"></i></span></div>
                <div class="finance-stat-value">{{ $formatMoney($monthlyRent) }}</div>
                <div class="finance-stat-note">{{ number_format($activeCustomersCount) }} active customers</div>
            </div></div>
        </div>

        <div class="row g-3 mt-1 mb-3">
            <div class="col-12 col-lg-4"><div class="finance-stat">
                <div class="finance-stat-top"><span class="finance-stat-label">Operating Snapshot · This Month</span><span class="finance-stat-icon finance-icon-green"><i class="bi bi-activity"></i></span></div>
                <div class="finance-stat-value {{ $monthNet < 0 ? 'finance-negative' : '' }}">{{ $formatMoney($monthNet) }}</div>
                <div class="finance-stat-note">Revenue minus recorded expenses and reseller commissions</div>
            </div></div>
            <div class="col-6 col-lg-4"><div class="finance-stat">
                <div class="finance-stat-top"><span class="finance-stat-label">Operating Expenses</span><span class="finance-stat-icon finance-icon-amber"><i class="bi bi-receipt"></i></span></div>
                <div class="finance-stat-value">{{ $formatMoney($expenseMonth) }}</div>
                <div class="finance-stat-note">Expenses recorded this month</div>
            </div></div>
            <div class="col-6 col-lg-4"><div class="finance-stat">
                <div class="finance-stat-top"><span class="finance-stat-label">Year-to-date Revenue</span><span class="finance-stat-icon finance-icon-blue"><i class="bi bi-calendar2-range"></i></span></div>
                <div class="finance-stat-value">{{ $formatMoney($yearRevenue) }}</div>
                <div class="finance-stat-note">Recorded collections and hotspot sales</div>
            </div></div>
        </div>

        <div class="finance-section-head">
            <div><h3>Financial performance</h3><p>Six-month trend based on saved collection, hotspot, expense and commission records.</p></div>
            <a class="finance-link" href="{{ route('admin.profit-summary') }}">Full Profit &amp; Loss <i class="bi bi-arrow-up-right ms-1"></i></a>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
                <section class="finance-panel">
                    <div class="finance-panel-head"><div><h3>Revenue vs. Costs</h3><p>Monthly comparison · {{ $formatMoney($monthNet) }} estimated net for the current month</p></div><span class="badge rounded-pill text-bg-light border">6 months</span></div>
                    <div class="finance-panel-body">
                        <div class="finance-legend mb-3"><span><i style="background:#138f9b"></i>Revenue</span><span><i style="background:#647ca7"></i>Expenses + commissions</span></div>
                        <div class="finance-trend-chart" role="img" aria-label="Six month bar chart comparing revenue and expenses">
                            @foreach($trend as $item)
                                <div class="finance-month-group">
                                    <div class="finance-bars">
                                        <div class="finance-bar finance-bar-revenue" style="height:{{ max(3, (int) round($item['revenue'] / $trendMax * 100)) }}%" title="{{ $item['label'] }} revenue: {{ $formatMoney($item['revenue']) }}"></div>
                                        <div class="finance-bar finance-bar-expenses" style="height:{{ max(3, (int) round($item['expenses'] / $trendMax * 100)) }}%" title="{{ $item['label'] }} expenses: {{ $formatMoney($item['expenses']) }}"></div>
                                    </div>
                                    <span class="finance-month-label">{{ $item['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="finance-mini-metrics mt-4">
                            <div class="finance-mini-metric"><span>Collection + Hotspot · This month</span><strong>{{ $formatMoney($monthRevenue) }}</strong></div>
                            <div class="finance-mini-metric"><span>Expenses + Commission · This month</span><strong>{{ $formatMoney($monthCosts) }}</strong></div>
                            <div class="finance-mini-metric"><span>Collection-only · This month</span><strong>{{ $formatMoney($collectionMonth) }}</strong></div>
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-12 col-xl-4">
                <section class="finance-panel">
                    <div class="finance-panel-head"><div><h3>Payment methods</h3><p>Collection ledger · current month</p></div></div>
                    <div class="finance-panel-body">
                        @forelse($paymentMethods as $method)
                            <div class="finance-method">
                                <div class="finance-method-head"><strong>{{ ucfirst(str_replace(['_', '-'], ' ', $method->method ?: 'Other')) }}</strong><span>{{ $formatMoney($method->total) }}</span></div>
                                <div class="finance-progress"><span style="width:{{ min(100, max(0, (float) $method->total / $monthMethodTotal * 100)) }}%"></span></div>
                            </div>
                        @empty
                            <div class="finance-empty"><i class="bi bi-wallet2 fs-3 d-block mb-2"></i>No collection ledger entries recorded this month.</div>
                        @endforelse
                        <div class="border-top pt-3 mt-3 d-flex justify-content-between align-items-center"><span class="small text-muted">Hotspot sales this month</span><strong class="finance-amount">{{ $formatMoney($hotspotMonth) }}</strong></div>
                        <div class="border-top pt-3 mt-3"><a href="{{ route('income-summary') }}" class="finance-link">View income breakdown <i class="bi bi-arrow-right ms-1"></i></a></div>
                    </div>
                </section>
            </div>
        </div>

        <div class="finance-section-head">
            <div><h3>Collections &amp; receivables</h3><p>Latest collection records and customers needing due follow-up.</p></div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-7">
                <section class="finance-panel">
                    <div class="finance-panel-head"><div><h3>Recent collections</h3><p>Latest records for the current month</p></div><a class="finance-link" href="{{ route('income-summary') }}">All collections <i class="bi bi-arrow-right ms-1"></i></a></div>
                    <div class="table-responsive">
                        <table class="finance-table">
                            <thead><tr><th>Customer</th><th>Date / Method</th><th class="text-end">Amount</th></tr></thead>
                            <tbody>
                            @forelse($recentCollections as $row)
                                <tr>
                                    <td><div class="finance-customer-name">{{ $row->customer?->customer_name ?: 'Unknown customer' }}</div><div class="finance-subtext">{{ $row->customer_collection_unique_id ?: 'No customer ID' }}</div></td>
                                    <td><div>{{ $row->collection_date ? \Illuminate\Support\Carbon::parse($row->collection_date)->format('d M Y') : '—' }}</div><div class="finance-subtext">{{ ucfirst(str_replace(['_', '-'], ' ', $row->payment_method ?: $row->payment_type ?: 'Other')) }}</div></td>
                                    <td class="text-end"><span class="finance-amount">{{ $formatMoney($row->collection_amount) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="3"><div class="finance-empty">No collection records found for this month.</div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <div class="col-12 col-xl-5">
                <section class="finance-panel">
                    <div class="finance-panel-head"><div><h3>Customers with highest due</h3><p>Largest outstanding balances first</p></div><a class="finance-link" href="{{ route('broadband-due-customers') }}">View due list <i class="bi bi-arrow-right ms-1"></i></a></div>
                    <div class="table-responsive">
                        <table class="finance-table">
                            <thead><tr><th>Customer</th><th class="text-end">Outstanding</th></tr></thead>
                            <tbody>
                            @forelse($topDueCustomers as $billing)
                                <tr>
                                    <td><div class="finance-customer-name">{{ $billing->customer?->customer_name ?: 'Unknown customer' }}</div><div class="finance-subtext">{{ $billing->customer_bill_unique_id }}</div></td>
                                    <td class="text-end"><span class="finance-amount finance-negative">{{ $formatMoney($billing->due_amount) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="2"><div class="finance-empty">No outstanding due records found.</div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <div class="finance-section-head">
            <div><h3>Finance workspace</h3><p>Shortcuts to the tools used for daily billing administration.</p></div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('payment-collection') }}"><span class="finance-shortcut-icon"><i class="bi bi-cash-coin"></i></span><span><strong>Payment Collection</strong><small>Record a customer payment</small></span></a></div>
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('ledger-summary') }}"><span class="finance-shortcut-icon"><i class="bi bi-journal-text"></i></span><span><strong>Ledger Summary</strong><small>Review credits and debits</small></span></a></div>
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('admin.expenses') }}"><span class="finance-shortcut-icon"><i class="bi bi-receipt-cutoff"></i></span><span><strong>Expenses</strong><small>Review operating costs</small></span></a></div>
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('extra-charges') }}"><span class="finance-shortcut-icon"><i class="bi bi-plus-circle"></i></span><span><strong>Extra Charges</strong><small>Customer billing additions</small></span></a></div>
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('admin.profit-summary') }}"><span class="finance-shortcut-icon"><i class="bi bi-pie-chart"></i></span><span><strong>Profit &amp; Loss</strong><small>Revenue minus costs</small></span></a></div>
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('payment-invoice') }}"><span class="finance-shortcut-icon"><i class="bi bi-file-earmark-text"></i></span><span><strong>Payment Invoice</strong><small>Invoice lookup and print</small></span></a></div>
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('broadband-due-customers') }}"><span class="finance-shortcut-icon"><i class="bi bi-bell"></i></span><span><strong>Due Customers</strong><small>Follow up outstanding bills</small></span></a></div>
            <div class="col-6 col-xl-3"><a class="finance-shortcut" href="{{ route('income-summary') }}"><span class="finance-shortcut-icon"><i class="bi bi-graph-up"></i></span><span><strong>Income &amp; Collection</strong><small>Filter collection by date</small></span></a></div>
        </div>

        @if($recentExpenses->isNotEmpty())
            <div class="finance-section-head">
                <div><h3>Recent expenses</h3><p>Recorded expenses for {{ $now->format('F Y') }}.</p></div>
                <a class="finance-link" href="{{ route('admin.expenses') }}">Expense register <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
            <section class="finance-panel">
                <div class="table-responsive">
                    <table class="finance-table">
                        <thead><tr><th>Date</th><th>Expense</th><th>Category</th><th>Entered by</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        @foreach($recentExpenses as $expense)
                            <tr>
                                <td>{{ $expense->expense_date?->format('d M Y') ?: '—' }}</td>
                                <td><div class="finance-customer-name">{{ $expense->title ?: 'Expense' }}</div><div class="finance-subtext">{{ $expense->reference_no ?: $expense->description }}</div></td>
                                <td>{{ $expense->category_label }}</td>
                                <td>{{ $expense->addedBy?->name ?: '—' }}</td>
                                <td class="text-end"><span class="finance-amount">{{ $formatMoney($expense->amount) }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <p class="finance-footnote mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Figures are calculated from the saved collection ledger, hotspot sales, billing balances, expense register and reseller commission records. The monthly operating snapshot is revenue minus recorded expenses and commissions; it is not an audited accounting statement.</p>
    </div>
</x-app-layout>