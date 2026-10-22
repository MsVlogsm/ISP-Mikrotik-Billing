{{-- theme, alerts and settings --}}
<ul class="navbar-nav navbar-nav-icons ms-auto flex-row align-items-center">
    @if (request()->routeIs('dashboard'))
        <li class="nav-item me-2">
            <a href="{{ route('broadband-online-customers') }}" class="nav-link d-flex align-items-center gap-1 px-2 py-1 rounded-pill border text-success fw-semibold" title="Online Customer Monitoring">
                <i class="bi bi-broadcast-pin"></i><span class="d-none d-xl-inline">Online Customers</span>
            </a>
        </li>
    @endif
    <li class="nav-item dropdown" wire:ignore
        x-data="{
            current: new Date('{{ now()->format('Y-m-d H:i:s') }}'),
            timeString: '',
            dateString: '',
            init() {
                this.updateDisplay();
                setInterval(() => {
                    this.current.setSeconds(this.current.getSeconds() + 1);
                    this.updateDisplay();
                }, 1000);
            },
            updateDisplay() {
                const pad = n => n.toString().padStart(2, '0');
                let hours = this.current.getHours();
                const minutes = pad(this.current.getMinutes());
                const seconds = pad(this.current.getSeconds());
                const ampm = hours >= 12 ? 'PM' : 'AM';
                hours = hours % 12 || 12;
                this.timeString = `${pad(hours)}:${minutes}:${seconds} ${ampm}`;
                this.dateString = this.current.toLocaleDateString('en-US', {
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
                });
            }
        }"
        x-init="init()"
    >
        <!-- Mobile Clock Icon -->
        <a class="d-block d-lg-none nav-link d-flex align-items-center fs-9 pe-1 py-0"
        id="clockDropdown" role="button" data-bs-toggle="dropdown"
        aria-haspopup="true" aria-expanded="false">
            <i class="bi bi-clock fs-8"></i>
        </a>

        <!-- Dropdown for Mobile -->
        <div class="dropdown-menu dropdown-caret dropdown-menu-end dropdown-menu-card dropdown-menu-notification dropdown-caret-bg mt-3"
            aria-labelledby="clockDropdown">
            <div class="card shadow-none py-4 text-center">
                <div class="text-sm text-gray-500" x-text="dateString"></div>
                <div class="text-2xl font-medium" x-text="timeString"></div>
                <div class="text-xs text-muted">
                    {{ __('TimeZone') }}: {{ config('app.timezone') }} (UTC {{ date('O') }})
                </div>
            </div>
        </div>

        <!-- Desktop Clock Display -->
        <div class="text-center d-none d-lg-block">
            <div class="text-sm text-gray-500" x-text="dateString"></div>
            <div class="text-2xl font-medium" x-text="timeString"></div>
            <div class="text-xs text-muted">
                {{ __('TimeZone') }}: {{ config('app.timezone') }} (UTC {{ date('O') }})
            </div>
        </div>
    </li>


    <li class="nav-item ps-2 pe-0">
        <div x-data="themeToggle()" x-init="init()"  class="dropdown theme-control-dropdown">
            <a class="nav-link d-flex align-items-center dropdown-toggle fs-9 pe-1 py-0"
                href="#" role="button" id="themeSwitchDropdown"
                data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <template x-if="theme === 'light'">
                    <i class="bi bi-sun fs-8" title="Light"></i>
                </template>
                <template x-if="theme === 'dark'">
                    <i class="bi bi-moon-stars-fill fs-8" title="Dark"></i>
                </template>
                <template x-if="theme === 'auto'">
                    <i class="bi bi-circle-half fs-8" title="Auto"></i>
                </template>
            </a>

            <div class="dropdown-menu dropdown-menu-end dropdown-caret border py-0 mt-3" aria-labelledby="themeSwitchDropdown">
                <div class="bg-white dark__bg-1000 rounded-2 py-2">
                    <button @click="setTheme('light')" class="dropdown-item d-flex align-items-center gap-2" type="button"
                        value="light" data-theme-control="theme">
                        <i class="bi bi-sun fs-8"></i>
                        {{ __('Light') }}
                    </button>
                    <button @click="setTheme('dark')" class="dropdown-item d-flex align-items-center gap-2" type="button"
                        value="dark" data-theme-control="theme">
                        <i class="bi bi-moon-stars-fill fs-8"></i>
                        {{ __('Dark') }}
                    </button>
                    <button @click="setTheme('auto')" class="dropdown-item d-flex align-items-center gap-2" type="button"
                        value="auto" data-theme-control="theme">
                        <i class="bi bi-circle-half fs-8"></i>
                        {{ __('Auto') }}
                    </button>
                </div>
            </div>
        </div>
    </li>
    
    @if(auth()->user()->hasAnyRole(['Super Admin', 'Admin']) && !auth()->user()->reseller)
        @livewire('NotificationList')
    @endif

    <li class="nav-item ps-2 p-1" href="#settings-offcanvas" data-bs-toggle="offcanvas">
        <div class="card-body d-flex align-items-center">
            <div class="bg-primary-subtle position-relative rounded-start">
                <div class="settings-popover">
                    <span class="ripple">
                        <span class="fa-spin position-absolute all-0 d-flex flex-center">
                            <span class="icon-spin position-absolute all-0 d-flex flex-center">
                                <i class="bi bi-gear-fill fs-8"></i>
                            </span>
                        </span>
                    </span>
                </div>
            </div>
        </div>
    </li>

    <li class="nav-item dropdown">
        <a class="nav-link pe-0 ps-2" id="navbarDropdownUser" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
                <div class="avatar avatar-xl">
                    <img class="rounded-circle" src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" />
                </div>
            @else
                <span class="inline-flex rounded-md">
                    <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none focus:bg-gray-50 dark:focus:bg-gray-700 active:bg-gray-50 dark:active:bg-gray-700 transition ease-in-out duration-150">
                        {{ Auth::user()->name }}
                        <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                </span>
            @endif
        </a>

        <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end py-0" aria-labelledby="navbarDropdownUser">
            <div class="bg-white dark__bg-1000 rounded-2 py-2">
                <a wire:navigate.hover wire:current="active" class="dropdown-item" href="{{route('profile.show')}}">{{ __('Profile & account') }}</a>
                <div class="dropdown-divider"></div>
                @if (hasAccess(['Super Admin'], ['create-user', 'edit-user', 'delete-user']))
                    <a class="dropdown-item" wire:navigate.hover wire:current="active" href="{{route('admin-users')}}">{{ __('Manage Users') }}</a>
                @endif
                @if (hasAccess(['Super Admin'], ['create-user-role', 'edit-user-role', 'view-user-role','delete-user-role']))
                    <a class="dropdown-item" wire:navigate.hover wire:current="active" href="{{route('admin-roles')}}">{{ __('Manage Roles') }}</a>
                @endif
                <form method="POST" action="{{ route('logout') }}" x-data>
                    @csrf
                    <a class="dropdown-item" href="{{ route('logout') }}" @click.prevent="$root.submit();">
                        {{ __('Log Out') }}
                    </a>
                </form>
            </div>
        </div>
    </li>
</ul>
