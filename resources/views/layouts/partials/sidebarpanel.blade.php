@if (auth()->check() && auth()->user()->hasRole('Reseller') && auth()->user()->reseller?->isMacPanel())
    <ul class="navbar-nav flex-column mb-3">
        <li class="nav-item"><div class="row navbar-vertical-label-wrapper mt-3 mb-2"><div class="col-auto navbar-vertical-label">{{ __('MAC Reseller Panel') }}</div><div class="col ps-0"><hr class="mb-0 navbar-vertical-divider" /></div></div></li>
        <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mac-reseller.dashboard') }}"><span class="nav-link-icon"><i class="bi bi-speedometer2"></i></span><span class="nav-link-text ps-1">{{ __('Dashboard') }}</span></a></li>
        <li class="nav-item"><a wire:navigate.hover wire:current.exact="active" class="nav-link" href="{{ route('mac-reseller.customers.index') }}"><span class="nav-link-icon"><i class="bi bi-people-fill"></i></span><span class="nav-link-text ps-1">{{ __('My Clients') }}</span></a></li>
        <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mac-reseller.customers.create') }}"><span class="nav-link-icon"><i class="bi bi-person-plus-fill"></i></span><span class="nav-link-text ps-1">{{ __('Add Client') }}</span></a></li>
    </ul>
@else
@if (auth()->user()->roles()->exists() || auth()->user()->permissions()->exists())
    @if (auth()->user()->hasRole('Reseller'))
        <ul class="navbar-nav flex-column mb-3" id="navbarVerticalNav">

            {{-- ── Dashboard (always visible) ── --}}
            <li class="nav-item">
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('Reseller Panel') }}</div>
                    <div class="col ps-0">
                        <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                </div>
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('reseller.dashboard') }}"
                    role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon"><i class="bi bi-speedometer2 me-2"></i></span>
                        <span class="nav-link-text ps-1">{{ __('Dashboard') }}</span>
                    </div>
                </a>
            </li>

            {{-- ── Customers ── --}}
            @canany(['view-customer', 'create-customer', 'edit-customer', 'delete-customer'])
                <li class="nav-item">
                    <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                        <div class="col-auto navbar-vertical-label">{{ __('Customers') }}</div>
                        <div class="col ps-0">
                            <hr class="mb-0 navbar-vertical-divider" />
                        </div>
                    </div>
                    <a wire:navigate.hover wire:current.exact="active" class="nav-link"
                        href="{{ route('reseller.customers.index') }}" role="button">
                        <div class="d-flex align-items-center">
                            <span class="nav-link-icon"><i class="bi bi-people-fill"></i></span>
                            <span class="nav-link-text ps-1">{{ __('Customer List') }}</span>
                        </div>
                    </a>
                    @can('create-customer')
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('reseller.customers.create') }}" role="button">
                            <div class="d-flex align-items-center">
                                <span class="nav-link-icon"><i class="bi bi-person-fill-add"></i></span>
                                <span class="nav-link-text ps-1">{{ __('New Customer') }}</span>
                            </div>
                        </a>
                    @endcan
                </li>
            @endcanany

            {{-- ── Billing & Payments ── --}}
            @canany(['payment-collection', 'payment-collection-edit', 'payment-collection-invoice', 'payment-history',
                'payment-collection-report', 'collection-list', 'without-collection-list', 'amount-collection'])
                <li class="nav-item">
                    <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                        <div class="col-auto navbar-vertical-label">{{ __('Billing') }}</div>
                        <div class="col ps-0">
                            <hr class="mb-0 navbar-vertical-divider" />
                        </div>
                    </div>
                    @can('payment-collection')
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('payment-collection') }}"
                            role="button">
                            <div class="d-flex align-items-center">
                                <span class="nav-link-icon"><i class="bi bi-cash-coin"></i></span>
                                <span class="nav-link-text ps-1">{{ __('Payment Collection') }}</span>
                            </div>
                        </a>
                    @endcan
                    @can('payment-collection-edit')
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('collection-edit') }}"
                            role="button">
                            <div class="d-flex align-items-center">
                                <span class="nav-link-icon"><i class="bi bi-pencil-square"></i></span>
                                <span class="nav-link-text ps-1">{{ __('Collection Edit') }}</span>
                            </div>
                        </a>
                    @endcan
                    @can('payment-collection-invoice')
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('payment-invoice') }}"
                            role="button">
                            <div class="d-flex align-items-center">
                                <span class="nav-link-icon"><i class="bi bi-receipt"></i></span>
                                <span class="nav-link-text ps-1">{{ __('Payment Invoice') }}</span>
                            </div>
                        </a>
                    @endcan
                    @canany(['payment-collection-report', 'collection-list', 'without-collection-list',
                        'amount-collection'])
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('collection-report.index') }}" role="button">
                            <div class="d-flex align-items-center">
                                <span class="nav-link-icon"><i class="bi bi-bar-chart-line"></i></span>
                                <span class="nav-link-text ps-1">{{ __('Collection Report') }}</span>
                            </div>
                        </a>
                    @endcanany
                </li>
            @endcanany

            {{-- ── Wallet & Vouchers (always visible to all resellers) ── --}}
            <li class="nav-item">
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('My Account') }}</div>
                    <div class="col ps-0">
                        <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                </div>
                <a wire:navigate.hover wire:current="active" class="nav-link"
                    href="{{ route('reseller.wallet.index') }}" role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon"><i class="bi bi-wallet2"></i></span>
                        <span class="nav-link-text ps-1">{{ __('Wallet & Earnings') }}</span>
                    </div>
                </a>
                <a wire:navigate.hover wire:current="active" class="nav-link"
                    href="{{ route('reseller.vouchers.index') }}" role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon"><i class="bi bi-ticket-perforated-fill"></i></span>
                        <span class="nav-link-text ps-1">{{ __('Vouchers') }}</span>
                    </div>
                </a>
            </li>

            {{-- ── Setup & Access ── --}}
            @canany(['package-setup'])
                <li class="nav-item">
                    <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                        <div class="col-auto navbar-vertical-label">{{ __('Setup') }}</div>
                        <div class="col ps-0">
                            <hr class="mb-0 navbar-vertical-divider" />
                        </div>
                    </div>
                    @can('package-setup')
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('reseller.packages.index') }}" role="button">
                            <div class="d-flex align-items-center">
                                <span class="nav-link-icon"><i class="bi bi-box2"></i></span>
                                <span class="nav-link-text ps-1">{{ __('Packages') }}</span>
                            </div>
                        </a>
                    @endcan
                </li>
            @endcanany

        </ul>
    @else
        <ul class="navbar-nav flex-column mb-3" id="navbarVerticalNav">
            <li class="nav-item">
                <!-- label-->
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('Dashboard') }}</div>
                    <div class="col ps-0">
                        <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                </div>
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('dashboard') }}"
                    role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-speedometer2 me-2"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Dashboard') }}</span>
                    </div>
                </a>
            </li>

            {{-- ── Broadband ── --}}
            <li class="nav-item">
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('Broadband') }}</div>
                    <div class="col ps-0"><hr class="mb-0 navbar-vertical-divider" /></div>
                </div>
                <!-- Broadband Dropdown -->
                <a class="nav-link dropdown-indicator collapsed" href="#broadbandMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="broadbandMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-wifi"></i></span><span class="nav-link-text ps-1">{{ __('Broadband') }}</span></div>
                </a>
                <ul class="nav collapse" id="broadbandMenu">
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('customer-add') }}"><span class="nav-link-text ps-1">{{ __('Add Customer') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('customers.index') }}"><span class="nav-link-text ps-1">{{ __('Customer List') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('customer-bulk-import') }}"><span class="nav-link-text ps-1">{{ __('Customer Bulk Import') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-customer-search') }}"><span class="nav-link-text ps-1">{{ __('Customer Search') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-online-customers') }}"><span class="nav-link-text ps-1">{{ __('Online Customers') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-due-customers') }}"><span class="nav-link-text ps-1">{{ __('Due Customers') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-paid-customers') }}"><span class="nav-link-text ps-1">{{ __('Paid Customers') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-inactive-customers') }}"><span class="nav-link-text ps-1">{{ __('Inactive Customers') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-new-customers') }}"><span class="nav-link-text ps-1">{{ __('New Customers') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-unverified-customers') }}"><span class="nav-link-text ps-1">{{ __('Unverified Customers') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-packages') }}"><span class="nav-link-text ps-1">{{ __('Broadband Packages') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('broadband-customer-package-import') }}"><span class="nav-link-text ps-1">{{ __('Customer & Package Import') }}</span></a></li>
                </ul>

            </li>
            {{-- ── Billing & Finance ── --}}
            <li class="nav-item">
                <a class="nav-link dropdown-indicator collapsed" href="#billingFinanceMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="billingFinanceMenu">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon"><i class="bi bi-cash-stack"></i></span>
                        <span class="nav-link-text ps-1">{{ __('Billing & Finance') }}</span>
                    </div>
                </a>
                <div class="collapse ps-4" id="billingFinanceMenu">
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('billing-finance') }}"><span class="nav-link-text ps-1">{{ __('Overview') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('income-summary') }}"><span class="nav-link-text ps-1">{{ __('Income & Collection') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('ledger-summary') }}"><span class="nav-link-text ps-1">{{ __('Ledger Summary') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('extra-charges') }}"><span class="nav-link-text ps-1">{{ __('Extra Charges') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('admin.expenses') }}"><span class="nav-link-text ps-1">{{ __('Expenses') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('admin.profit-summary') }}"><span class="nav-link-text ps-1">{{ __('Profit & Loss') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('payment-collection') }}"><span class="nav-link-text ps-1">{{ __('Payment Collection') }}</span></a>
                </div>
            </li>
            <li class="nav-item">
                <!-- label-->
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('Mikrotik') }}</div>
                    <div class="col ps-0">
                        <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                </div>
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mikrotik-sync') }}"
                    role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-router-fill"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Mikrotik Sync') }}</span>
                    </div>
                </a>

                <!-- Mikrotik Server Dropdown -->
                <a class="nav-link dropdown-indicator collapsed" href="#mikrotikServer" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="mikrotikServer">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-hdd-network"></i></span><span class="nav-link-text ps-1">{{ __('Mikrotik Server') }}</span></div>
                </a>
                <ul class="nav collapse" id="mikrotikServer">
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mikrotik-server') }}"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Server') }}</span></div></a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mikrotik-server-backup') }}"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Server Backup') }}</span></div></a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mikrotik-server-import') }}"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Import From Mikrotik') }}</span></div></a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mikrotik-server-bulk-import') }}"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Bulk Clients Import') }}</span></div></a></li>
                </ul>

                <!-- Mikrotik Setup Dropdown -->
                <a class="nav-link dropdown-indicator collapsed" href="#mikrotikSetup" role="button"
                    data-bs-toggle="collapse" aria-expanded="false" aria-controls="mikrotikSetup">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-tools"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Mikrotik Setup') }}</span>
                    </div>
                </a>
                <ul class="nav collapse" id="mikrotikSetup" style="">
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-ip-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('IP & Pool') }}</span>
                            </div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-pppoe-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('PPPoE Server') }}</span></div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-radius-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('RADIUS') }}</span>
                            </div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-firewall-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Firewall') }}</span>
                            </div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-walled-garden') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Walled Garden') }}</span><span class="badge rounded-pill ms-2 badge-subtle-info">{{ __('New') }}</span>
                            </div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-queue-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Queues') }}</span>
                            </div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-vpn-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('VPN Server') }}</span>
                            </div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-interface-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Interfaces & VLAN') }}</span></div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-traffic-monitor') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Live Traffic') }}</span><span class="badge rounded-pill ms-2 badge-subtle-success">{{ __('New') }}</span>
                            </div>
                        </a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-backup-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Backup & Restore') }}</span><span
                                    class="badge rounded-pill ms-2 badge-subtle-primary">{{ __('Admin') }}</span></div>
                        </a></li>
                </ul>

                <!-- Hotspot (Unified) -->
            <li class="nav-item">
                <a class="nav-link dropdown-indicator collapsed" href="#hotspotMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="hotspotMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-wifi"></i></span><span class="nav-link-text ps-1">{{ __('Hotspot') }}</span></div>
                </a>
                <div class="collapse ps-4" id="hotspotMenu">
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-dashboard') }}"><span class="nav-link-text ps-1">{{ __('Dashboard') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-customer-add') }}"><span class="nav-link-text ps-1">{{ __('Add Customer') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-customers') }}"><span class="nav-link-text ps-1">{{ __('Customer List') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-online-customers') }}"><span class="nav-link-text ps-1">{{ __('Online Customers') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-ledger') }}"><span class="nav-link-text ps-1">{{ __('Customer Ledger') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-packages') }}"><span class="nav-link-text ps-1">{{ __('Packages') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-cards') }}"><span class="nav-link-text ps-1">{{ __('Card List') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-card-print-setup') }}"><span class="nav-link-text ps-1">{{ __('Print Setup') }}</span></a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('hotspot-page-setup') }}"><span class="nav-link-text ps-1">{{ __('Hotspot Setup') }}</span></a>
                </div>
            </li>
            <li class="nav-item">
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('Network Monitoring') }}</div>
                    <div class="col ps-0"><hr class="mb-0 navbar-vertical-divider" /></div>
                </div>
                <a class="nav-link dropdown-indicator collapsed" href="#networkMonitoringMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="networkMonitoringMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-activity"></i></span><span class="nav-link-text ps-1">{{ __('Network Monitoring') }}</span></div>
                </a>
                <div class="collapse ps-4" id="networkMonitoringMenu">
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('device-manager') }}"><span class="nav-link-text ps-1">{{ __('Device Manager') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-events') }}"><span class="nav-link-text ps-1">{{ __('Events & Alerts') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-map') }}"><span class="nav-link-text ps-1">{{ __('Network Map') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-topology.designer') }}"><span class="nav-link-text ps-1">{{ __('Topology Designer') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-topology.live') }}"><span class="nav-link-text ps-1">{{ __('Live Topology') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('optical-audit') }}"><span class="nav-link-text ps-1">{{ __('Virtual OTDR / Optical Audit') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('traffic-monitor') }}"><span class="nav-link-text ps-1">{{ __('Traffic Monitor') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('high-usage-monitor') }}"><span class="nav-link-text ps-1">{{ __('High Usage Monitor') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('device-watcher') }}"><span class="nav-link-text ps-1">{{ __('Device Watcher') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mikrotik-login-messages') }}"><span class="nav-link-text ps-1">{{ __('Logs & Alerts') }}</span></a>
                    @if(auth()->user()->hasRole('Super Admin'))
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('system.sync-health') }}"><span class="nav-link-text ps-1">{{ __('Automation & Sync Health') }}</span></a>
                    @endif
                </div>
            </li>
            <li class="nav-item">
                <!-- X-Link Billing parity modules -->
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('Network Operations') }}</div>
                </div>
                <a class="nav-link dropdown-indicator collapsed" href="#networkInventoryMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="networkInventoryMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-router"></i></span><span class="nav-link-text ps-1">{{ __('Devices Inventory') }}</span></div>
                </a>
                <div class="collapse ps-4" id="networkInventoryMenu">
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-inventory') }}"><span class="nav-link-text ps-1">{{ __('Dashboard') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-inventory.mikrotik') }}"><span class="nav-link-text ps-1">{{ __('MikroTik Management') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-inventory.olt') }}"><span class="nav-link-text ps-1">{{ __('OLT Management') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-inventory.onu-management') }}"><span class="nav-link-text ps-1">{{ __('ONU Management') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-monitoring') }}"><span class="nav-link-text ps-1">{{ __('Customer Network Monitoring') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-inventory.olt') }}#olt-provisioning"><span class="nav-link-text ps-1">{{ __('OLT / ONU Provisioning') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-inventory.switches') }}"><span class="nav-link-text ps-1">{{ __('Switch Management') }}</span></a>
                    <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('network-inventory.access-points') }}"><span class="nav-link-text ps-1">{{ __('Access Point Management') }}</span></a>
                </div>
                {{-- X-Link Billing parity modules: collapsed parents with click-to-open submenus --}}
                <a class="nav-link dropdown-indicator collapsed" href="#mobileBankingMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="mobileBankingMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-phone"></i></span><span class="nav-link-text ps-1">{{ __('Mobile Banking') }}</span></div>
                </a>
                <div class="collapse ps-4" id="mobileBankingMenu">
                    <a wire:navigate.hover class="nav-link" href="{{ route('xlink.mobile-banking') }}">{{ __('Overview') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('mobile-banking.logs') }}">{{ __('Mobile Banking LOG') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('mobile-banking.gateways') }}">{{ __('Payment Gateway') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('mobile-banking.methods') }}">{{ __('Payment Methods') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('mobile-banking.settings') }}">{{ __('Sync Settings') }}</a>
                </div>

                <a class="nav-link dropdown-indicator collapsed" href="#partnerNetworkMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="partnerNetworkMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-diagram-2"></i></span><span class="nav-link-text ps-1">{{ __('Partner Network') }}</span></div>
                </a>
                <div class="collapse ps-4" id="partnerNetworkMenu">
                    <a wire:navigate.hover class="nav-link" href="{{ route('xlink.partner-network') }}">{{ __('Overview') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('reseller-cashflow') }}">{{ __('Reseller Cashflow') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('reseller-ledger') }}">{{ __('Reseller Ledger') }}</a>
                </div>

                <a class="nav-link dropdown-indicator collapsed" href="#bandwidthResellerMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="bandwidthResellerMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-speedometer2"></i></span><span class="nav-link-text ps-1">{{ __('Bandwidth Reseller') }}</span></div>
                </a>
                <div class="collapse ps-4" id="bandwidthResellerMenu">
                    <a wire:navigate.hover class="nav-link" href="{{ route('xlink.bandwidth-reseller') }}">{{ __('Reseller Management') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('bandwidth-services') }}">{{ __('Services') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('bandwidth-invoices') }}">{{ __('Billing') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('bandwidth-tickets') }}">{{ __('Support Ticket') }}</a>
                </div>

                <a class="nav-link dropdown-indicator collapsed" href="#stockInventoryMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="stockInventoryMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-box-seam"></i></span><span class="nav-link-text ps-1">{{ __('Stock Inventory') }}</span></div>
                </a>
                <div class="collapse ps-4" id="stockInventoryMenu">
                    <a wire:navigate.hover class="nav-link" href="{{ route('xlink.stock-inventory') }}">{{ __('Dashboard') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('stock-inventory.products') }}">{{ __('Products & Tracking') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('stock-inventory.movements') }}">{{ __('Stock / Issue / Sales') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('stock-inventory.warranty') }}">{{ __('Warranty') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('stock-inventory.damaged') }}">{{ __('Lost & Damaged') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('stock-inventory.settings') }}">{{ __('Settings') }}</a>
                </div>
                <a class="nav-link dropdown-indicator collapsed" href="#communicationCenterMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="communicationCenterMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-chat-dots"></i></span><span class="nav-link-text ps-1">{{ __('Communication Center') }}</span></div>
                </a>
                <div class="collapse ps-4" id="communicationCenterMenu">
                    <a wire:navigate.hover class="nav-link" href="{{ route('xlink.communication-center') }}">{{ __('Overview') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('communication-center.chat') }}">{{ __('Chat') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('communication-center.sms') }}">{{ __('SMS Center') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('communication-center.notifications') }}">{{ __('Notifications') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('communication-center.settings') }}">{{ __('Communication Settings') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('communication-center.whatsapp') }}">{{ __('WhatsApp Inbox') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('sms-bridge.index') }}">{{ __('SMS Bridge') }}</a>
                </div>
                <a class="nav-link dropdown-indicator collapsed" href="#supportCenterMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="supportCenterMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-life-preserver"></i></span><span class="nav-link-text ps-1">{{ __('Support Center') }}</span></div>
                </a>
                <div class="collapse ps-4" id="supportCenterMenu">
                    <a wire:navigate.hover class="nav-link" href="{{ route('xlink.support-center') }}">{{ __('Overview') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('support-center.tickets') }}">{{ __('Ticket List') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('support-center.create-ticket') }}">{{ __('Add Ticket') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('support-center.sales') }}">{{ __('Sales Query List') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('support-center.sales-create') }}">{{ __('Add Sales Query') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('support-center.kyc') }}">{{ __('KYC Update') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('support-center.templates') }}">{{ __('Template Settings') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('admin.activity-logs') }}">{{ __('Activity Logs') }}</a>
                    <a wire:navigate.hover class="nav-link" href="{{ route('admin.login-logs') }}">{{ __('Login Logs') }}</a>
                </div>
                @foreach([
                    ['xlink.team-access','Team & Access','bi-people','teamAccessMenu'],
                    ['xlink.system-settings','System Settings','bi-gear','systemSettingsMenu'],
                    ['xlink.billing-helpline','Billing Helpline','bi-headset','billingHelplineMenu'],
                    ['xlink.profile-security','Profile & Security','bi-shield-lock','profileSecurityMenu'],
                ] as [$route,$label,$icon,$menuId])
                    <a class="nav-link dropdown-indicator collapsed" href="#{{ $menuId }}" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="{{ $menuId }}">
                        <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi {{ $icon }}"></i></span><span class="nav-link-text ps-1">{{ __($label) }}</span></div>
                    </a>
                    @if($menuId === 'teamAccessMenu')
                    <div class="collapse ps-4" id="{{ $menuId }}">
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route($route) }}">{{ __('Overview') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('admin-users') }}">{{ __('Management Users') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('admin-roles') }}">{{ __('Permission Templates') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('user-activity') }}">{{ __('User & Permission Audit') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('staff-team') }}">{{ __('Team Overview') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('staff-conveyance') }}">{{ __('Visits & Conveyance') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('attendance') }}">{{ __('Attendance') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('attendance-report') }}">{{ __('Attendance Report') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('attendance-leave') }}">{{ __('Leave Management') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('attendance-payroll') }}">{{ __('Payroll & Salary') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('attendance-settings') }}">{{ __('Attendance Settings') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('team-access.demo') }}">{{ __('Demo Module') }}</a>
                    </div>
                    @elseif($menuId === 'systemSettingsMenu')
                    <div class="collapse ps-4" id="{{ $menuId }}">
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route($route) }}">{{ __('Overview') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('site-settings') }}">{{ __('Master Setup') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('company-settings') }}">{{ __('Company Settings') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('mikrotik-sync') }}">{{ __('MikroTik Setup') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('sms-setup') }}">{{ __('SMS Setup') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('main-site-setup') }}">{{ __('Main Site Setup') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('mikrotik-server-backup') }}">{{ __('MikroTik Backup') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('mikrotik-log-viewer') }}">{{ __('MikroTik Logs') }}</a>
                    </div>
                    @elseif($menuId === 'billingHelplineMenu')
                    <div class="collapse ps-4" id="{{ $menuId }}">
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route($route) }}">{{ __('Overview') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('admin-tickets') }}">{{ __('Support Tickets') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('payment-collection') }}">{{ __('Payment Collection') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('collection-report.index') }}">{{ __('Collection Report') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('customer-summary') }}">{{ __('Customer Summary') }}</a>
                    </div>
                    @elseif($menuId === 'profileSecurityMenu')
                    <div class="collapse ps-4" id="{{ $menuId }}">
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route($route) }}">{{ __('Overview') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('profile.show') }}">{{ __('My Profile') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('api-tokens.index') }}">{{ __('API Tokens') }}</a>
                        <a wire:navigate.hover class="nav-link" href="{{ route('admin.login-logs') }}">{{ __('Login Logs') }}</a>
                    </div>
                    @else
                    <div class="collapse ps-4" id="{{ $menuId }}">
                        <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route($route) }}">{{ __('Overview') }}</a>
                    </div>
                    @endif
                @endforeach

            <li class="nav-item">
                <!-- label-->
                <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">{{ __('Admin') }}</div>
                    <div class="col ps-0">
                        <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                </div>
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('address-setup') }}"
                    role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-buildings"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Address Setup') }}</span>
                    </div>
                </a>
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link"
                    href="{{ route('package-list-setup') }}" role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-box2"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Package Setup') }}</span>
                    </div>
                </a>
                <!-- Purchase Requests page-->
                <a wire:navigate.hover wire:current="active" class="nav-link"
                    href="{{ route('admin.purchase-requests') }}" role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-cart-check-fill"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Purchase Requests') }}</span>
                    </div>
                </a>
                <!-- Dedicated MAC Reseller module menu: Super Admin only -->
                @if (auth()->user()->hasRole('Super Admin'))
                <a class="nav-link dropdown-indicator collapsed" href="#macResellerAdminMenu" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="macResellerAdminMenu">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><i class="bi bi-people-fill"></i></span><span class="nav-link-text ps-1">{{ __('MAC Reseller') }}</span></div>
                </a>
                <ul class="nav collapse ps-2" id="macResellerAdminMenu">
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mac-reseller.dashboard') }}"><i class="bi bi-speedometer2 me-2"></i>{{ __('Dashboard') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('package-list-setup') }}"><i class="bi bi-box2 me-2"></i>{{ __('Package') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('package-list-setup') }}"><i class="bi bi-sliders me-2"></i>{{ __('Tariff Config') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('admin.resellers.create', ['panel_type' => 'mac']) }}"><i class="bi bi-person-plus-fill me-2"></i>{{ __('Add MAC Reseller') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('admin.resellers.index', ['panel_type' => 'mac']) }}"><i class="bi bi-list-ul me-2"></i>{{ __('MAC Reseller List') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('reseller-cashflow') }}"><i class="bi bi-wallet2 me-2"></i>{{ __('Reseller Funding') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('xlink.mobile-banking') }}"><i class="bi bi-phone me-2"></i>{{ __('Client PGW Payments') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mobile-banking.logs') }}"><i class="bi bi-receipt me-2"></i>{{ __('PGW Transaction Settlement') }}</a></li>
                    <li class="nav-item"><a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('communication-center.notifications') }}"><i class="bi bi-megaphone me-2"></i>{{ __('MACReseller Notice') }}</a></li>
                </ul>
                @endif
                <!-- reseller setup page-->
                <a wire:navigate.hover wire:current="active" class="nav-link"
                    href="{{ route('admin.resellers.index') }}" role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-person-badge-fill"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Reseller Setup') }}</span>
                    </div>
                </a>
                <!-- Logs Management Dropdown -->
                <a class="nav-link dropdown-indicator collapsed" href="#logsSetupDropdown" role="button"
                    data-bs-toggle="collapse" aria-expanded="false" aria-controls="logsSetupDropdown">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-journal-text"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Logs & Audits') }}</span>
                    </div>
                </a>
                <ul class="nav collapse" id="logsSetupDropdown">
                    <li class="nav-item">
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('admin.activity-logs') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Activity Logs') }}</span></div>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('admin.login-logs') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Login Logs') }}</span>
                            </div>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('admin.system-logs') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('System Logs') }}</span>
                            </div>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('mikrotik-log-viewer') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Router Logs') }}</span>
                            </div>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('admin.vouchers') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('Reseller Vouchers') }}</span></div>
                        </a>
                    </li>
                </ul>

                <!-- Customer Reviews page-->
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('admin.reviews') }}"
                    role="button">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-chat-heart"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('Customer Reviews') }}</span>
                    </div>
                </a>
                <!-- parent pages-->
                <!-- SMS Management Dropdown -->
                <a class="nav-link dropdown-indicator collapsed" href="#smsSetupDropdown" role="button"
                    data-bs-toggle="collapse" aria-expanded="false" aria-controls="smsSetupDropdown">
                    <div class="d-flex align-items-center">
                        <span class="nav-link-icon">
                            <i class="bi bi-envelope-check"></i>
                        </span>
                        <span class="nav-link-text ps-1">{{ __('SMS Management') }}</span>
                    </div>
                </a>
                <ul class="nav collapse" id="smsSetupDropdown">
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('sms-service') }}"><span class="nav-link-text ps-1">{{ __('SMS Service Overview') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('sms-service.send') }}"><span class="nav-link-text ps-1">{{ __('Send SMS') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('sms-service.bulk') }}"><span class="nav-link-text ps-1">{{ __('Bulk Campaign') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('sms-service.templates') }}"><span class="nav-link-text ps-1">{{ __('SMS Templates') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('sms-service.gateway') }}"><span class="nav-link-text ps-1">{{ __('SMS Gateway') }}</span></a></li>
                    <li class="nav-item"><a wire:navigate.hover class="nav-link" href="{{ route('sms-service.reports') }}"><span class="nav-link-text ps-1">{{ __('SMS Reports & Delivery History') }}</span></a></li>
                    <li class="nav-item">
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('sms-setup') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('SMS Setup') }}</span>
                            </div>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a wire:navigate.hover wire:current="active" class="nav-link"
                            href="{{ route('sms-bridge.index') }}">
                            <div class="d-flex align-items-center"><span class="nav-link-text ps-1">{{ __('SMS Bridge') }}</span>
                            </div>
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    @endif
@else
    <ul class="navbar-nav flex-column mb-3" id="navbarVerticalNav">
        <li class="nav-item">
            <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                <div class="col-auto navbar-vertical-label">{{ __('Account') }}</div>
                <div class="col ps-0">
                    <hr class="mb-0 navbar-vertical-divider" />
                </div>
            </div>
            <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('profile.show') }}"
                role="button">
                <div class="d-flex align-items-center">
                    <span class="nav-link-icon"><i class="bi bi-person-fill"></i></span>
                    <span class="nav-link-text ps-1">{{ __('Profile') }}</span>
                </div>
            </a>
        </li>
    </ul>
@endif

@endif
