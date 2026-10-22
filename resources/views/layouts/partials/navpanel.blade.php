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
        <ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns">
            <li class="nav-item">
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('reseller.dashboard') }}"
                    role="button">{{ __('Dashboard') }}</a>
            </li>
            <li class="nav-item">
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('ai-engineer') }}" role="button">
                    <i class="bi bi-stars me-1"></i>{{ __('AI Engineer') }}
                </a>
            </li>

            @canany(['view-customer', 'create-customer', 'edit-customer', 'delete-customer'])
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                        aria-haspopup="true" aria-expanded="false" id="resellerCustomers">{{ __('Customers') }}</a>
                    <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0"
                        aria-labelledby="resellerCustomers">
                        <div class="bg-white dark__bg-1000 rounded-3 py-2">
                            <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}" width="60"
                                alt="" />
                            <a wire:navigate.hover wire:current.exact="active" class="dropdown-item link-600 fw-medium"
                                href="{{ route('reseller.customers.index') }}">{{ __('Customer List') }}</a>
                            @can('create-customer')
                                <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                                    href="{{ route('reseller.customers.create') }}">{{ __('New Customer') }}</a>
                            @endcan
                        </div>
                    </div>
                </li>
            @endcanany

            @canany(['payment-collection', 'payment-collection-edit', 'payment-collection-invoice', 'payment-history',
                'payment-collection-report', 'collection-list', 'without-collection-list', 'amount-collection'])
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                        aria-haspopup="true" aria-expanded="false" id="resellerBilling">{{ __('Billing') }}</a>
                    <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0"
                        aria-labelledby="resellerBilling">
                        <div class="bg-white dark__bg-1000 rounded-3 py-2">
                            <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}" width="60"
                                alt="" />
                            @can('payment-collection')
                                <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                                    href="{{ route('payment-collection') }}">{{ __('Payment Collection') }}</a>
                            @endcan
                            @can('payment-collection-edit')
                                <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                                    href="{{ route('collection-edit') }}">{{ __('Collection Edit') }}</a>
                            @endcan
                            @can('payment-collection-invoice')
                                <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                                    href="{{ route('payment-invoice') }}">{{ __('Payment Invoice') }}</a>
                            @endcan
                            @canany(['payment-collection-report', 'collection-list', 'without-collection-list',
                                'amount-collection'])
                                <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                                    href="{{ route('collection-report.index') }}">{{ __('Collection Report') }}</a>
                            @endcanany
                        </div>
                    </div>
                </li>
            @endcanany

            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false" id="resellerAccount">{{ __('My Account') }}</a>
                <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0"
                    aria-labelledby="resellerAccount">
                    <div class="bg-white dark__bg-1000 rounded-3 py-2">
                        <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}" width="60"
                            alt="" />
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('reseller.wallet.index') }}">{{ __('Wallet & Earnings') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('reseller.vouchers.index') }}">{{ __('Vouchers') }}</a>
                    </div>
                </div>
            </li>

            @can('package-setup')
                <li class="nav-item">
                    <a wire:navigate.hover wire:current="active" class="nav-link"
                        href="{{ route('reseller.packages.index') }}" role="button">{{ __('Packages') }}</a>
                </li>
            @endcan
        </ul>
    @else
        <ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns">
            <li class="nav-item">
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('dashboard') }}"
                    role="button">{{ __('Dashboard') }}</a>
            </li>
            <li class="nav-item">
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('ai-engineer') }}" role="button">
                    <i class="bi bi-stars me-1"></i>{{ __('AI Engineer') }}
                </a>
            </li>
            <li class="nav-item">
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('mikrotik-sync') }}"
                    role="button">{{ __('Mikrotik Sync') }}</a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false" id="mikrotikSetupNav">{{ __('Mikrotik Setup') }}</a>
                <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0"
                    aria-labelledby="mikrotikSetupNav">
                    <div class="bg-white dark__bg-1000 rounded-3 py-2">
                        <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}"
                            width="60" alt="" />

                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-ip-setup') }}">{{ __('IP & Pool') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-pppoe-setup') }}">{{ __('PPPoE Server') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-radius-setup') }}">{{ __('RADIUS') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-firewall-setup') }}">{{ __('Firewall') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-walled-garden') }}">
                            {{ __('Walled Garden') }} <span class="badge rounded-pill ms-2 badge-subtle-info">{{ __('New') }}</span>
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-queue-setup') }}">{{ __('Queues') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-vpn-setup') }}">{{ __('VPN Server') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-interface-setup') }}">{{ __('Interfaces & VLAN') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-traffic-monitor') }}">{{ __('Live Traffic') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-log-viewer') }}">{{ __('Router Logs') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-backup-setup') }}">{{ __('Backup & Restore') }}</a>

                        <hr class="my-2">
                        {{-- Highlighted Hotspot --}}
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('mikrotik-hotspot-manager') }}">
                            <span>📶 {{ __('Hotspot Manager') }}</span>
                        </a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('address-setup') }}"
                    role="button">{{ __('Address') }}</a>
            </li>
            <li class="nav-item">
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link"
                    href="{{ route('package-list-setup') }}" role="button">{{ __('Package') }}</a>
            </li>
            <li class="nav-item">
                <!-- parent pages-->
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('new-customer') }}"
                    role="button">{{ __('Create Customer') }}</a>
            </li>
            <li class="nav-item">
                <!-- parent pages-->
                <a wire:navigate.hover wire:current.exact="active" class="nav-link"
                    href="{{ route('customers.index') }}" role="button">{{ __('Customers') }}</a>
            </li>
            <li class="nav-item">
                <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('admin-tickets') }}"
                    role="button">{{ __('Support Tickets') }}</a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false" id="collections">{{ __('Collection') }}</a>
                <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0"
                    aria-labelledby="collections">
                    <div class="bg-white dark__bg-1000 rounded-3 py-2">
                        <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}"
                            width="60" alt="" />
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('payment-collection') }}">{{ __('Payment Collection') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('collection-edit') }}">
                            {{ __('Collection Edit') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('payment-invoice') }}">
                            {{ __('Payment Invoice') }}
                        </a>
                    </div>
                </div>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false" id="reports">{{ __('Reports') }}</a>
                <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0" aria-labelledby="reports">
                    <div class="bg-white dark__bg-1000 rounded-3 py-2">
                        <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}"
                            width="60" alt="" />
                        <a wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('collection-report.index') }}">
                            {{ __('Collections Report') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('customer-summary') }}">
                            {{ __('Customer Summary') }}
                        </a>
                        <a wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('dis-report') }}">
                            {{ __('DIS Summary') }}
                            <span class="badge rounded-pill ms-2 badge-subtle-success">{{ __('New') }}</span>
                        </a>
                    </div>
                </div>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false" id="financeDropdown">{{ __('Billing & Finance') }}</a>
                <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0"
                    aria-labelledby="financeDropdown">
                    <div class="bg-white dark__bg-1000 rounded-3 py-2">
                        <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}"
                            width="60" alt="" />
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium" href="{{ route('billing-finance') }}">{{ __('Overview') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium" href="{{ route('income-summary') }}">{{ __('Income & Collection') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium" href="{{ route('ledger-summary') }}">{{ __('Ledger Summary') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium" href="{{ route('extra-charges') }}">{{ __('Extra Charges') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium" href="{{ route('admin.expenses') }}">{{ __('Expenses') }}</a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium" href="{{ route('admin.profit-summary') }}">{{ __('Profit & Loss') }}</a>
                    </div>
                </div>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false" id="admin">{{ __('Admin') }}</a>
                <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0" aria-labelledby="admin">
                    <div class="bg-white dark__bg-1000 rounded-3 py-2">
                        <img class="img-dropdown" src="{{ asset('images/authentication-corner.png') }}" width="60" alt="" />
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('admin.resellers.index') }}">
                            {{ __('Reseller Setup') }}
                        </a>
                        @if (auth()->user()->hasRole('Super Admin'))
                            <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium" href="{{ route('mac-reseller.choose') }}"><i class="bi bi-people-fill me-1"></i>{{ __('All MAC Reseller Portals') }}</a>
                        @endif
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('admin.purchase-requests') }}">
                            {{ __('Purchase Requests') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('admin.activity-logs') }}">
                            {{ __('Activity Logs') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('admin.login-logs') }}">
                            {{ __('Login Logs') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('admin.system-logs') }}">
                            {{ __('System Logs') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('admin.reviews') }}">
                            {{ __('Customer Reviews') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('admin.vouchers') }}">
                            {{ __('Reseller Vouchers') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('sms-setup') }}">
                            {{ __('SMS Setup') }}
                        </a>
                        <a wire:navigate.hover wire:current="active" class="dropdown-item link-600 fw-medium"
                            href="{{ route('sms-bridge.index') }}">
                            {{ __('SMS Bridge') }}
                        </a>
                    </div>
                </div>
            </li>
        </ul>
    @endif
@else
    <ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns">
        <li class="nav-item">
            <a wire:navigate.hover wire:current="active" class="nav-link" href="{{ route('profile.show') }}"
                role="button">{{ __('Profile') }}</a>
        </li>
    </ul>
@endif

@endif
