<div>
    @if(session('onu_sync_message'))
        <div class="alert alert-{{ session('onu_sync_success') ? 'success' : 'warning' }} mx-sm-0 mx-md-3 mt-3 mb-0">
            <strong><i class="bi bi-arrow-repeat me-1"></i> ONU Sync:</strong>
            <pre class="mb-0 mt-1 text-wrap">{{ session('onu_sync_message') }}</pre>
        </div>
    @endif
    <section class="customer-page-header mx-sm-0 mx-md-3 mt-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <div class="customer-eyebrow"><i class="bi bi-people-fill me-1"></i>{{ __('CUSTOMER MANAGEMENT') }}</div>
                <h1 class="customer-page-title">{{ __('Customer List') }}</h1>
                <p class="customer-page-subtitle mb-0">{{ __('Manage customer profiles, connectivity, billing and service actions from one place.') }}</p>
            </div>
            <div class="customer-header-actions d-flex flex-wrap gap-2">
                @if(auth()->user()?->hasRole('Super Admin') || hasAccess(['Super Admin'], ['edit-customer']))
                    <button type="button" class="btn btn-outline-primary" onclick="openBulkClientChange()">
                        <i class="bi bi-arrow-left-right me-1"></i>{{ __('Bulk Client Copy') }}
                        <span class="badge bg-primary ms-1" id="bulk-client-selected-count">0</span>
                    </button>
                @endif
                @if(auth()->user()?->hasRole('Super Admin') || hasAccess(['Super Admin'], ['create-customer']))
                    <a href="{{ route('customer-add') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>{{ __('Add Customer') }}</a>
                @endif
            </div>
        </div>
        <div class="customer-stat-grid">
            <div class="customer-stat-card"><span class="customer-stat-icon stat-blue"><i class="bi bi-people"></i></span><div><span class="customer-stat-label">{{ __('Total Customers') }}</span><strong>{{ number_format($customerStats['total'] ?? 0) }}</strong></div></div>
            <div class="customer-stat-card"><span class="customer-stat-icon stat-green"><i class="bi bi-person-check"></i></span><div><span class="customer-stat-label">{{ __('Active') }}</span><strong>{{ number_format($customerStats['active'] ?? 0) }}</strong></div></div>
            <div class="customer-stat-card"><span class="customer-stat-icon stat-amber"><i class="bi bi-hourglass-split"></i></span><div><span class="customer-stat-label">{{ __('Pending') }}</span><strong>{{ number_format($customerStats['pending'] ?? 0) }}</strong></div></div>
            <div class="customer-stat-card"><span class="customer-stat-icon stat-red"><i class="bi bi-person-x"></i></span><div><span class="customer-stat-label">{{ __('Disabled') }}</span><strong>{{ number_format($customerStats['disabled'] ?? 0) }}</strong></div></div>
            <div class="customer-stat-card"><span class="customer-stat-icon stat-violet"><i class="bi bi-gift"></i></span><div><span class="customer-stat-label">{{ __('Free Accounts') }}</span><strong>{{ number_format($customerStats['free'] ?? 0) }}</strong></div></div>
        </div>
    </section>

    <div class="card border-0 shadow-sm mx-sm-0 mx-md-3 my-3 customer-filter-card">
        <div class="card-body p-3">
            <div class="row align-items-center">
                @if ($routers->count() > 1)
                    <div class="col-md-2">
                        <label class="small text-muted fw-bold ps-1">{{ __('Router') }}</label>
                        <select class="form-select border-0 bg-light" id="router_filter">
                            <option value="">{{ __('All Routers') }}</option>
                            @foreach($routers as $router)
                                <option value="{{ $router->router_name }}">{{ $router->router_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="{{ $routers->count() > 1 ? 'col-md-10' : 'col-md-12' }}">
                    <label class="small text-muted fw-bold ps-1 d-inline">{{ __('FILTERS') }}</label><div class="d-flex flex-nowrap align-items-center gap-0 customer-filter-toolbar d-inline-flex">
                        <div class="filter-group d-flex gap-1 overflow-auto pb-1">
                            <input type="radio" class="btn-check" name="collection" id="all_list" autocomplete="off">
                            <label class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-none fw-600" for="all_list">
                                <i class="bi bi-people-fill me-1"></i> {{ __('All Customers') }} <span class="badge bg-white text-primary fw-bold"></span>
                            </label>
                            
                            <input type="radio" class="btn-check" name="collection" id="all_active_list" autocomplete="off" checked>
                            <label class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-none fw-600" for="all_active_list">
                                <i class="bi bi-people-fill me-1"></i> {{ __('All Active') }} <span class="badge bg-white text-primary fw-bold"></span>
                            </label>

                            <input type="radio" class="btn-check" name="collection" id="collection_list" autocomplete="off">
                            <label class="btn btn-outline-success btn-sm rounded-pill px-3 shadow-none fw-600" for="collection_list">
                                <i class="bi bi-cash-stack me-1"></i> {{ __('Paid') }} <span class="badge bg-white text-success fw-bold"></span>
                            </label>

                            <input type="radio" class="btn-check" name="collection" id="without_collection_list" autocomplete="off">
                            <label class="btn btn-outline-indigo btn-sm rounded-pill px-3 shadow-none fw-600" for="without_collection_list">
                                <i class="bi bi-wallet2 me-1"></i> {{ __('Unpaid') }} <span class="badge bg-white text-indigo fw-bold"></span>
                            </label>

                            <input type="radio" class="btn-check" name="collection" id="pending_customer" autocomplete="off">
                            <label class="btn btn-outline-warning btn-sm rounded-pill px-3 shadow-none fw-600" for="pending_customer">
                                <i class="bi bi-clock-history me-1"></i> {{ __('Pending') }} <span class="badge bg-white text-warning fw-bold"></span>
                            </label>

                            <input type="radio" class="btn-check" name="collection" id="disable_customer" autocomplete="off">
                            <label class="btn btn-outline-danger btn-sm rounded-pill px-3 shadow-none fw-600" for="disable_customer">
                                <i class="bi bi-slash-circle me-1"></i> {{ __('Disabled') }} <span class="badge bg-white text-danger fw-bold"></span>
                            </label>

                            <input type="radio" class="btn-check" name="collection" id="free_customer" autocomplete="off">
                            <label class="btn btn-outline-info btn-sm rounded-pill px-3 shadow-none fw-600" for="free_customer">
                                <i class="bi bi-gift me-1"></i> {{ __('Free') }} <span class="badge bg-white text-info fw-bold"></span>
                            </label>

                            <input type="radio" class="btn-check" name="collection" id="inactive_customer" autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-none fw-600" for="inactive_customer">
                                <i class="bi bi-person-x me-1"></i> {{ __('Inactive') }} <span class="badge bg-white text-secondary fw-bold"></span>
                            </label>

                            @foreach($resellers as $reseller)
                                <input type="radio" class="btn-check" name="collection" id="reseller_{{ $reseller->id }}" data-reseller-id="{{ $reseller->id }}" autocomplete="off">
                                <label class="btn btn-outline-purple btn-sm rounded-pill px-3 shadow-none fw-600" for="reseller_{{ $reseller->id }}">
                                    <i class="bi bi-person-badge-fill me-1"></i> {{ $reseller->company ?: ($reseller->user->name ?? 'Reseller') }} <span class="badge bg-white text-purple fw-bold"></span>
                                </label>
                            @endforeach
                        </div>
                        <div class="d-flex flex-nowrap gap-1">
                            <form method="POST" action="{{ route('network-inventory.olt.sync') }}" class="d-inline" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button span').textContent='Syncing…';">
                                @csrf
                                <button type="submit" class="btn btn-outline-success btn-sm rounded-pill px-3 py-0 shadow-none fw-600" style="height: 2rem;" title="Synchronize ONU inventory and customer mappings">
                                    <i class="bi bi-arrow-repeat me-1"></i><span>Sync ONU</span>
                                </button>
                            </form>
                            <button type="button" id="reset_table" class="btn btn-light btn-outline-dark btn-sm rounded-pill px-3 py-0 shadow-none fw-600" style="height: 2rem;" title="Reset all filters and search">
                                <i class="bi bi-arrow-clockwise"></i> {{ __('Reset') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm m-sm-0 m-md-3 p-0 overflow-hidden customer-table-card">
        <div class="table-responsive bg-white px-3 pb-3" wire:ignore>
            <table class="customer-table table table-hover custom-data-table border-0 w-100" style="width:100%">
                <thead class="bg-light">
                    <tr>
                        <th class="text-center" style="width:36px;"><input type="checkbox" id="select-all-customers" class="form-check-input" title="{{ __('Select All') }}" aria-label="{{ __('Select All') }}"></th>
                        <th>{{ __('CID') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th>{{ __('Connection') }}</th>
                        <th>{{ __('Mobile') }}</th>
                        <th>{{ __('Package') }}</th>
                        <th>{{ __('Billing Date') }}</th>
                        <th class="text-end">{{ __('Balance') }}</th>
                        <th>{{ __('Expiry') }}</th>
                        <th>{{ __('Address') }}</th>
                        <th>{{ __('POP / Area') }}</th>
                        <th class="text-end">{{ __('Monthly Bill') }}</th>
                        <th class="text-center">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="text-center"></tbody>

            </table>
        </div>
    </div>

    @if($bulkClientModalOpen)
        <div class="modal fade show bulk-client-copy-modal" tabindex="-1" style="display:block; background:rgba(0,0,0,.55);" aria-modal="true" role="dialog" wire:key="bulk-client-change-modal">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1"><i class="bi bi-arrow-left-right me-2 text-primary"></i>{{ __('Bulk Client Copy') }}</h5>
                            <div class="small text-muted">{{ count($bulkClientPreview) }} {{ __('selected PPPoE customers') }}</div>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeBulkClientChange" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <form class="bulk-client-copy-form" wire:submit.prevent="submitBulkClientChange">
                        <div class="modal-body bulk-client-copy-body">
                            @if($bulkClientError)
                                <div class="alert alert-danger py-2">{{ $bulkClientError }}</div>
                            @endif

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">{{ __('1st MikroTik Router (Source)') }}</label>
                                    <select class="form-select" wire:model="bulkClientSourceRouter" required>
                                        <option value="">{{ __('Select source router') }}</option>
                                        @foreach($routers as $router)
                                            <option value="{{ $router->router_name }}">{{ $router->router_name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('Selected customers must belong to this router.') }}</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">{{ __('2nd MikroTik Router (Destination)') }}</label>
                                    <select class="form-select" wire:model.live="bulkClientTargetRouter" required>
                                        <option value="">{{ __('Select destination router') }}</option>
                                        @foreach($routers as $router)
                                            @if($router->router_name !== $bulkClientSourceRouter)
                                                <option value="{{ $router->router_name }}">{{ $router->router_name }}{{ $router->action === 'connected' ? '' : ' (Offline)' }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="alert alert-info py-2 small">
                                <strong>{{ __('Copy behavior:') }}</strong>
                                {{ __('Each destination PPP secret is first created as disabled and verified. The source router secret remains untouched, and the existing customer PPP router/profile assignment in X-Link Billing is not changed.') }}
                                <br>
                                {{ __('Billing, payments, package assignments, customer identity, ONU mappings and active source sessions remain unchanged. PPPoE server/interface settings are not changed.') }}
                            </div>

                            <div class="table-responsive border rounded">
                                <table class="table table-sm table-striped align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>{{ __('Customer ID') }}</th>
                                            <th>{{ __('Customer') }}</th>
                                            <th>{{ __('PPPoE Username') }}</th>
                                            <th>{{ __('Current Profile') }}</th>
                                            <th>{{ __('Destination Profile') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($bulkClientPreview as $client)
                                            <tr>
                                                <td class="fw-semibold">{{ $client['cid'] }}</td>
                                                <td>{{ $client['customer'] }}</td>
                                                <td><code>{{ $client['username'] }}</code></td>
                                                <td>{{ $client['profile'] ?: '—' }}</td>
                                                <td style="min-width:190px;">
                                                    <select class="form-select form-select-sm" wire:model="bulkClientProfileMap.{{ $client['cid'] }}" @disabled(empty($bulkClientProfiles))>
                                                        <option value="">{{ __('Select profile') }}</option>
                                                        @foreach($bulkClientProfiles as $profile)
                                                            <option value="{{ $profile }}">{{ $profile }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="small text-muted mt-2">{{ __('Maximum 100 customers per batch. A matching destination profile is preselected when available; review the destination profile for every customer. If a username already exists on the destination router, the entire batch is stopped before any changes.') }}</div>
                        </div>
                        <div class="modal-footer bulk-client-copy-footer">
                            <button type="button" class="btn btn-light border" wire:click="closeBulkClientChange">{{ __('Cancel') }}</button>
                            <button type="button" class="btn btn-primary" onclick="confirmBulkClientChange()" @disabled(empty($bulkClientProfiles))>
                                <i class="bi bi-arrow-left-right me-1"></i>{{ __('Copy Selected Customers') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if($editingCustomerId)
        <div class="modal fade show" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);" aria-modal="true" role="dialog" wire:key="edit-customer-modal-{{ $editingCustomerId }}">
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Edit Customer') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeEditCustomerModal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body bg-light" style="padding: 1.5rem; max-height: 85vh; overflow-y: auto;">
                        @livewire('edit-customer', ['customerId' => $editingCustomerId], key($editingCustomerId))
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" wire:click="closeEditCustomerModal">{{ __('Close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($editingBillId)
        <div class="modal fade show" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);" aria-modal="true" role="dialog" wire:key="edit-bill-modal-{{ $editingBillId }}">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5">{{ __('Update Bill') }} (<span>{{ $bill_customer_name }}</span>)</h1>
                        <button type="button" class="btn-close close" wire:click="closeBillModal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <form wire:submit.prevent="updateBill">
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-5">
                                    {{ __('Unique ID:') }} <span>{{ $bill_customer_unique_id }}</span>
                                    <br>
                                    {{ __('Customer Name:') }} <span>{{ $bill_customer_name }}</span>
                                    <br>
                                    {{ __('IP/Username:') }} <span>{{ $bill_username }}</span>
                                    <br>
                                    {{ __('Auto Disable Date:') }} <span>{{ $bill_auto_disable_date ? \Carbon\Carbon::parse($bill_auto_disable_date)->format('d-M-y') : '' }}</span>
                                    <br>
                                    <br>
                                    <br>
                                    <br>
                                </div>
                                <div class="col-7 border-start">
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Monthly Rent :') }}</span>
                                        <input type="number" min="0" step="any" wire:model.live="monthly_rent" class="form-control" required>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Additional Charge :') }}</span>
                                        <input type="number" min="0" step="any" wire:model.live="additional_charge" class="form-control" required>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Vat (%) :') }}</span>
                                        <input type="number" min="0" step="any" wire:model.live="vat" class="form-control" required>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Sub Total :') }}</span>
                                        <input type="text" class="form-control" wire:model="sub_total_amount" disabled readonly>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Previous Due :') }}</span>
                                        <input type="number" step="any" wire:model.live="previous_due" class="form-control" disabled readonly>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Advance :') }}</span>
                                        <input type="number" min="0" step="any" wire:model.live="advance" class="form-control" required>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Discount :') }}</span>
                                        <input type="number" min="0" step="any" wire:model.live="discount" class="form-control" required>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Grand Total :') }}</span>
                                        <input type="text" wire:model="total_amount" class="form-control" disabled readonly>
                                        <span class="input-group-text">{{ __('Tk') }}</span>
                                    </div>
                                    <div class="input-group input-group-sm mb-3">
                                        <span class="input-group-text ps-5 w-50">{{ __('Auto Disable :') }}</span>
                                        <div class="input-group-text form-check form-switch form-check-reverse w-50 text-center justify-content-center">
                                            <input class="form-check-input ms-0" wire:model="auto_disable" type="checkbox">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer pb-0 mb-0 border-top-0 pe-0">
                                <button type="button" class="btn btn-danger close" wire:click="closeBillModal">{{ __('Close') }}</button>
                                <button type="submit" class="btn btn-success">{{ __('Save Changes') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

@push('styles')
    <style>
        /* Keep the bulk-copy action bar visible while the customer table scrolls. */
        .bulk-client-copy-modal .modal-content {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 1rem);
            max-height: calc(100vh - 1rem);
            overflow: hidden;
        }
        .bulk-client-copy-form {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }
        .bulk-client-copy-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto !important;
            overscroll-behavior: contain;
        }
        .bulk-client-copy-footer {
            position: sticky;
            bottom: 0;
            z-index: 5;
            flex: 0 0 auto;
            background: var(--bs-modal-bg, #fff);
            border-top: 1px solid var(--bs-border-color, #dee2e6);
            box-shadow: 0 -5px 14px rgba(15, 23, 42, .06);
        }
        .bulk-client-copy-footer .btn-primary {
            min-height: 42px;
            padding-inline: 1.15rem;
            font-weight: 700;
        }
        .customer-cid-live { display:inline-flex; align-items:center; gap:.45rem; white-space:nowrap; }
        .customer-live-dot { display:inline-block; width:.58rem; height:.58rem; flex:0 0 .58rem; border-radius:50%; }
        .customer-live-dot--active { background:#16a34a; box-shadow:0 0 0 0 rgba(22,163,74,.55); animation:customerLivePulse 1.6s infinite; }
        .customer-live-dot--inactive { background:#f43f5e; box-shadow:0 0 0 0 rgba(244,63,94,.35); }
        @keyframes customerLivePulse { 0% { box-shadow:0 0 0 0 rgba(22,163,74,.5); } 70% { box-shadow:0 0 0 .42rem rgba(22,163,74,0); } 100% { box-shadow:0 0 0 0 rgba(22,163,74,0); } }
        @media (prefers-reduced-motion: reduce) { .customer-live-dot--active { animation:none; } }
        .customer-page-header { color: #172033; }
        .customer-eyebrow { color: #4f46e5; font-size: .72rem; font-weight: 800; letter-spacing: .12em; margin-bottom: .35rem; }
        .customer-page-title { font-size: clamp(1.45rem, 2.3vw, 2rem); font-weight: 800; letter-spacing: -.035em; margin: 0; color: #172033; }
        .customer-page-subtitle { color: #738096; font-size: .92rem; margin-top: .35rem; }
        .customer-header-actions .btn { border-radius: 10px; font-weight: 650; padding: .62rem .9rem; }
        .customer-stat-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .8rem; }
        .customer-stat-card { min-width: 0; display: flex; align-items: center; gap: .8rem; background: #fff; border: 1px solid #e8edf5; border-radius: 14px; padding: .9rem 1rem; box-shadow: 0 5px 18px rgba(24, 39, 75, .035); }
        .customer-stat-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 42px; width: 42px; height: 42px; border-radius: 12px; font-size: 1.15rem; }
        .stat-blue { background: #e9efff; color: #3559d8; } .stat-green { background: #e3f8ee; color: #138451; } .stat-amber { background: #fff3d8; color: #a96500; } .stat-red { background: #ffe8e8; color: #c03939; } .stat-violet { background: #f1eaff; color: #7542bd; }
        .customer-stat-label { display: block; color: #778399; font-size: .76rem; font-weight: 650; white-space: nowrap; }
        .customer-stat-card strong { display: block; color: #172033; font-size: 1.35rem; line-height: 1.3; font-weight: 800; }
        .customer-row-actions .dropdown-toggle { border-radius: 9px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
        .customer-row-actions .dropdown-menu { min-width: 235px; max-height: 70vh; overflow-y: auto; padding: .45rem; border: 1px solid #e7ebf3; border-radius: 12px; z-index: 1085; }
        .customer-row-actions .dropdown-item { border-radius: 7px; padding: .48rem .65rem; font-size: .82rem; }
        /* Anchor actions are permitted menu items, not disabled links. Explicitly override
           inherited theme link colors so they remain readable like the button actions. */
        .customer-row-actions .dropdown-menu a.dropdown-item {
            color: #25364d !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            cursor: pointer !important;
            text-decoration: none !important;
        }
        .customer-row-actions .dropdown-menu a.dropdown-item:hover,
        .customer-row-actions .dropdown-menu a.dropdown-item:focus {
            color: #14243a !important;
            background: #eaf0ff !important;
        }
        .customer-row-actions .dropdown-menu a.dropdown-item i { opacity: 1 !important; }
        .customer-row-actions .dropdown-item:hover { background: #f2f5ff; }
        .customer-filter-card,
        .customer-table-card {
            border-radius: 14px !important;
        }
        .customer-filter-card .form-select {
            min-height: 38px;
            border-radius: 10px !important;
        }
        .customer-filter-card .btn-check + label {
            min-height: 32px;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .customer-filter-card .btn-check:checked + label {
            box-shadow: 0 4px 10px rgba(13,110,253,.14);
            transform: translateY(-1px);
        }
        .customer-filter-card .filter-group {
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }
        /* Keep filters and action buttons on one row on desktop.
           Let the filter chips scroll instead of pushing actions onto a second line. */
        .customer-filter-toolbar {
            display: flex;
            flex-wrap: nowrap !important;
            align-items: center;
            min-width: 0;
        }
        .customer-filter-toolbar {
            width: auto;
            gap: 0 !important;
        }
        .customer-filter-toolbar > .filter-group {
            flex: 0 1 auto !important;
            width: auto;
            min-width: 0;
            overflow-x: auto !important;
            overflow-y: hidden;
            flex-wrap: nowrap !important;
            gap: .25rem !important;
            white-space: nowrap;
        }
        .customer-filter-toolbar > .filter-group > label {
            flex: 0 0 auto;
            padding-left: .45rem !important;
            padding-right: .45rem !important;
            font-size: .72rem !important;
            min-height: 29px;
        }
        .customer-filter-toolbar > .filter-group .badge {
            font-size: .65rem;
            padding: .2em .35em;
        }
        .customer-filter-toolbar > .customer-filter-actions {
            flex: 0 0 auto !important;
            width: auto !important;
            margin-left: auto !important;
            white-space: nowrap;
            gap: .25rem !important;
            padding-left: .4rem !important;
        }
        .customer-filter-actions .btn {
            padding-left: .5rem !important;
            padding-right: .5rem !important;
            font-size: .74rem !important;
            height: 1.85rem !important;
            white-space: nowrap;
        }
        .customer-filter-actions form {
            flex: 0 0 auto;
            margin: 0;
        }
        .customer-table-card .table-responsive {
            border-radius: 14px;
        }
        .custom-data-table {
            border-collapse: separate !important;
            border-spacing: 0 8px !important;
            font-size: 0.85rem !important;
        }
        .custom-data-table thead th {
            text-transform: uppercase;
            font-weight: 700;
            color: #6c757d;
            border-bottom: 2px solid #f8f9fa !important;
            padding: 12px 10px !important;
            white-space: nowrap;
        }
        .custom-data-table tbody tr {
            background-color: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
        }
        .custom-data-table tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
            background-color: #f8f9ff !important;
        }
        @media (max-width: 1199.98px) {
            .customer-stat-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 767.98px) {
            .customer-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
            .customer-stat-card { padding: .7rem; gap: .55rem; border-radius: 11px; }
            .customer-stat-icon { width: 35px; height: 35px; flex-basis: 35px; border-radius: 10px; font-size: 1rem; }
            .customer-stat-label { font-size: .68rem; white-space: normal; }
            .customer-stat-card strong { font-size: 1.12rem; }
            .customer-page-subtitle { font-size: .82rem; }
            .customer-header-actions { width: 100%; }
            .customer-header-actions .btn { flex: 1 1 auto; }
            .customer-filter-toolbar {
                flex-wrap: nowrap !important;
                overflow-x: auto;
                overflow-y: hidden;
                padding-bottom: .25rem;
                width: 100%;
            }
            .customer-filter-toolbar > .filter-group {
                flex: 0 0 auto !important;
                width: max-content !important;
                min-width: max-content !important;
                flex-wrap: nowrap !important;
            }
            .customer-filter-toolbar > .customer-filter-actions {
                flex: 0 0 auto !important;
                flex-wrap: nowrap !important;
                width: max-content !important;
                margin-left: 0 !important;
                border-left: 1px solid #dee2e6 !important;
                border-top: 0 !important;
                padding-top: 0 !important;
            }
            .customer-filter-card,
            .customer-table-card {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .customer-filter-card .ms-auto {
                width: 100%;
                margin-left: 0 !important;
                border-left: 0 !important;
                border-top: 1px solid #dee2e6;
                padding-top: .5rem;
            }
        }

        .custom-data-table td {
            padding: 12px 10px !important;
            vertical-align: middle !important;
            border-top: 1px solid #f1f1f1 !important;
        }
        .badge-soft {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .action-btns .btn {
            width: 32px !important;
            height: 32px !important;
            padding: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 8px !important;
            margin: 0 2px !important;
            transition: all 0.2s !important;
        }
        .action-btns .btn:hover {
            transform: scale(1.1);
        }
        .fw-600 { font-weight: 600; }
        .btn-outline-indigo {
            color: #6610f2;
            border-color: #6610f2;
        }
        .btn-outline-indigo:hover, .btn-check:checked + .btn-outline-indigo {
            background-color: #6610f2;
            color: #fff;
        }
        .text-indigo { color: #6610f2 !important; }
        .bg-indigo { background-color: #6610f2; color: #fff; }
        
        .btn-outline-purple {
            color: #6f42c1;
            border-color: #6f42c1;
        }
        .btn-outline-purple:hover, .btn-check:checked + .btn-outline-purple {
            background-color: #6f42c1;
            color: #fff;
        }
        .text-purple { color: #6f42c1 !important; }
        .bg-purple { background-color: #6f42c1; color: #fff; }
        
        .billing-card {
            min-width: 120px;
            padding: 5px;
            background: #fdfdfd;
            border-radius: 6px;
        }

        .btn-check:checked + .btn {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
        }
        
        .filter-group::-webkit-scrollbar {
            height: 4px;
        }
        .filter-group::-webkit-scrollbar-thumb {
            background: #e9ecef;
            border-radius: 10px;
        }
        .filter-group .btn {
            flex: 0 0 auto !important;
            white-space: nowrap !important;
        }

        /* DataTables Styling Overrides */
        .dt-container .dt-search input {
            border: 0;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 8px 15px;
        }
        .dt-container .dt-paging .dt-paging-button.current {
            background: #0d6efd !important;
            color: #fff !important;
            border: 0;
            border-radius: 8px;
        }
        .dt-buttons .btn {
            border-radius: 8px !important;
            font-size: 0.8rem !important;
            margin-right: 4px !important;
            margin-bottom: 4px !important;
            flex: 0 0 auto !important;
            white-space: nowrap !important;
        }
        .dt-container .dt-buttons {
            display: flex !important;
            flex-wrap: nowrap !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            gap: 2px !important;
            max-width: 100% !important;
            padding-bottom: 6px !important;
        }
        .dt-container .dt-buttons::-webkit-scrollbar {
            height: 4px;
        }
        .dt-container .dt-buttons::-webkit-scrollbar-thumb {
            background: #e9ecef;
            border-radius: 10px;
        }
        .dt-paging {
            margin-top: 10px !important;
        }
        .dt-info {
            font-size: 0.8rem;
            color: #6c757d;
        }
    </style>
@endpush

@script
    <script>
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });

            if ($.fn.dataTable.isDataTable('.customer-table')) {
                $('.customer-table').DataTable().destroy();
            }

            var table = $('.customer-table').DataTable({
                processing: true,
                // serverSide: true,
                pagingType: 'full_numbers',
                pageLength: 10,
                lengthChange: true,
                searchable: true,
                lengthMenu: [
                    [10, 25, 50, 100, -1],
                    [10, 25, 50, 100, 'All']
                ],
                select: true,
                dom: '<"d-flex flex-column flex-md-row justify-content-md-between align-items-center gap-2 mb-3"Bf>rt<"d-flex flex-column flex-md-row justify-content-md-between align-items-center gap-2 mt-3"ip>',
                buttons: [
                    'pageLength',
                    {
                        extend: 'excel',
                        text: '<i class="bi bi-file-earmark-excel"></i> {{ __("Excel") }}',
                        className: 'btn-outline-success',
                        exportOptions: { columns: ':visible', footer: true }
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '<i class="bi bi-file-earmark-pdf"></i> {{ __("PDF") }}',
                        className: 'btn-outline-danger',
                        orientation: 'landscape',
                        pageSize: 'LEGAL',
                        exportOptions: { columns: ':visible', footer: true }
                    },
                    {
                        extend: 'print',
                        text: '<i class="bi bi-printer"></i> {{ __("Print") }}',
                        className: 'btn-outline-dark',
                        exportOptions: { columns: ':visible', footer: true },
                        customize: function (win) {
                            $(win.document.body).find('h1').css('text-align', 'center').text('{{ __("Customer Billing Report") }}');
                            // We can manually append footer totals here if needed since footer: false is used
                        }
                    },
                    'colvis'
                ],
                ajax: {
                    url: "{{ route('customers.data') }}",
                    dataSrc: function(json) {
                        if (json && Array.isArray(json.data)) return json.data;
                        console.error('Customer List data response is invalid', json);
                        return [];
                    },
                    error: function(xhr) {
                        console.error('Customer List AJAX failed', xhr.status, xhr.responseText);
                    },
                    data: function(d) {
                        var checkedRadio = $('input[name="collection"]:checked');
                        var checkedId = checkedRadio.attr('id');

                        if ($('#all_list').is(':checked')) {
                            d.filter = 'all';
                        } else if ($('#all_active_list').is(':checked')) {
                            d.filter = 'all_active';
                        } else if ($('#without_collection_list').is(':checked')) {
                            d.filter = 'without_collection';
                        } else if ($('#collection_list').is(':checked')) {
                            d.filter = 'collection';
                        } else if ($('#pending_customer').is(':checked')) {
                            d.filter = 'pending';
                        }else if ($('#disable_customer').is(':checked')) {
                            d.filter = 'disable';
                        }else if ($('#free_customer').is(':checked')) {
                            d.filter = 'free';
                        }else if ($('#inactive_customer').is(':checked')) {
                            d.filter = 'inactive';
                        }else if (checkedId && checkedId.startsWith('reseller_')) {
                            d.filter = 'reseller';
                            d.reseller_id = checkedRadio.data('reseller-id');
                        }
                        const dashboardStatus = new URLSearchParams(window.location.search).get('dashboard_status');
                        if (dashboardStatus && ['all','active','pending','free','disable','inactive','recent'].includes(dashboardStatus)) {
                            d.filter = dashboardStatus === 'active' ? 'all_active' : dashboardStatus;
                        }
                        if (new URLSearchParams(window.location.search).get('map_missing') === '1') {
                            d.filter = 'missing_location';
                        }
                        d.router_name = $('#router_filter').val();
                    }
                },
                columns: [
                    { data: 'DT_RowIndex', name: 'DT_RowIndex', title: '<input type="checkbox" id="select-all-customers" class="form-check-input" title="{{ __('Select All') }}" aria-label="{{ __('Select All') }}">', searchable: false, orderable: false, className: 'text-center', render: function(data, type, row) { return '<input type="checkbox" class="customer-select form-check-input" value="'+(row.customer_unique_id || '')+'">'; } },
                    { data: 'cid', name: 'customer_unique_id', title: '{{ __('CID') }}', className: 'text-start' },
                    { data: 'customer_name_display', name: 'customer_name', title: '{{ __('Customer') }}', className: 'text-start' },
                    { data: 'connection', name: 'ppp_user.username', title: '{{ __('Connection') }}', className: 'text-start' },
                    { data: 'mobile_display', name: 'mobile', title: '{{ __('Mobile') }}', className: 'text-start' },
                    { data: 'package_display', name: 'package.package', title: '{{ __('Package') }}', className: 'text-start' },
                    { data: 'billing_date_display', name: 'billing.auto_disable_date', title: '{{ __('Billing Date') }}', className: 'text-start' },
                    { data: 'balance_display', name: 'billing.due_amount', title: '{{ __('Balance') }}', className: 'text-end' },
                    { data: 'expiry_display', name: 'billing.auto_disable_date', title: '{{ __('Expiry') }}', className: 'text-start' },
                    { data: 'address_display', name: 'customers_address', title: '{{ __('Address') }}', className: 'text-start' },
                    { data: 'pop_area', name: 'official.distribution_location', title: '{{ __('POP / Area') }}', className: 'text-start' },
                    { data: 'monthly_bill_display', name: 'billing.monthly_rent', title: '{{ __('Monthly Bill') }}', className: 'text-end' },
                    { data: 'action', name: 'action', title: '{{ __('Actions') }}', orderable: false, searchable: false, className: 'text-center' }
                ]
            });

            function updateCustomerCount() {
                var count = table.page.info().recordsTotal;
                $('.btn-check + label span').text(''); // Clear all counts
                
                // Set count for the active one
                var checkedId = $('input[name="collection"]:checked').attr('id');
                if (checkedId) {
                    $('#' + checkedId + ' + label span').text('('+count+')');
                }
            }

            function updateBulkClientSelectionCount() {
                $('#bulk-client-selected-count').text($('.customer-select:checked').length);
            }

            table.on('draw', function () {
                updateCustomerCount();
                $('#select-all-customers').prop('checked', false);
                updateBulkClientSelectionCount();
            });

            // Select/unselect all customers visible on the current page.
            $(document).off('change.customerSelectAll', '#select-all-customers').on('change.customerSelectAll', '#select-all-customers', function () {
                $('.customer-select').prop('checked', this.checked);
                updateBulkClientSelectionCount();
            });

            $(document).off('change.customerSelect', '.customer-select').on('change.customerSelect', '.customer-select', function () {
                const total = $('.customer-select').length;
                const selected = $('.customer-select:checked').length;
                $('#select-all-customers').prop('checked', total > 0 && total === selected);
                updateBulkClientSelectionCount();
            });
            
            // Logic to reset table search when a filter is clicked
            function resetTableState() {
                table.search('').columns().search('');
                $('.dt-search input').val('');
            }

            $('input[name="collection"]').on('change', function() { 
                resetTableState();
                table.clear().draw(); 
                table.ajax.reload(null, true); 
            });

            $('#router_filter').on('change', function() { 
                resetTableState();
                table.clear().draw();
                table.ajax.reload(null, true); 
            });

            $('#reset_table').on('click', function() {
                $('#router_filter').val('');
                $('#all_active_list').prop('checked', true);
                resetTableState();
                table.clear().draw();
                table.ajax.reload(null, true);
            });

            window.openBulkClientChange = function() {
                const selectedCustomerIds = $('.customer-select:checked').map(function () {
                    return String(this.value || '').trim();
                }).get().filter(Boolean);

                if (!selectedCustomerIds.length) {
                    Swal.fire({
                        title: "{{ __('No customers selected') }}",
                        text: "{{ __('Select customers using the checkboxes in the first column.') }}",
                        icon: "info"
                    });
                    return;
                }

                if (selectedCustomerIds.length > 100) {
                    Swal.fire({
                        title: "{{ __('Batch limit reached') }}",
                        text: "{{ __('Select no more than 100 customers per batch.') }}",
                        icon: "warning"
                    });
                    return;
                }

                $wire.call('openBulkClientChange', selectedCustomerIds);
            };

            window.confirmBulkClientChange = function() {
                Swal.fire({
                    title: "{{ __('Copy selected customers to destination router?') }}",
                    text: "{{ __('The destination PPP secrets will be created and verified. Source secrets, customer assignments and active source sessions will remain unchanged.') }}",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#2563eb",
                    cancelButtonColor: "#6b7280",
                    confirmButtonText: "{{ __('Yes, copy customers') }}"
                }).then((result) => {
                    if (result.isConfirmed) {
                        $wire.call('submitBulkClientChange');
                    }
                });
            };

            window.confirmDeleteCustomer = function(encryptedId) {
                Swal.fire({
                    title: "{{ __('Are you sure?') }}",
                    text: "{{ __('You won\'t be able to revert this!') }}",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#3085d6",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "{{ __('Yes, delete it!') }}"
                }).then((result) => {
                    if (result.isConfirmed) { Livewire.dispatch('delete-customer', { id: encryptedId }); }
                });
            };
 
            window.confirmDisableCustomer = function(encryptedId) {
                Swal.fire({
                    title: "{{ __('Are you sure?') }}",
                    text: "{{ __('Disable this customer?') }}",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#f0ad4e",
                    cancelButtonColor: "#6c757d",
                    confirmButtonText: "{{ __('Yes, disable it!') }}"
                }).then((result) => {
                    if (result.isConfirmed) { Livewire.dispatch('disable-customer', { id: encryptedId }); }
                });
            };

            window.confirmEnableCustomer = function(encryptedId) {
                Swal.fire({
                    title: "{{ __('Are you sure?') }}",
                    text: "{{ __('Enable this customer?') }}",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#3085d6",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "{{ __('Yes, Enable it!') }}"
                }).then((result) => {
                    if (result.isConfirmed) { Livewire.dispatch('enable-customer', { id: encryptedId }); }
                });
            };

            window.confirmPushCustomer = function(encryptedId) {
                Swal.fire({
                    title: "{{ __('Are you sure?') }}",
                    text: "{{ __('Push this customer configuration to MikroTik?') }}",
                    icon: "question",
                    showCancelButton: true,
                    confirmButtonColor: "#f0ad4e",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "{{ __('Yes, Push it!') }}"
                }).then((result) => {
                    if (result.isConfirmed) { Livewire.dispatch('push-customer', { id: encryptedId }); }
                });
            };

            window.confirmPushAllCustomers = function() {
                Swal.fire({
                    title: "{{ __('Are you sure?') }}",
                    text: "{{ __('Push configuration for ALL customers to MikroTik?') }}",
                    icon: "question",
                    showCancelButton: true,
                    confirmButtonColor: "#f0ad4e",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "{{ __('Yes, Push All!') }}"
                }).then((result) => {
                    if (result.isConfirmed) { Livewire.dispatch('push-all-customers'); }
                });
            };

            $wire.on('customer-action-done', () => { table.ajax.reload(null, false); });
    </script>
@endscript
