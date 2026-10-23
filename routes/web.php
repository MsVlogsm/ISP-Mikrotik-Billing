<?php

use App\Http\Controllers\Admin\ProfitSummaryController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\PartnerNetworkController;
use App\Livewire\Admin\ExpenseManager;
use App\Livewire\Admin\ActivityLogViewer;
use App\Livewire\Admin\ManagePurchaseRequests;
use App\Livewire\Admin\LoginLogViewer;
use App\Livewire\Admin\ManageReviews;
use App\Livewire\Admin\AdminVoucherList;
use App\Livewire\Admin\SystemLogViewer;
use App\Http\Controllers\CollectionReportController;
use App\Http\Controllers\BillingFinanceDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CustomerDetailsController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MainSiteController;
use App\Http\Controllers\Payment\BkashPaymentController;
use App\Http\Controllers\Payment\NagadPaymentController;
use App\Http\Controllers\Payment\SslCommerzPaymentController;
use App\Http\Controllers\Portal\PortalVoucherController;
use App\Http\Controllers\SupportCenterController;
use App\Http\Controllers\Reseller\ResellerDashboardController;
use App\Http\Controllers\Reseller\MacResellerDashboardController;
use App\Livewire\AddressSetup;
use App\Livewire\Admin\ManageRole;
use App\Livewire\Admin\ManageTickets;
use App\Livewire\Admin\ManageUser;
use App\Livewire\CollectionEdit;
use App\Livewire\CommentSubmit;
use App\Livewire\CustomerList;
use App\Livewire\CustomerSummary;
use App\Livewire\EditCustomer;
use App\Livewire\MainSiteSetup;
use App\Livewire\Mikrotik\BackupManager;
use App\Livewire\Mikrotik\FirewallSetup;
use App\Livewire\Mikrotik\HotspotManager;
use App\Livewire\Mikrotik\InterfaceSetup;
use App\Livewire\Mikrotik\IpSetup;
use App\Livewire\Mikrotik\PppoeSetup;
use App\Livewire\Mikrotik\QueueSetup;
use App\Livewire\Mikrotik\RadiusSetup;
use App\Livewire\Mikrotik\RouterLogViewer;
use App\Livewire\Mikrotik\TrafficMonitor;
use App\Livewire\NetworkMap;
use App\Livewire\DeviceManager;
use App\Livewire\NetworkEvents;
use App\Livewire\NetworkTopology;
use App\Livewire\OpticalAudit;
use App\Livewire\HighUsageMonitor;
use App\Livewire\DeviceWatcher;
use App\Livewire\MikrotikLoginMessages;
use App\Livewire\Mikrotik\VpnSetup;
use App\Livewire\Mikrotik\WalledGardenSetup;
use App\Livewire\MikrotikSync;
use App\Livewire\MikrotikClients;
use App\Livewire\NewCustomer;
use App\Livewire\NotificationListAll;
use App\Livewire\PackageListSetup;
use App\Livewire\Payment\Invoice;
use App\Livewire\PaymentCollection;
use App\Livewire\Report\DisReport;
use App\Livewire\Reseller\ResellerCustomerList;
use App\Livewire\Reseller\ResellerPackageManagement;
use App\Livewire\Reseller\ResellerVoucherManagement;
use App\Livewire\Reseller\ResellerWalletManagement;
use App\Livewire\SMSSetup;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Http\Middleware\EnsureBillingPort;
use App\Http\Middleware\EnsurePortalPort;
use App\Http\Middleware\EnforceModulePermissions;

// Extract domain host from APP_URL for consistent subdomain routing
$baseDomain = parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url');

// Main domain
Route::domain($baseDomain)->group(function () {
    Route::get('/', function () {
        // Keep the public landing page on the default HTTP/HTTPS port, while
        // sending the dedicated billing and customer-portal ports to their login screens.
        $port = request()->getPort();
        $forwardedPort = request()->header('X-Forwarded-Port');
        if ($forwardedPort !== null && ctype_digit((string) $forwardedPort)) {
            $port = (int) $forwardedPort;
        }

        if ($port === 8081) {
            return redirect()->route('login');
        }

        if ($port === 8082) {
            return redirect()->to('http://'.request()->getHost().':8082/portal/login');
        }

        return app(MainSiteController::class)->index();
    })->name('welcome');
    Route::get('/all-packages', [MainSiteController::class, 'allPackages'])->name('all-packages');
    Route::get('/lang/{locale}', function ($locale) {
        if (in_array($locale, ['en', 'bn'])) {
            session()->put('main_site_locale', $locale);
        }
        return redirect()->back();
    })->name('welcome.lang');

    // Warning / Recharge page for expired users
    Route::get('/warning', function () {
        return view('warning');
    })->name('warning');

    // Public voucher redemption route on main domain
    Route::get('/recharge/voucher', [PortalVoucherController::class, 'showRechargeForm'])->name('welcome.voucher.recharge');
    Route::post('/recharge/voucher', [PortalVoucherController::class, 'redeem'])->name('welcome.voucher.redeem');

    Route::get('/portal', function () {
        $host = request()->getHost();
        if (Str::startsWith($host, 'portal.')) {
            return redirect('/');
        }

        return redirect()->to('http://'.$host.':8082/');
    });

    Route::get('/billing', function () {
        $host = request()->getHost();
        if (Str::startsWith($host, 'billing.')) {
            return redirect('/');
        }

        return redirect()->to('http://'.$host.':8081/');
    });
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    'restrict.profile',
    EnforceModulePermissions::class,
])->group(function () use ($baseDomain) {

    // billing domain
    Route::middleware(EnsureBillingPort::class)->group(function () {
        Route::get('/system/db-backup/download/{filename}', function ($filename) {
            if (str_contains($filename, '/') || str_contains($filename, '\\')) {
                abort(403, 'Invalid filename.');
            }
            $path = base_path('backups/'.$filename);
            if (file_exists($path)) {
                return response()->download($path);
            }
            abort(404, 'Backup file not found.');
        })->name('system.db-backup.download');

        Route::redirect('/', '/dashboard');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard-index', [DashboardController::class, 'index'])->name('dashboard.index');
        Route::get('/roles', ManageRole::class)->name('roles.index');
        Route::get('/users', ManageUser::class)->name('users.index');

        Route::get('/due-call-reminders', [\App\Http\Controllers\DueCallReminderController::class, 'index'])->name('due-call-reminders.index');
        Route::post('/due-call-reminders/settings', [\App\Http\Controllers\DueCallReminderController::class, 'save'])->name('due-call-reminders.save');
        Route::post('/due-call-reminders/run', [\App\Http\Controllers\DueCallReminderController::class, 'run'])->name('due-call-reminders.run');
        Route::post('/due-call-reminders/test-connection', [\App\Http\Controllers\DueCallReminderController::class, 'testConnection'])->name('due-call-reminders.test-connection');
        Route::post('/due-call-reminders/test-call', [\App\Http\Controllers\DueCallReminderController::class, 'testCall'])->name('due-call-reminders.test-call');

        // AI Engineer
        Route::get('/ai-engineer', [\App\Http\Controllers\AiEngineerController::class, 'index'])->name('ai-engineer');
        Route::post('/ai-engineer/diagnose', [\App\Http\Controllers\AiEngineerController::class, 'diagnose'])->name('ai-engineer.diagnose');
        Route::post('/ai-engineer/chat', [\App\Http\Controllers\AiEngineerController::class, 'chat'])->name('ai-engineer.chat');
        Route::get('/ai-engineer/search', [\App\Http\Controllers\AiEngineerController::class, 'search'])->name('ai-engineer.search');
        Route::post('/ai-engineer/feedback', [\App\Http\Controllers\AiEngineerController::class, 'feedback'])->name('ai-engineer.feedback');
        Route::resources([
            'collection-report' => CollectionReportController::class,
        ]);
        Route::get('customers/data', [CustomerList::class, 'getData'])->name('customers.data');
        Route::get('customers/{id}/edit', [CustomerList::class, 'edit'])->name('customers.edit');
        Route::get('customers/{id}/billing-data', [\App\Http\Controllers\CustomersController::class, 'show'])->name('customers.billing-data');
        Route::post('customers/{id}/enable', [\App\Http\Controllers\CustomersController::class, 'customerEnable'])->name('customers.enable');
        Route::patch('customers/{id}/billing', [\App\Http\Controllers\CustomersController::class, 'update'])->name('customers.update');
        Route::delete('customers/{id}', [\App\Http\Controllers\CustomersController::class, 'destroy'])->name('customers.destroy');
        Route::get('customer/{id}/actions/{action}', [\App\Http\Controllers\CustomerActionsController::class, 'show'])->name('customer.actions');
        Route::post('customer/{id}/actions/{action}', [\App\Http\Controllers\CustomerActionsController::class, 'handle'])->name('customer.actions.handle');
        Route::get('customer/{id}/live-traffic', [\App\Http\Controllers\CustomerActionsController::class, 'liveTraffic'])->name('customer.live-traffic');
        Route::get('customer/{id}/invoice', [\App\Http\Controllers\CustomerActionsController::class, 'invoicePage'])->name('customer.actions.invoice');
        Route::get('customer/{id}/pos-print', [\App\Http\Controllers\CustomerActionsController::class, 'printPage'])->name('customer.actions.print');
        Route::get('customers/{id}', [CustomerDetailsController::class, 'show'])->name('customers.show');
        Route::get('/customer-details/{id}', [CustomerDetailsController::class, 'show'])->name('customer.details');
        Route::get('customers', CustomerList::class)->name('customers.index');

        Route::get('/new/customers', CustomerList::class)->name('customers-new');
        Route::get('/admin-users', ManageUser::class)->name('admin-users');
        Route::get('/admin-roles', ManageRole::class)->name('admin-roles');
        Route::get('/support-tickets', ManageTickets::class)->name('admin-tickets');
        Route::get('/mikrotik', MikrotikSync::class)->name('mikrotik-sync');
        Route::get('/mikrotik-server', MikrotikSync::class)->name('mikrotik-server');
        Route::get('/mikrotik-server/backup', BackupManager::class)->name('mikrotik-server-backup');
        Route::get('/mikrotik-server/import', MikrotikClients::class)->name('mikrotik-server-import');
        Route::get('/mikrotik-server/bulk-import', CustomerList::class)->name('mikrotik-server-bulk-import');

        // Network Monitoring suite
        Route::get('/device-manager', DeviceManager::class)->name('device-manager');
        Route::get('/device-manager/{kind}/{device}', \App\Livewire\DeviceDetail::class)->name('device-manager.detail');
        Route::get('/network-events', NetworkEvents::class)->name('network-events');
        Route::get('/network-map', NetworkMap::class)->name('network-map');
        Route::get('/network-topology/designer', NetworkTopology::class)->name('network-topology.designer');
        Route::get('/network-topology/live', NetworkTopology::class)->defaults('mode', 'live')->name('network-topology.live');
        Route::get('/optical-audit', OpticalAudit::class)->name('optical-audit');
        Route::get('/traffic-monitor', TrafficMonitor::class)->name('traffic-monitor');
        Route::get('/high-usage-monitor', HighUsageMonitor::class)->name('high-usage-monitor');
        Route::get('/device-watcher', DeviceWatcher::class)->name('device-watcher');
        Route::get('/mikrotik-login-messages', MikrotikLoginMessages::class)->name('mikrotik-login-messages');

        // Mikrotik Setup Routes
        Route::prefix('mikrotik-setup')->group(function () {
            Route::get('/ip', IpSetup::class)->name('mikrotik-ip-setup');
            Route::get('/pppoe', PppoeSetup::class)->name('mikrotik-pppoe-setup');
            Route::get('/queue', QueueSetup::class)->name('mikrotik-queue-setup');
            Route::get('/firewall', FirewallSetup::class)->name('mikrotik-firewall-setup');
            Route::get('/hotspot', HotspotManager::class)->name('mikrotik-hotspot-setup'); // merged → HotspotManager
        Route::get('/hotspot-dashboard', HotspotManager::class)->name('hotspot-dashboard');
        Route::get('/hotspot-customer-add', HotspotManager::class)->name('hotspot-customer-add');
        Route::get('/hotspot-customers', HotspotManager::class)->name('hotspot-customers');
        Route::get('/hotspot-online-customers', HotspotManager::class)->name('hotspot-online-customers');
        Route::get('/hotspot-ledger', HotspotManager::class)->name('hotspot-ledger');
        Route::get('/hotspot-packages', HotspotManager::class)->name('hotspot-packages');
        Route::get('/hotspot-cards', HotspotManager::class)->name('hotspot-cards');
        Route::get('/hotspot-card-print-setup', HotspotManager::class)->name('hotspot-card-print-setup');
        Route::get('/hotspot-page-setup', HotspotManager::class)->name('hotspot-page-setup');
            Route::get('/hotspot-manager', HotspotManager::class)->name('mikrotik-hotspot-manager');
            Route::get('/radius', RadiusSetup::class)->name('mikrotik-radius-setup');
            Route::get('/vpn', VpnSetup::class)->name('mikrotik-vpn-setup');
            Route::get('/interface', InterfaceSetup::class)->name('mikrotik-interface-setup');
            Route::get('/traffic', TrafficMonitor::class)->name('mikrotik-traffic-monitor');
            Route::get('/logs', RouterLogViewer::class)->name('mikrotik-log-viewer');
            Route::get('/backup', BackupManager::class)->name('mikrotik-backup-setup');
            Route::get('/walled-garden', WalledGardenSetup::class)->name('mikrotik-walled-garden');
        });

        Route::get('/address', AddressSetup::class)->name('address-setup');
        Route::get('/packages', PackageListSetup::class)->name('package-list-setup');
        Route::get('/sms', SMSSetup::class)->name('sms-setup');
        Route::get('/create-customer', NewCustomer::class)->name('new-customer');

        // Broadband module — reference-aligned customer, package and import operations
        Route::prefix('broadband')->group(function () {
            Route::get('/customer-add', NewCustomer::class)->name('customer-add');
            Route::get('/customers', [\App\Http\Controllers\BroadbandController::class, 'list'])->name('broadband-customers');
            Route::get('/customer-search', [\App\Http\Controllers\BroadbandController::class, 'search'])->name('broadband-customer-search');
            Route::get('/online-customers', [\App\Http\Controllers\BroadbandController::class, 'list'])->defaults('status','online')->name('broadband-online-customers');
            Route::get('/due-customers', [\App\Http\Controllers\BroadbandController::class, 'due'])->name('broadband-due-customers');
            Route::get('/inactive-customers', [\App\Http\Controllers\BroadbandController::class, 'inactive'])->name('broadband-inactive-customers');
            Route::get('/new-customers', [\App\Http\Controllers\BroadbandController::class, 'newCustomers'])->name('broadband-new-customers');
            Route::get('/unverified-customers', [\App\Http\Controllers\BroadbandController::class, 'unverified'])->name('broadband-unverified-customers');
            Route::get('/packages', [\App\Http\Controllers\BroadbandController::class, 'packages'])->name('broadband-packages');
            Route::get('/customer-package-import', [\App\Http\Controllers\BroadbandController::class, 'import'])->name('broadband-customer-package-import');
            Route::post('/customers/{id}/disable', [\App\Http\Controllers\BroadbandController::class, 'disableCustomer'])->name('broadband-customer-disable');
            Route::patch('/customers/{id}/left-details', [\App\Http\Controllers\BroadbandController::class, 'updateLeftDetails'])->name('broadband-customer-left-details');
            Route::get('/customers/{id}/ping', [\App\Http\Controllers\BroadbandController::class, 'customerPing'])->name('broadband-customer-ping');
            Route::get('/customers/{id}/traffic', [\App\Http\Controllers\BroadbandController::class, 'customerTraffic'])->name('broadband-customer-traffic');
            Route::delete('/customers/{id}', [\App\Http\Controllers\BroadbandController::class, 'destroyCustomer'])->name('broadband-customer-destroy');
        });
        // Compatibility aliases matching the reference navigation
        Route::get('/customer-add', fn () => redirect()->route('customer-add'))->name('customer-add-alias');
        Route::get('/customer-search', fn () => redirect()->route('broadband-customer-search'))->name('customer-search');
        Route::get('/online-customers', fn () => redirect()->route('broadband-online-customers'))->name('online-customers');
        Route::get('/due-customers', fn () => redirect()->route('broadband-due-customers'))->name('due-customers');
        Route::get('/inactive-customers', fn () => redirect()->route('broadband-inactive-customers'))->name('inactive-customers');
        Route::get('/new-customers', fn () => redirect()->route('broadband-new-customers'))->name('new-customers');
        Route::get('/unverified-customers', fn () => redirect()->route('broadband-unverified-customers'))->name('unverified-customers');
        Route::get('/broadband-packages', fn () => redirect()->route('broadband-packages'))->name('broadband-packages-alias');
        Route::get('/customer-bulk-import', [\App\Http\Controllers\CustomerBulkImportController::class, 'index'])->name('customer-bulk-import');
        Route::get('/customer-bulk-import/sample/{format}', [\App\Http\Controllers\CustomerBulkImportController::class, 'downloadSample'])
            ->where('format', 'csv|xlsx|xls')->name('customer-bulk-import.sample');
        Route::post('/customer-bulk-import/preview', [\App\Http\Controllers\CustomerBulkImportController::class, 'preview'])->name('customer-bulk-import.preview');
        Route::post('/customer-bulk-import/validate', [\App\Http\Controllers\CustomerBulkImportController::class, 'validateMapping'])->name('customer-bulk-import.validate');
        Route::post('/customer-bulk-import/commit', [\App\Http\Controllers\CustomerBulkImportController::class, 'commit'])->name('customer-bulk-import.commit');
        Route::get('/customer-package-import', fn () => redirect()->route('broadband-customer-package-import'))->name('customer-package-import');

        // payment routes
        Route::get('/payment-collection', PaymentCollection::class)->name('payment-collection');
        Route::get('/payment-collection-edit', CollectionEdit::class)->name('collection-edit');
        Route::get('/payment-invoice', Invoice::class)->name('payment-invoice');

        // all report
        Route::get('/customer-summary', CustomerSummary::class)->name('customer-summary');
        Route::get('/report/dis-report-table', [DisReport::class, 'getData'])->name('dis-report-table');
        Route::get('/report/dis-report', DisReport::class)->name('dis-report');

        // site settings (consolidated)
        Route::get('/site-settings', MainSiteSetup::class)
            ->middleware(DispatchServingFilamentEvent::class)
            ->name('site-settings');

        // main site content management (deprecate name but keeps route if needed or redirection)
        Route::get('/main-site-setup', function () {
            return redirect()->route('site-settings');
        })->name('main-site-setup');

        // X-Link Billing control center with live operational KPIs.
        Route::get('/xlink-billing', function () {
            $customers = \App\Models\CustomersInfo::count();
            $routers = \App\Models\RouterList::count();
            $openTickets = \App\Models\SupportTicket::whereIn('status', ['open','pending','in_progress'])->count();
            $pendingRequests = \App\Models\PackagePurchaseRequest::whereIn('status', ['pending','requested'])->count();
            $watchers = \App\Models\DeviceWatcher::count();
            return view('xlink.index', [
                'kpis' => [
                    ['label'=>'Customers','value'=>$customers,'icon'=>'bi-people'],
                    ['label'=>'Routers','value'=>$routers,'icon'=>'bi-router'],
                    ['label'=>'Open Tickets','value'=>$openTickets,'icon'=>'bi-life-preserver'],
                    ['label'=>'Pending Requests','value'=>$pendingRequests,'icon'=>'bi-hourglass-split'],
                    ['label'=>'Device Watchers','value'=>$watchers,'icon'=>'bi-eye'],
                ],
                'modules' => [
                    ['Mobile Banking','xlink.mobile-banking','bi-phone'],
                    ['Partner Network','xlink.partner-network','bi-diagram-3'],
                    ['Bandwidth Reseller','xlink.bandwidth-reseller','bi-speedometer2'],
                    ['Devices Inventory','xlink.devices-inventory','bi-hdd-network'],
                    ['Stock Inventory','xlink.stock-inventory','bi-box-seam'],
                    ['Communication Center','xlink.communication-center','bi-chat-dots'],
                    ['Support Center','xlink.support-center','bi-headset'],
                    ['Team & Access','xlink.team-access','bi-people'],
                    ['System Settings','xlink.system-settings','bi-gear'],
                    ['Billing Helpline','xlink.billing-helpline','bi-telephone'],
                    ['Profile & Security','xlink.profile-security','bi-shield-lock'],
                    ['Network Map','network-map','bi-diagram-3'],
                    ['Traffic Monitor','traffic-monitor','bi-activity'],
                    ['High Usage Monitor','high-usage-monitor','bi-bar-chart-line'],
                    ['Device Watcher','device-watcher','bi-eye'],
                    ['Logs & Alerts','mikrotik-login-messages','bi-bell'],
                ],
            ]);
        })->name('xlink.index');

        // X-Link Billing functional module hubs — backed by existing production features.
        Route::get('/mobile-banking', fn () => view('xlink.module', [
            'title'=>'Mobile Banking','icon'=>'bi-phone','description'=>'Mobile payment gateways, SMS and collection operations.',
            'stats'=>[
                ['Gateway','Operational'],
                ['bKash',\App\Models\MainSiteData::getValue('payment_bkash_enabled', 0) ? 'Enabled' : 'Disabled'],
                ['Nagad',\App\Models\MainSiteData::getValue('payment_nagad_enabled', 0) ? 'Enabled' : 'Disabled'],
                ['SSLCommerz',\App\Models\MainSiteData::getValue('payment_sslcommerz_enabled', 0) ? 'Enabled' : 'Disabled'],
            ],
            'links'=>[[route('site-settings'),'Payment Gateway Settings','Configure bKash, Nagad and SSLCommerz'],[route('payment-collection'),'Payment Collection','Collection desk'],[route('payment-invoice'),'Invoices','Payment invoices']],
        ]))->name('xlink.mobile-banking');
        Route::get('/partner-network', [PartnerNetworkController::class, 'index'])->name('xlink.partner-network');
        Route::get('/reseller-cashflow', [PartnerNetworkController::class, 'cashflow'])->name('reseller-cashflow');
        Route::get('/reseller-ledger', [PartnerNetworkController::class, 'ledger'])->name('reseller-ledger');
        Route::get('/bandwidth-reseller', fn () => view('xlink.module', [
            'title'=>'Bandwidth Reseller','icon'=>'bi-speedometer2','description'=>'Reseller packages, wallet, vouchers and customer operations.',
            'stats'=>[
                ['Resellers',\App\Models\Reseller::count()],
                ['Customers',\App\Models\CustomersInfo::whereNotNull('reseller_id')->count()],
                ['Vouchers',\App\Models\Voucher::count()],
            ],
            'links'=>[[route('reseller.dashboard'),'Reseller Dashboard','Operations'],[route('reseller.packages.index'),'Packages','Package management'],[route('reseller.wallet.index'),'Wallet','Balances and transactions'],[route('reseller.vouchers.index'),'Vouchers','Voucher management']],
        ]))->name('xlink.bandwidth-reseller');
        Route::get('/network-inventory', function () {
            $routers = \App\Models\RouterList::orderBy('router_name')->get();
            $totalRouters = $routers->count();
            $onlineRouters = $routers->where('action', 'connected')->count();
            $offlineRouters = max(0, $totalRouters - $onlineRouters);
            $routerAttention = $routers->where('action', '!=', 'connected')->take(5)->map(fn ($router) => (object)[
                'device_type' => 'MikroTik', 'display_name' => $router->router_name, 'ip_address' => $router->ip_address,
                'health_status' => 'down', 'last_latency_ms' => null, 'last_checked_at' => $router->updated_at,
            ]);
            $deviceAttention = \App\Models\NetworkInventoryDevice::query()->where('monitor_enabled', true)
                ->where(function ($q) { $q->whereIn('health_status', ['down','degraded'])->orWhere('status', 'offline'); })
                ->orderByDesc('last_checked_at')->take(10)->get()->map(fn ($device) => (object)[
                    'device_type' => strtoupper(str_replace('-', ' ', $device->type)), 'display_name' => $device->name,
                    'ip_address' => $device->ip_address, 'health_status' => $device->health_status ?: $device->status,
                    'last_latency_ms' => $device->last_latency_ms, 'last_checked_at' => $device->last_checked_at,
                ]);
            $attention = $routerAttention->merge($deviceAttention)->take(8);
            $healthModel = \App\Models\NetworkInventoryHealthCheck::query();
            $health24 = (clone $healthModel)->where('checked_at', '>=', now()->subDay())->get();
            $health7 = (clone $healthModel)->where('checked_at', '>=', now()->subDays(7))->get();
            $uptime = function ($rows) {
                $total = $rows->count();
                $up = $rows->where('status', 'online')->count();
                return $total ? round(($up / $total) * 100, 2) : null;
            };
            $incidentCount = $health7->whereIn('status', ['down','degraded'])->count();
            $avgLatency = $health24->whereNotNull('latency_ms')->avg('latency_ms');

            return view('xlink.network-inventory', [
                'routerTotal' => $totalRouters,
                'routerOnline' => $onlineRouters,
                'oltTotal' => (int) \App\Models\NetworkInventoryDevice::type('olt')->count(),
                'oltOnline' => (int) \App\Models\NetworkInventoryDevice::type('olt')->where('status','online')->count(),
                'switchTotal' => (int) \App\Models\NetworkInventoryDevice::type('switch')->count(),
                'switchOnline' => (int) \App\Models\NetworkInventoryDevice::type('switch')->where('status','online')->count(),
                'apTotal' => (int) \App\Models\NetworkInventoryDevice::type('access-point')->count(),
                'apOnline' => (int) \App\Models\NetworkInventoryDevice::type('access-point')->where('status','online')->count(),
                'attention' => $attention,
                'offlineCount' => $offlineRouters,
                'uptime24' => $uptime($health24),
                'uptime7' => $uptime($health7),
                'incidentCount' => $incidentCount,
                'avgLatency' => $avgLatency !== null ? round($avgLatency, 1) : null,
            ]);
        })->name('network-inventory');

        Route::get('/devices-inventory', fn () => redirect()->route('network-inventory'))
            ->name('xlink.devices-inventory');
        Route::get('/network-inventory/health-history', function () {
            $model = \App\Models\NetworkInventoryHealthCheck::query();
            $health24 = (clone $model)->where('checked_at', '>=', now()->subDay())->get();
            $health7 = (clone $model)->where('checked_at', '>=', now()->subDays(7))->get();
            $uptime = fn ($rows) => $rows->count() ? round(($rows->where('status','online')->count() / $rows->count()) * 100, 2) : null;
            $checks = (clone $model)->with('device')->latest('checked_at')->limit(100)->get();
            return view('xlink.health-history', [
                'checks' => $checks, 'uptime24' => $uptime($health24), 'uptime7' => $uptime($health7),
                'incidentCount' => $health7->whereIn('status', ['down','degraded'])->count(),
                'avgLatency' => $health24->whereNotNull('latency_ms')->count() ? round($health24->whereNotNull('latency_ms')->avg('latency_ms'), 1) : null,
            ]);
        })->name('network-inventory.health-history');

        Route::get('/network-inventory/mikrotik-management', function () {
            $routers = \App\Models\RouterList::orderBy('router_name')->get();
            return view('xlink.mikrotik-management', [
                'routers' => $routers,
                'online' => $routers->where('action', 'connected')->count(),
            ]);
        })->name('network-inventory.mikrotik');


        Route::get('/network-inventory/device/{type}', function (string $type) {
            abort_unless(in_array($type, ['olt','switch','access-point'], true), 404);
            $label = ['olt'=>'OLT','switch'=>'Switch','access-point'=>'Access Point'][$type];
            $query = \App\Models\NetworkInventoryDevice::type($type);
            if ($search = trim((string) request('q', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('ip_address', 'like', "%{$search}%")
                      ->orWhere('vendor', 'like', "%{$search}%")
                      ->orWhere('model', 'like', "%{$search}%")
                      ->orWhere('location', 'like', "%{$search}%");
                });
            }
            if (in_array(request('status'), ['online','offline','unknown'], true)) {
                $query->where('status', request('status'));
            }
            $perPage = min(max((int) request('per_page', 10), 10), 50);
            $devices = $query->orderBy('name')->paginate($perPage)->withQueryString();
            return view('xlink.inventory-list', compact('devices','type','label'));
        })->name('network-inventory.devices');

        Route::post('/network-inventory/device/{type}', function (\Illuminate\Http\Request $request, string $type) {
            abort_unless(in_array($type, ['olt','switch','access-point'], true), 404);
            $data = $request->validate([
                'name' => ['required','string','max:120'],
                'ip_address' => ['nullable','ip'],
                'vendor' => ['nullable','string','max:80'],
                'model' => ['nullable','string','max:120'],
                'onu_total' => ['nullable','integer','min:0'],
                'onu_online' => ['nullable','integer','min:0'],
                'rx_power' => ['nullable','numeric','between:-99.99,99.99'],
                'customer_count' => ['nullable','integer','min:0'],
                'status' => ['required','in:online,offline,unknown'],
                'location' => ['nullable','string','max:160'],
                'notes' => ['nullable','string','max:1000'],
                'health_port' => ['nullable','integer','min:1','max:65535'],
                'snmp_port' => ['nullable','integer','min:1','max:65535'],
                'latitude' => ['nullable','numeric','between:-90,90'],
                'longitude' => ['nullable','numeric','between:-180,180'],
                'onu_total' => ['nullable','integer','min:0'],
                'onu_online' => ['nullable','integer','min:0'],
                'rx_power' => ['nullable','numeric'],
                'customer_count' => ['nullable','integer','min:0'],
                'monitor_enabled' => ['nullable','boolean'],
            ]);
            if ($type === 'olt') $data['snmp_port'] = (int) ($data['snmp_port'] ?? 161);
            \App\Models\NetworkInventoryDevice::create($data + [
                'type' => $type,
                'monitor_enabled' => (bool) ($data['monitor_enabled'] ?? false),
            ]);
            return back()->with('inventory_message', ucfirst(str_replace('-', ' ', $type)).' device added.');
        })->name('network-inventory.devices.store');

        Route::put('/network-inventory/device/{type}/{device}', function (\Illuminate\Http\Request $request, string $type, \App\Models\NetworkInventoryDevice $device) {
            abort_unless(in_array($type, ['olt','switch','access-point'], true) && $device->type === $type, 404);
            $data = $request->validate([
                'name' => ['required','string','max:120'], 'ip_address' => ['nullable','ip'],
                'vendor' => ['nullable','string','max:80'], 'model' => ['nullable','string','max:120'],
                'status' => ['required','in:online,offline,unknown'], 'location' => ['nullable','string','max:160'],
                'notes' => ['nullable','string','max:1000'],
                'health_port' => ['nullable','integer','min:1','max:65535'],
                'snmp_port' => ['nullable','integer','min:1','max:65535'],
                'latitude' => ['nullable','numeric','between:-90,90'],
                'longitude' => ['nullable','numeric','between:-180,180'],
                'onu_total' => ['nullable','integer','min:0'],
                'onu_online' => ['nullable','integer','min:0'],
                'rx_power' => ['nullable','numeric'],
                'customer_count' => ['nullable','integer','min:0'],
                'monitor_enabled' => ['nullable','boolean'],
            ]);
            if ($type === 'olt') $data['snmp_port'] = (int) ($data['snmp_port'] ?? $device->snmp_port ?? 161);
            $device->update($data + ['monitor_enabled' => (bool) ($data['monitor_enabled'] ?? false)]);
            return back()->with('inventory_message', 'Inventory record updated.');
        })->name('network-inventory.devices.update');

        Route::post('/network-inventory/device/{type}/bulk-status', function (\Illuminate\Http\Request $request, string $type) {
            abort_unless(in_array($type, ['olt','switch','access-point'], true), 404);
            $data = $request->validate(['device_ids'=>['required','array','min:1'],'device_ids.*'=>['integer'],'status'=>['required','in:online,offline,unknown']]);
            \App\Models\NetworkInventoryDevice::type($type)->whereIn('id',$data['device_ids'])->update(['status'=>$data['status']]);
            return back()->with('inventory_message', count($data['device_ids']).' device status records updated.');
        })->name('network-inventory.devices.bulk-status');

        Route::delete('/network-inventory/device/{type}/{device}', function (string $type, \App\Models\NetworkInventoryDevice $device) {
            abort_unless(in_array($type, ['olt','switch','access-point'], true) && $device->type === $type, 404);
            $device->delete();
            return back()->with('inventory_message', 'Inventory record deleted.');
        })->name('network-inventory.devices.destroy');

        Route::post('/network-inventory/olt/sync', function () {
            \Illuminate\Support\Facades\Artisan::call('olt:sync-onu-mappings');
            return back()->with('onu_sync_message', trim(\Illuminate\Support\Facades\Artisan::output()) ?: 'ONU mapping synchronization completed.')->with('onu_sync_success', true);
        })->name('network-inventory.olt.sync');

        Route::get('/network-inventory/olt-management', function () {
            $query = \App\Models\NetworkInventoryDevice::type('olt')->orderBy('name');
            if ($q = trim((string) request('q',''))) {
                $query->where(function($x) use ($q) {
                    $x->where('name','like',"%{$q}%")->orWhere('ip_address','like',"%{$q}%")->orWhere('vendor','like',"%{$q}%")->orWhere('model','like',"%{$q}%")->orWhere('location','like',"%{$q}%");
                });
            }
            $vendor = trim((string) request('vendor',''));
            $ponType = trim((string) request('pon_type',''));
            if ($vendor !== '') $query->where('vendor',$vendor);
            if ($ponType !== '') $query->where('olt_type_id',$ponType);
            if (in_array(request('status'),['online','offline','unknown'],true)) $query->where('status',request('status'));
            $devices=$query->paginate(20)->withQueryString();
            $vendors = \App\Models\NetworkInventoryDevice::type('olt')->whereNotNull('vendor')->where('vendor','<>','')->distinct()->orderBy('vendor')->pluck('vendor');
            $ponTypes = \App\Models\NetworkInventoryDevice::type('olt')->whereNotNull('olt_type_id')->where('olt_type_id','<>','')->distinct()->orderBy('olt_type_id')->pluck('olt_type_id');
            $allOlts = \App\Models\NetworkInventoryDevice::type('olt');
            $oltStats = [
                'total' => (clone $allOlts)->count(),
                'online' => (clone $allOlts)->where('status','online')->count(),
                'offline' => (clone $allOlts)->where('status','offline')->count(),
                'onu_total' => \App\Models\OltOnuCustomerMapping::count(),
                'onu_online' => \App\Models\OltOnuCustomerMapping::where('status','online')->count(),
                'mapped' => \App\Models\OltOnuCustomerMapping::whereNotNull('customer_id')->count(),
            ];
            $onuCountsByOlt = \App\Models\OltOnuCustomerMapping::selectRaw("olt_device_id, COUNT(*) as total, SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online, SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END) as mapped")
                ->groupBy('olt_device_id')->get()->keyBy('olt_device_id')->map(fn($row) => ['total'=>(int)$row->total,'online'=>(int)$row->online,'mapped'=>(int)$row->mapped])->all();
            return view('xlink.olts',['devices'=>$devices,'oltStats'=>$oltStats,'onuCountsByOlt'=>$onuCountsByOlt,'vendors'=>$vendors,'ponTypes'=>$ponTypes]);
        })->name('network-inventory.olt');

        Route::get('/olts', fn () => redirect()->route('network-inventory.olt'))->name('olts');
        Route::get('/network-inventory/olt/add', fn () => view('xlink.olt-form',['device'=>new \App\Models\NetworkInventoryDevice(['type'=>'olt','status'=>'unknown','is_active'=>true]),'edit'=>false]))->name('network-inventory.olt.add');
        Route::post('/network-inventory/olt', function(\Illuminate\Http\Request $request){
            $data=$request->validate([
                'name'=>['required','string','max:120'],'olt_type_id'=>['nullable','string','max:120'],'host'=>['nullable','string','max:255'],
                'ip_address'=>['nullable','ip'],'port'=>['nullable','integer','min:1','max:65535'],'username'=>['nullable','string','max:120'],'password'=>['nullable','string','max:128'],
                'ssh_port'=>['nullable','integer','min:1','max:65535'],'snmp_port'=>['nullable','integer','min:1','max:65535'],'snmp_version'=>['nullable','in:1,2C,3'],'snmp_community'=>['nullable','string','max:255'],'ssh_enabled'=>['nullable','boolean'],'provisioning_enabled'=>['nullable','boolean'],
                'model'=>['nullable','string','max:120'],'firmware'=>['nullable','string','max:120'],'serial_no'=>['nullable','string','max:160'],'vendor'=>['nullable','string','max:80'],
                'pon_ports'=>['nullable','integer','min:0'],'ge_ports'=>['nullable','integer','min:0'],'sfp_ports'=>['nullable','integer','min:0'],'sfp_plus_ports'=>['nullable','integer','min:0'],
                'web_protocol'=>['nullable','in:http,https'],'web_port'=>['nullable','integer','min:1','max:65535'],'connect_timeout'=>['nullable','integer','min:1'],'cli_timeout'=>['nullable','integer','min:1'],'read_delay_ms'=>['nullable','integer','min:0'],
                'diagnostic_command'=>['nullable','string','max:1000'],'adapter_config'=>['nullable','string','max:2000'],'latitude'=>['nullable','numeric','between:-90,90'],'longitude'=>['nullable','numeric','between:-180,180'],'location'=>['nullable','string','max:255'],
                'onu_total'=>['nullable','integer','min:0'],'onu_online'=>['nullable','integer','min:0'],'rx_power'=>['nullable','numeric'],'customer_count'=>['nullable','integer','min:0'],'status'=>['required','in:online,offline,unknown'],'health_port'=>['nullable','integer','min:1','max:65535'],'notes'=>['nullable','string','max:2000'],'is_active'=>['nullable','boolean'],'snmp_version'=>['nullable','in:1,2C,3'],'snmp_community'=>['nullable','string','max:255'],'api_token'=>['nullable','string','max:2000'],'api_endpoint'=>['nullable','string','max:1000'],'ssh_port'=>['nullable','integer','min:1','max:65535'],'telnet_port'=>['nullable','integer','min:1','max:65535'],'api_port'=>['nullable','integer','min:1','max:65535'],'ssh_enabled'=>['nullable','boolean'],'telnet_enabled'=>['nullable','boolean'],'api_enabled'=>['nullable','boolean'],'provisioning_enabled'=>['nullable','boolean'],
            ]);
            $data['type']='olt'; $data['ip_address']=$data['ip_address']??($data['host']??null); $data['is_active']=(bool)($data['is_active']??false);
            $data['ssh_enabled']=(bool)($data['ssh_enabled']??false); $data['provisioning_enabled']=(bool)($data['provisioning_enabled']??false);
            $data['ssh_port']=(int)($data['ssh_port']??22); $data['snmp_port']=(int)($data['snmp_port']??161); $data['snmp_version']=$data['snmp_version']??'2C';
            foreach (['username','password','snmp_community'] as $secretField) { if (!empty($data[$secretField])) $data[$secretField]=\Illuminate\Support\Facades\Crypt::encryptString((string)$data[$secretField]); else unset($data[$secretField]); }
            \App\Models\NetworkInventoryDevice::create($data); return redirect()->route('network-inventory.olt')->with('inventory_message','OLT created successfully.');
        })->name('network-inventory.olt.store');
        Route::get('/network-inventory/olt/{device}/edit', function(\App\Models\NetworkInventoryDevice $device){ abort_unless($device->type==='olt',404); return view('xlink.olt-form',['device'=>$device,'edit'=>true]); })->name('network-inventory.olt.edit');
        Route::put('/network-inventory/olt/{device}', function(\Illuminate\Http\Request $request, \App\Models\NetworkInventoryDevice $device){
            abort_unless($device->type==='olt',404); $data=$request->validate(['name'=>['required','string','max:120'],'olt_type_id'=>['nullable','string','max:120'],'host'=>['nullable','string','max:255'],'ip_address'=>['nullable','ip'],'port'=>['nullable','integer','min:1','max:65535'],'username'=>['nullable','string','max:120'],'password'=>['nullable','string','max:128'],'ssh_port'=>['nullable','integer','min:1','max:65535'],'snmp_port'=>['nullable','integer','min:1','max:65535'],'snmp_version'=>['nullable','in:1,2C,3'],'snmp_community'=>['nullable','string','max:255'],'ssh_enabled'=>['nullable','boolean'],'provisioning_enabled'=>['nullable','boolean'],'model'=>['nullable','string','max:120'],'firmware'=>['nullable','string','max:120'],'serial_no'=>['nullable','string','max:160'],'vendor'=>['nullable','string','max:80'],'pon_ports'=>['nullable','integer','min:0'],'ge_ports'=>['nullable','integer','min:0'],'sfp_ports'=>['nullable','integer','min:0'],'sfp_plus_ports'=>['nullable','integer','min:0'],'web_protocol'=>['nullable','in:http,https'],'web_port'=>['nullable','integer','min:1','max:65535'],'connect_timeout'=>['nullable','integer','min:1'],'cli_timeout'=>['nullable','integer','min:1'],'read_delay_ms'=>['nullable','integer','min:0'],'diagnostic_command'=>['nullable','string','max:1000'],'adapter_config'=>['nullable','string','max:2000'],'latitude'=>['nullable','numeric','between:-90,90'],'longitude'=>['nullable','numeric','between:-180,180'],'location'=>['nullable','string','max:255'],'onu_total'=>['nullable','integer','min:0'],'onu_online'=>['nullable','integer','min:0'],'rx_power'=>['nullable','numeric'],'customer_count'=>['nullable','integer','min:0'],'status'=>['required','in:online,offline,unknown'],'health_port'=>['nullable','integer','min:1','max:65535'],'notes'=>['nullable','string','max:2000'],'is_active'=>['nullable','boolean']]);
            foreach (['username','password','snmp_community'] as $secretField) { if (empty($data[$secretField])) unset($data[$secretField]); else $data[$secretField]=\Illuminate\Support\Facades\Crypt::encryptString((string)$data[$secretField]); }
            $data['ssh_enabled']=(bool)($data['ssh_enabled']??false); $data['provisioning_enabled']=(bool)($data['provisioning_enabled']??false);
            $data['ssh_port']=(int)($data['ssh_port']??22); $data['snmp_port']=(int)($data['snmp_port']??$device->snmp_port??161); $data['snmp_version']=$data['snmp_version']??'2C';
            $data['ip_address']=$data['ip_address']??($data['host']??$device->ip_address); $data['is_active']=(bool)($data['is_active']??false); $device->update($data); return redirect()->route('network-inventory.olt')->with('inventory_message','OLT updated successfully.');
        })->name('network-inventory.olt.update');
        Route::get('/network-inventory/olt/{device}/customers', function(\App\Models\NetworkInventoryDevice $device){
            abort_unless($device->type==='olt',404);
            $q=trim((string)request('q',''));
            $status=(string)request('status','');
            $mappings=\App\Models\OltOnuCustomerMapping::with(['customer','pppUser'])->where('olt_device_id',$device->id)->latest('id')->get();
            $inventoryMappings = $mappings;
            if($q!=='') $mappings=$mappings->filter(function($m) use($q){ $hay=implode(' ',[(string)$m->onu_id,(string)$m->onu_serial,(string)$m->onu_mac,(string)$m->pon_port,(string)optional($m->customer)->customer_unique_id,(string)optional($m->customer)->customer_name,(string)optional($m->pppUser)->username]); return stripos($hay,$q)!==false; })->values();
            if(in_array($status,['online','offline','unknown'],true)) $mappings=$mappings->where('status',$status)->values();
            $normalizeStable = static fn ($value) => strtolower(preg_replace('/[^a-z0-9]/i','',(string) $value));
            $mappingByOnu=collect();
            foreach($inventoryMappings as $m){
                $macKey=$normalizeStable($m->onu_mac);
                $serialKey=strtolower(trim((string)$m->onu_serial));
                if($macKey!=='') $mappingByOnu->put('mac:'.$macKey,$m);
                if($serialKey!=='') $mappingByOnu->put('serial:'.$serialKey,$m);
                $mappingByOnu->put('id:'.(string)$m->onu_id,$m);
            }
            $customers=\App\Models\CustomersInfo::with('pppUser')->orderBy('customer_unique_id')->get();
            // Never perform a blocking SNMP/SSH poll inside this page request. The inventory
            // sync command records the latest known ONU rows; present those immediately.
            $live=[
                'status'=>'cached',
                'onus'=>$inventoryMappings->map(static fn($m)=>[
                    'onu_id'=>(string)$m->onu_id,
                    'onu_serial'=>(string)($m->onu_serial ?? ''),
                    'onu_mac'=>(string)($m->onu_mac ?? ''),
                    'pon_port'=>(string)($m->pon_port ?? ''),
                    'status'=>(string)($m->status ?: 'unknown'),
                    'rx_power'=>$m->rx_power,
                    'tx_power'=>$m->tx_power,
                    'client_macs'=>[],
                ])->values()->all(),
                'error'=>null,
                'meta'=>['cached'=>true,'opm_status'=>'cached'],
            ];
            // Read-only candidate matching: surface only unambiguous evidence-based
            // suggestions on this OLT; no customer mapping is changed here.
            $candidateService = app(\App\Services\AiEngineerDiagnosticService::class);
            $candidateResult = $candidateService->matchingCandidates(200);
            $matchingCandidates = collect($candidateResult['matches'] ?? [])
                ->filter(fn ($match) => (int) ($match['olt_device_id'] ?? 0) === (int) $device->id)
                ->keyBy(fn ($match) => (string) ($match['mapping_id'] ?? ''))
                ->all();
            $recommendedCustomerId=(string)request('customer_id','');

            // Detect reused Customer/PPPoE assignments without changing existing data.
            $customerMapCounts=$mappings->whereNotNull('customer_id')->groupBy('customer_id');
            $pppMapCounts=$mappings->whereNotNull('ppp_user_id')->groupBy('ppp_user_id');
            $mappingConflictIds=$mappings->filter(function($m) use($customerMapCounts,$pppMapCounts){
                return ($m->customer_id && (($customerMapCounts->get($m->customer_id)?->count() ?? 0) > 1))
                    || ($m->ppp_user_id && (($pppMapCounts->get($m->ppp_user_id)?->count() ?? 0) > 1));
            })->pluck('id')->map(fn($id)=>(int)$id)->all();

            return view('xlink.olt-customers',compact('device','mappings','mappingByOnu','customers','live','q','status','matchingCandidates','recommendedCustomerId','mappingConflictIds'));
        })->name('network-inventory.olt.customers');
        Route::post('/network-inventory/olt/{device}/customers/mapping', function(\Illuminate\Http\Request $request, \App\Models\NetworkInventoryDevice $device){
            abort_unless($device->type==='olt',404);
            $data=$request->validate(['onu_id'=>'required|string|max:120','onu_mac'=>'nullable|string|max:80','onu_serial'=>'nullable|string|max:160','pon_port'=>'nullable|string|max:80','customer_id'=>'required|integer|exists:customers_infos,id','confirm_overwrite'=>'nullable|boolean','confirm_reuse'=>'nullable|boolean']);
            $normalizeStable = static fn ($value) => strtolower(preg_replace('/[^a-z0-9]/i','',(string)$value));
            $macKey=$normalizeStable($data['onu_mac']??'');
            $serialKey=strtolower(trim((string)($data['onu_serial']??'')));
            $mapping=null;
            $existing=\App\Models\OltOnuCustomerMapping::where('olt_device_id',$device->id)->get();
            if($macKey!=='') $mapping=$existing->first(fn($m)=>$normalizeStable($m->onu_mac)===$macKey);
            if(!$mapping && $serialKey!=='') $mapping=$existing->first(fn($m)=>strtolower(trim((string)$m->onu_serial))===$serialKey);
            if(!$mapping) $mapping=$existing->first(fn($m)=>(string)$m->onu_id===(string)$data['onu_id']);
            if($mapping && $mapping->customer_id && !$request->boolean('confirm_overwrite')) return back()->with('inventory_message','Existing mapping found. Confirm replacement before saving.')->with('onu_sync_success',false);
            $customer=\App\Models\CustomersInfo::with('pppUser')->findOrFail($data['customer_id']);
            $reuseQuery=\App\Models\OltOnuCustomerMapping::where('olt_device_id',$device->id);
            if($mapping) $reuseQuery->where('id','<>',$mapping->id);
            $sameCustomer=(clone $reuseQuery)->where('customer_id',$customer->id)->exists();
            $samePpp=$customer->ppp_user_id ? (clone $reuseQuery)->where('ppp_user_id',$customer->ppp_user_id)->exists() : false;
            if(($sameCustomer || $samePpp) && !$request->boolean('confirm_reuse')) {
                $who=$sameCustomer && $samePpp ? 'Customer and PPPoE' : ($sameCustomer ? 'Customer' : 'PPPoE');
                return back()->with('inventory_message',"This {$who} is already mapped to another ONU. Confirm reuse before saving.")
                    ->with('onu_sync_success',false);
            }
            $payload=['customer_id'=>$customer->id,'ppp_user_id'=>$customer->ppp_user_id,'onu_serial'=>$data['onu_serial']??$mapping?->onu_serial,'onu_mac'=>$data['onu_mac']??$mapping?->onu_mac,'pon_port'=>$data['pon_port']??$mapping?->pon_port,'status'=>$mapping?->status ?? 'unknown','last_seen_at'=>$mapping?->last_seen_at ?? now()];
            if($mapping){
                $idConflict=(string)$mapping->onu_id!==(string)$data['onu_id'] && \App\Models\OltOnuCustomerMapping::where('olt_device_id',$device->id)->where('onu_id',$data['onu_id'])->where('id','<>',$mapping->id)->exists();
                if($idConflict) {
                    return back()->with('inventory_message','The requested ONU ID already belongs to another ONU on this OLT. Refresh the ONU list and select the correct device.')
                        ->with('onu_sync_success',false);
                }
                $mapping->onu_id=$data['onu_id'];
                $mapping->fill($payload); $mapping->save();
            } else {
                $mapping=\App\Models\OltOnuCustomerMapping::create($payload+['olt_device_id'=>$device->id,'onu_id'=>$data['onu_id']]);
            }
            return back()->with('inventory_message','ONU/customer mapping saved successfully.')->with('onu_sync_success',true);
        })->name('network-inventory.olt.customers.mapping');
        Route::get('/network-inventory/olt/{device}/provisioning', [\App\Http\Controllers\OltProvisioningController::class, 'index'])->name('network-inventory.olt.provisioning');
        Route::post('/network-inventory/olt/{device}/provisioning/discover', [\App\Http\Controllers\OltProvisioningController::class, 'discover'])->name('network-inventory.olt.provisioning.discover');
        Route::post('/network-inventory/olt/{device}/provisioning/execute', [\App\Http\Controllers\OltProvisioningController::class, 'execute'])->name('network-inventory.olt.provisioning.execute');
        Route::post('/network-inventory/olt/{device}/discover', [\App\Http\Controllers\OltProvisioningController::class, 'discover'])->name('network-inventory.olt.discover');
        Route::post('/network-inventory/olt/{device}/provision', [\App\Http\Controllers\OltProvisioningController::class, 'execute'])->name('network-inventory.olt.provision');
        Route::get('/network-inventory/olt/{device}/diagnostics', function(\App\Models\NetworkInventoryDevice $device){
            abort_unless($device->type==='olt',404);

            // Never perform a live SNMP walk during page rendering. An unreachable
            // OLT can otherwise hold PHP-FPM until Nginx returns 504. Render the
            // last saved read-only ONU/OPM snapshot; refresh telemetry separately.
            $storedMappings=\App\Models\OltOnuCustomerMapping::where('olt_device_id',$device->id)->get();
            $cachedRows=$storedMappings->map(fn($m)=>[
                'onu_id'=>$m->onu_id, 'pon_port'=>$m->pon_port, 'onu_mac'=>$m->onu_mac,
                // A stored number without an OPM timestamp is historical/unknown,
                // not a valid current signal reading. Never present it as live.
                'tx_power'=>$m->opm_last_seen_at ? $m->tx_power : null,
                'rx_power'=>$m->opm_last_seen_at ? $m->rx_power : null,
            ])->values()->all();
            $storedOpmCount=collect($cachedRows)->filter(fn($r)=>is_numeric($r['tx_power']??null)||is_numeric($r['rx_power']??null))->count();
            $opm=[
                'status'=>$storedOpmCount > 0 ? 'stored' : 'not_ready',
                'onus'=>$cachedRows,
                'meta'=>[
                    'opm_status'=>$storedOpmCount > 0 ? 'stored' : 'not_ready',
                    'opm_message'=>$storedOpmCount > 0
                        ? "Showing {$storedOpmCount} ONU rows with saved optical-power values. Live SNMP is not called during page load."
                        : 'No saved optical-power sample is available. Showing saved ONU mappings without blocking on live SNMP.',
                ],
            ];

            // When live CLI OPM is unavailable, use the last stored read-only
            // MAC/TX/RX snapshot. Stable MAC is the primary key; PON+ONU ID
            // is only a fallback for rows that have no MAC.
            $storedMappings=\App\Models\OltOnuCustomerMapping::where('olt_device_id',$device->id)->get();
            $storedByMac=$storedMappings->filter(fn($m)=>trim((string)$m->onu_mac)!=='')
                ->keyBy(fn($m)=>strtolower(preg_replace('/[^a-f0-9]/i','',(string)$m->onu_mac)));
            $storedByKey=$storedMappings->keyBy(fn($m)=>(string)$m->pon_port.':'.(string)$m->onu_id);

            foreach(($opm['onus']??[]) as &$liveRow){
                $macKey=strtolower(preg_replace('/[^a-f0-9]/i','',(string)($liveRow['onu_mac']??'')));
                $stored=$macKey!=='' ? $storedByMac->get($macKey) : null;
                if(!$stored){
                    $key=(string)($liveRow['pon_port']??'').':'.(string)($liveRow['onu_id']??'');
                    $stored=$storedByKey->get($key);
                }
                if($stored){
                    // Only reuse a saved OPM value when its measurement timestamp exists.
                    // A historical number with no measurement time must never look live.
                    if($stored->opm_last_seen_at && !is_numeric($liveRow['tx_power']??null) && is_numeric($stored->tx_power)) $liveRow['tx_power']=(float)$stored->tx_power;
                    if($stored->opm_last_seen_at && !is_numeric($liveRow['rx_power']??null) && is_numeric($stored->rx_power)) $liveRow['rx_power']=(float)$stored->rx_power;
                    if(trim((string)($liveRow['onu_mac']??''))==='' && trim((string)$stored->onu_mac)!=='') $liveRow['onu_mac']=$stored->onu_mac;
                    if($stored->opm_last_seen_at && (is_numeric($stored->tx_power) || is_numeric($stored->rx_power))) $liveRow['stored_opm']=true;
                }
            }
            unset($liveRow);

            $storedOpmCount=collect($opm['onus']??[])->filter(fn($r)=>is_numeric($r['tx_power']??null)||is_numeric($r['rx_power']??null))->count();
            if(($opm['meta']['opm_status']??'')!=='ok' && $storedOpmCount>0){
                $opm['meta']['opm_status']='stored';
                $opm['meta']['opm_message']="Showing {$storedOpmCount} ONU rows from the last saved read-only MAC/TX/RX snapshot. This page does not poll the OLT; update telemetry separately to refresh these values.";
            }

            $q=trim((string)request('opm_q',''));
            $pon=trim((string)request('opm_pon',''));
            $rows=collect($opm['onus']??[])->filter(function($row) use($q,$pon){
                if($pon!=='' && (string)($row['pon_port']??'') !== $pon) return false;
                if($q==='') return true;
                $hay=implode(' ',[(string)($row['onu_id']??''),(string)($row['onu_mac']??''),(string)($row['onu_serial']??''),(string)($row['description']??''),(string)($row['onu_model']??''),(string)($row['onu_vendor']??'')]);
                return stripos($hay,$q)!==false;
            })->values();
            $ponOptions=collect($opm['onus']??[])->pluck('pon_port')->filter(fn($v)=>(string)$v!=='')->map(fn($v)=>(string)$v)->unique()->sort(SORT_NATURAL)->values();
            return view('xlink.olt-diagnostics',compact('device','opm','rows','ponOptions','q','pon'));
        })->name('network-inventory.olt.diagnostics');
        Route::post('/network-inventory/olt/{device}/diagnostic-check', function(\App\Models\NetworkInventoryDevice $device, \App\Services\NetworkInventoryHealthProbe $probe){
            abort_unless($device->type==='olt',404);
            $host=$device->ip_address ?: $device->host;
            $result=$probe->check($device);
            $probeStatus=(string)($result['status'] ?? 'down');
            $statusLabel=strtoupper((string)($result['protocol'] ?? 'connectivity'));
            $port=(int)($result['port'] ?? $device->snmp_port ?? 161);
            $healthStatus=$probeStatus==='online'?'ready':($probeStatus==='not_ready'?'unknown':'failed');
            $deviceStatus=$probeStatus==='online'?'online':($probeStatus==='not_ready'?'unknown':'offline');
            $device->update(['status'=>$deviceStatus,'health_status'=>$healthStatus,'last_latency_ms'=>(int)($result['latency_ms'] ?? 0),'last_checked_at'=>now()]);
            $prefix=$probeStatus==='online'?'Connectivity check passed':($probeStatus==='not_ready'?'Connectivity check not ready':'Connectivity check failed');
            return back()->with('diagnostic_message',$prefix.' via '.$statusLabel.' on '.$host.':'.$port.' — '.(string)($result['message'] ?? 'No probe details available.'));
        })->name('network-inventory.olt.diagnostic-check');

        Route::get('/network-inventory/switch-management', fn () => redirect()->route('network-inventory.devices', ['type' => 'switch']))
            ->name('network-inventory.switches');

        Route::get('/network-inventory/access-point-management', fn () => redirect()->route('network-inventory.devices', ['type' => 'access-point']))
            ->name('network-inventory.access-points');

        // Customer Network Monitoring — live stored OLT/ONU observations only.
        Route::get('/network-monitoring', function () {
            $query = \App\Models\OltOnuCustomerMapping::with(['customer','pppUser','olt'])->latest('id');
            $q = trim((string) request('q',''));
            $oltId = trim((string) request('olt',''));
            $pon = trim((string) request('pon',''));
            $status = trim((string) request('status',''));
            $signal = trim((string) request('signal',''));
            if ($q !== '') $query->where(function($w) use($q) {
                $w->where('onu_id','like',"%{$q}%")->orWhere('onu_mac','like',"%{$q}%")
                  ->orWhere('onu_serial','like',"%{$q}%")->orWhere('onu_ip','like',"%{$q}%")
                  ->orWhereHas('customer',fn($c)=>$c->where('customer_unique_id','like',"%{$q}%")->orWhere('customer_name','like',"%{$q}%")->orWhere('mobile','like',"%{$q}%"))
                  ->orWhereHas('pppUser',fn($u)=>$u->where('username','like',"%{$q}%"))
                  ->orWhereHas('olt',fn($o)=>$o->where('name','like',"%{$q}%")->orWhere('ip_address','like',"%{$q}%"));
            });
            if ($oltId !== '' && ctype_digit($oltId)) $query->where('olt_device_id',(int)$oltId);
            if ($pon !== '') $query->where('pon_port',$pon);
            if (in_array($status,['online','offline','unknown'],true)) $query->where('status',$status);
            if ($signal === 'great') $query->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->where('rx_power','>=',-20);
            elseif ($signal === 'good') $query->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->whereBetween('rx_power',[-24,-20]);
            elseif ($signal === 'poor') $query->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->whereBetween('rx_power',[-27,-24]);
            elseif ($signal === 'critical') $query->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->where('rx_power','<',-27);
            $mappings = $query->paginate((int)request('per_page',20) === 50 ? 50 : 20)->withQueryString();
            $all = \App\Models\OltOnuCustomerMapping::query();
            $stats = [
                'total'=>(clone $all)->count(),
                'online'=>(clone $all)->where('status','online')->count(),
                'offline'=>(clone $all)->where('status','offline')->count(),
                'critical'=>(clone $all)->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->where('rx_power','<',-27)->count(),
                'unmapped'=>(clone $all)->whereNull('customer_id')->count(),
            ];
            $olts = \App\Models\NetworkInventoryDevice::type('olt')->orderBy('name')->get(['id','name','ip_address']);
            $ponPorts = \App\Models\OltOnuCustomerMapping::query()->whereNotNull('pon_port')->where('pon_port','<>','')->distinct()->orderBy('pon_port')->pluck('pon_port');
            return view('xlink.network-monitoring',compact('mappings','stats','olts','ponPorts','q','oltId','pon','status','signal'));
        })->name('network-monitoring');

        Route::get('/network-monitoring/export.csv', function () {
            $query = \App\Models\OltOnuCustomerMapping::with(['customer','pppUser','olt'])->latest('id');
            $q = trim((string) request('q','')); $oltId=trim((string)request('olt','')); $pon=trim((string)request('pon',''));
            $status=trim((string)request('status','')); $signal=trim((string)request('signal',''));
            if($q!=='') $query->where(function($w)use($q){$w->where('onu_id','like',"%{$q}%")->orWhere('onu_mac','like',"%{$q}%")->orWhere('onu_serial','like',"%{$q}%")->orWhere('onu_ip','like',"%{$q}%")->orWhereHas('customer',fn($c)=>$c->where('customer_unique_id','like',"%{$q}%")->orWhere('customer_name','like',"%{$q}%")->orWhere('mobile','like',"%{$q}%"))->orWhereHas('pppUser',fn($u)=>$u->where('username','like',"%{$q}%"))->orWhereHas('olt',fn($o)=>$o->where('name','like',"%{$q}%"));});
            if($oltId!==''&&ctype_digit($oltId))$query->where('olt_device_id',(int)$oltId);
            if($pon!=='')$query->where('pon_port',$pon);
            if(in_array($status,['online','offline','unknown'],true))$query->where('status',$status);
            if($signal==='great')$query->whereNotNull('rx_power')->where('rx_power','>=',-20);
            elseif($signal==='good')$query->whereNotNull('rx_power')->whereBetween('rx_power',[-24,-20]);
            elseif($signal==='poor')$query->whereNotNull('rx_power')->whereBetween('rx_power',[-27,-24]);
            elseif($signal==='critical')$query->whereNotNull('rx_power')->where('rx_power','<',-27);
            $stream=fopen('php://temp','r+'); fputcsv($stream,['OLT','OLT IP','Customer ID','Customer Name','Mobile','PPPoE User','ONU IP','PON Port','ONU ID','ONU MAC','ONU Serial','Status','RX Power dBm','TX Power dBm','Distance m','Last Seen']);
            foreach($query->get() as $m) fputcsv($stream,[$m->olt?->name,$m->olt?->ip_address,$m->customer?->customer_unique_id,$m->customer?->customer_name,$m->customer?->mobile,$m->pppUser?->username,$m->onu_ip,$m->pon_port,$m->onu_id,$m->onu_mac,$m->onu_serial,$m->status,$m->rx_power,$m->tx_power,$m->distance_m,$m->last_seen_at]);
            rewind($stream); $csv=stream_get_contents($stream); fclose($stream);
            return response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="xlink-network-monitoring.csv"']);
        })->name('network-monitoring.export');

        // FTTH ONU Management: database-backed subscriber/ONU ledger. This page
        // never invents live telemetry and does not issue disruptive OLT commands.
        Route::get('/network-inventory/onu-management', function () {
            $query = \App\Models\OltOnuCustomerMapping::with(['customer','pppUser','olt'])->latest('id');
            $q = trim((string) request('q', ''));
            $oltId = trim((string) request('olt', ''));
            $status = trim((string) request('status', ''));
            $signal = trim((string) request('signal', ''));
            if ($q !== '') $query->where(function ($w) use ($q) {
                $w->where('onu_id','like',"%{$q}%")->orWhere('onu_serial','like',"%{$q}%")
                  ->orWhere('onu_mac','like',"%{$q}%")->orWhere('pon_port','like',"%{$q}%")
                  ->orWhere('onu_ip','like',"%{$q}%")
                  ->orWhereHas('customer', fn($c) => $c->where('customer_unique_id','like',"%{$q}%")->orWhere('customer_name','like',"%{$q}%"))
                  ->orWhereHas('pppUser', fn($p) => $p->where('username','like',"%{$q}%"))
                  ->orWhereHas('olt', fn($o) => $o->where('name','like',"%{$q}%"));
            });
            if ($oltId !== '' && ctype_digit($oltId)) $query->where('olt_device_id',(int)$oltId);
            if (in_array($status,['online','offline','unknown'],true)) $query->where('status',$status);
            if ($signal === 'good') $query->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->where('rx_power','>=',-20);
            elseif ($signal === 'warning') $query->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->whereBetween('rx_power',[-27,-20]);
            elseif ($signal === 'critical') $query->whereNotNull('opm_last_seen_at')->whereNotNull('rx_power')->where('rx_power','<',-27);
            $mappings = $query->paginate(25)->withQueryString();
            $base = \App\Models\OltOnuCustomerMapping::query();
            $stats = [
                'total' => (clone $base)->count(),
                'online' => (clone $base)->where('status','online')->count(),
                'offline' => (clone $base)->where('status','offline')->count(),
                'warning' => (clone $base)->whereNotNull('rx_power')->where('rx_power','<',-20)->count(),
                'mapped' => (clone $base)->whereNotNull('customer_id')->count(),
            ];
            $olts = \App\Models\NetworkInventoryDevice::type('olt')->orderBy('name')->get(['id','name','vendor','location']);
            return view('xlink.onu-management', compact('mappings','stats','olts','q','oltId','status','signal'));
        })->name('network-inventory.onu-management');

        Route::get('/network-inventory/onu-management/export.csv', function () {
            $rows = \App\Models\OltOnuCustomerMapping::with(['customer','pppUser','olt'])->latest('id')->get();
            $stream = fopen('php://temp','r+');
            fputcsv($stream, ['OLT','Customer ID','Customer Name','PPPoE User','PON Port','ONU ID','ONU MAC','ONU Serial','Status','RX Power dBm','TX Power dBm','Distance m','Last Seen']);
            foreach ($rows as $m) fputcsv($stream, [$m->olt?->name,$m->customer?->customer_unique_id,$m->customer?->customer_name,$m->pppUser?->username,$m->pon_port,$m->onu_id,$m->onu_mac,$m->onu_serial,$m->status,$m->rx_power,$m->tx_power,$m->distance_m,$m->last_seen_at]);
            rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
            return response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="xlink-onu-management.csv"']);
        })->name('network-inventory.onu-management.export');

        Route::get('/stock-inventory', function () {
            $products = \App\Models\StockInventoryProduct::query()
                ->orderByRaw('CASE WHEN quantity <= reorder_level THEN 0 ELSE 1 END')
                ->orderBy('name')->paginate(20)->withQueryString();
            $totalQty = (int) \App\Models\StockInventoryProduct::sum('quantity');
            $stockValue = (float) \App\Models\StockInventoryProduct::selectRaw('COALESCE(SUM(quantity * unit_cost),0) total')->value('total');
            $lowStock = (int) \App\Models\StockInventoryProduct::whereColumn('quantity', '<=', 'reorder_level')->count();
            $outOfStock = (int) \App\Models\StockInventoryProduct::where('quantity', 0)->count();
            $warrantyExpiring = (int) \App\Models\StockInventoryWarranty::where('status', 'active')->whereNotNull('warranty_end')->whereBetween('warranty_end', [now()->toDateString(), now()->addDays(30)->toDateString()])->count();
            $openDamage = (int) \App\Models\StockInventoryDamageRecord::where('status', 'open')->count();
            $recentMovements = \App\Models\StockInventoryMovement::with('product')->latest()->limit(8)->get();

            return view('xlink.stock-inventory', [
                'tab' => 'dashboard',
                'products' => $products,
                'recentMovements' => $recentMovements,
                'warrantyExpiring' => $warrantyExpiring,
                'openDamage' => $openDamage,
                'stats' => [
                    ['Products', \App\Models\StockInventoryProduct::count()],
                    ['Total Qty', $totalQty],
                    ['Stock Value', number_format($stockValue, 2).' '.siteUrlSettings('site_currency')],
                    ['Low Stock', $lowStock],
                    ['Out of Stock', $outOfStock],
                ],
            ]);
        })->name('xlink.stock-inventory');
        Route::get('/stock-inventory/categories', function () {
            $categories = \App\Models\StockInventoryProduct::query()
                ->select('category', \Illuminate\Support\Facades\DB::raw('COUNT(*) as product_count'))
                ->whereNotNull('category')->whereRaw("TRIM(category) <> ''")
                ->groupBy('category')->orderBy('category')->get();
            return view('xlink.stock-inventory', ['tab'=>'categories','categories'=>$categories,'stats'=>[]]);
        })->name('stock-inventory.categories');
        Route::get('/stock-inventory/vendors', function () {
            $suppliers = \App\Models\StockInventorySupplier::query()
                ->select('stock_inventory_suppliers.*')
                ->selectSub(\Illuminate\Support\Facades\DB::table('stock_inventory_purchases')->selectRaw('COALESCE(SUM(total_amount),0)')->whereColumn('supplier_id','stock_inventory_suppliers.id'), 'purchase_total')
                ->selectSub(\Illuminate\Support\Facades\DB::table('stock_inventory_supplier_payments')->selectRaw('COALESCE(SUM(amount),0)')->whereColumn('supplier_id','stock_inventory_suppliers.id'), 'paid_total')
                ->orderBy('name')->paginate(25)->withQueryString();
            return view('xlink.stock-inventory', ['tab'=>'vendors','suppliers'=>$suppliers,'stats'=>[]]);
        })->name('stock-inventory.vendors');
        Route::get('/stock-inventory/vendor-payments', function () {
            $suppliers = \App\Models\StockInventorySupplier::orderBy('name')->get();
            $supplierPayments = \App\Models\StockInventorySupplierPayment::with('supplier')->latest('payment_date')->paginate(30)->withQueryString();
            return view('xlink.stock-inventory', ['tab'=>'vendor-payments','suppliers'=>$suppliers,'supplierPayments'=>$supplierPayments,'stats'=>[]]);
        })->name('stock-inventory.vendor-payments');
        Route::post('/stock-inventory/vendor-payments', function (\Illuminate\Http\Request $request) {
            $data = $request->validate([
                'supplier_id'=>'required|exists:stock_inventory_suppliers,id',
                'payment_date'=>'required|date',
                'amount'=>'required|numeric|min:0.01',
                'method'=>'required|in:cash,bank,bkash,nagad,rocket,other',
                'reference'=>'nullable|string|max:120',
                'notes'=>'nullable|string|max:1000',
            ]);
            $data['recorded_by'] = auth()->id();
            \App\Models\StockInventorySupplierPayment::create($data);
            return redirect()->route('stock-inventory.vendor-payments')->with('inventory_message','Supplier payment recorded.');
        })->name('stock-inventory.vendor-payments.store');
        Route::get('/stock-inventory/stock', function () { $movements=\App\Models\StockInventoryMovement::with('product')->latest()->paginate(30); return view('xlink.stock-inventory',['tab'=>'movements','movements'=>$movements,'products'=>\App\Models\StockInventoryProduct::orderBy('name')->get(),'stats'=>[]]); })->name('stock-inventory.stock');
        Route::get('/stock-inventory/products', function (\Illuminate\Http\Request $request) {
            $query = \App\Models\StockInventoryProduct::query();
            $search = trim((string) $request->query('q', ''));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('sku', 'like', '%'.$search.'%')
                      ->orWhere('name', 'like', '%'.$search.'%')
                      ->orWhere('category', 'like', '%'.$search.'%');
                });
            }
            if (in_array($request->query('status'), ['active', 'inactive'], true)) {
                $query->where('status', $request->query('status'));
            }
            if ($request->query('stock_alert') === 'low') {
                $query->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'reorder_level');
            } elseif ($request->query('stock_alert') === 'out') {
                $query->where('quantity', '<=', 0);
            }
            $products = $query->orderByRaw('CASE WHEN quantity <= reorder_level THEN 0 ELSE 1 END')
                ->orderBy('name')->paginate(25)->withQueryString();
            return view('xlink.stock-inventory', ['tab'=>'products','products'=>$products,'stats'=>[], 'filters'=>$request->query()]);
        })->name('stock-inventory.products');
        Route::post('/stock-inventory/products', function(\Illuminate\Http\Request $r){
            $d=$r->validate(['sku'=>'required|string|max:80|unique:stock_inventory_products,sku','name'=>'required|string|max:160','category'=>'nullable|string|max:100','unit'=>'required|string|max:20','quantity'=>'required|integer|min:0','reorder_level'=>'required|integer|min:0','unit_cost'=>'required|numeric|min:0','notes'=>'nullable|string|max:1000']);
            \Illuminate\Support\Facades\DB::transaction(function() use($d){
                $opening=(int)$d['quantity'];
                $product=\App\Models\StockInventoryProduct::create($d);
                if($opening>0){
                    \App\Models\StockInventoryMovement::create(['product_id'=>$product->id,'movement_type'=>'stock-in','quantity'=>$opening,'reference'=>'Opening Balance','source'=>'Initial stock entry','notes'=>'Opening quantity recorded when product was created.']);
                }
            });
            return back()->with('inventory_message','Product added.');
        })->name('stock-inventory.products.store');
        Route::post('/stock-inventory/movements', function(\Illuminate\Http\Request $r){
            $d=$r->validate([
                'product_id'=>'required|exists:stock_inventory_products,id',
                'movement_type'=>'required|in:stock-in,issue,sale,return,adjustment',
                'quantity'=>'required|integer|min:1',
                'adjustment_direction'=>'nullable|required_if:movement_type,adjustment|in:increase,decrease',
                'reference'=>'nullable|string|max:120',
                'source'=>'nullable|string|max:120',
                'destination'=>'nullable|string|max:120',
                'notes'=>'nullable|string|max:1000',
            ]);
            return \Illuminate\Support\Facades\DB::transaction(function() use($d){
                $p=\App\Models\StockInventoryProduct::whereKey($d['product_id'])->lockForUpdate()->firstOrFail();
                abort_if($p->status!=='active',422,'Inactive products cannot receive new stock movements.');
                $adjustmentOut = ($d['movement_type']==='adjustment' && ($d['adjustment_direction']??'increase')==='decrease');
                $delta = ($adjustmentOut || in_array($d['movement_type'],['issue','sale'],true)) ? -(int)$d['quantity'] : (int)$d['quantity'];
                abort_if($delta<0 && $p->quantity < abs($delta),422,'Insufficient stock.');
                $p->update(['quantity'=>$p->quantity+$delta]);
                unset($d['adjustment_direction']);
                if ($adjustmentOut) {
                    $d['movement_type']='issue';
                    $d['notes']=trim('Adjustment decrease. '.($d['notes']??''));
                    $d['destination']=$d['destination']??'Stock adjustment';
                } elseif (($d['movement_type']??'')==='adjustment') {
                    $d['notes']=trim('Adjustment increase. '.($d['notes']??''));
                }
                \App\Models\StockInventoryMovement::create($d);
                return back()->with('inventory_message','Stock movement recorded successfully.');
            });
        })->name('stock-inventory.movements.store');
        Route::get('/stock-inventory/movements', function(){ $movements=\App\Models\StockInventoryMovement::with('product')->latest()->paginate(30); return view('xlink.stock-inventory',['tab'=>'movements','movements'=>$movements,'products'=>\App\Models\StockInventoryProduct::orderBy('name')->get(),'stats'=>[]]); })->name('stock-inventory.movements');
        Route::post('/stock-inventory/products/{product}/update', function(\Illuminate\Http\Request $r, \App\Models\StockInventoryProduct $product){ $d=$r->validate(['sku'=>'required|string|max:80|unique:stock_inventory_products,sku,'.$product->id,'name'=>'required|string|max:160','category'=>'nullable|string|max:100','unit'=>'required|string|max:20','reorder_level'=>'required|integer|min:0','unit_cost'=>'required|numeric|min:0','status'=>'required|in:active,inactive','notes'=>'nullable|string|max:1000']); $product->update($d); return back()->with('inventory_message','Product updated.'); })->name('stock-inventory.products.update');
        Route::post('/stock-inventory/products/{product}/delete', function(\App\Models\StockInventoryProduct $product){ abort_if($product->movements()->exists(),422,'Product with movement history cannot be deleted.'); $product->delete(); return back()->with('inventory_message','Product deleted.'); })->name('stock-inventory.products.delete');
        Route::get('/stock-inventory/categories', function(){
            $categories = \App\Models\StockInventoryProduct::query()->selectRaw('category, COUNT(*) as product_count')->whereNotNull('category')->where('category','<>','')->groupBy('category')->orderBy('category')->get();
            return view('xlink.stock-inventory',['tab'=>'categories','categories'=>$categories,'stats'=>[]]);
        })->name('stock-inventory.categories');
        Route::get('/stock-inventory/stock', fn()=>redirect()->route('stock-inventory.movements'))->name('stock-inventory.stock');
        Route::get('/stock-inventory/vendors', function(){
            $suppliers = \App\Models\StockInventorySupplier::withSum('purchases as purchase_total','total_amount')->withSum('payments as paid_total','amount')->orderBy('name')->paginate(25)->withQueryString();
            return view('xlink.stock-inventory',['tab'=>'vendors','suppliers'=>$suppliers,'stats'=>[]]);
        })->name('stock-inventory.vendors');
        Route::get('/stock-inventory/vendor-payments', function(){
            $suppliers = \App\Models\StockInventorySupplier::orderBy('name')->get();
            $supplierPayments = \App\Models\StockInventorySupplierPayment::with('supplier')->latest('payment_date')->latest('id')->paginate(30)->withQueryString();
            return view('xlink.stock-inventory',['tab'=>'vendor-payments','suppliers'=>$suppliers,'supplierPayments'=>$supplierPayments,'stats'=>[]]);
        })->name('stock-inventory.vendor-payments');
        Route::post('/stock-inventory/vendor-payments', function(\Illuminate\Http\Request $r){
            $d=$r->validate(['supplier_id'=>'required|exists:stock_inventory_suppliers,id','payment_date'=>'required|date','amount'=>'required|numeric|min:0.01','method'=>'required|in:cash,bank,bkash,nagad,rocket,other','reference'=>'nullable|string|max:120','notes'=>'nullable|string|max:1000']);
            \App\Models\StockInventorySupplierPayment::create($d+['recorded_by'=>auth()->id()]);
            return back()->with('inventory_message','Supplier payment recorded.');
        })->name('stock-inventory.vendor-payments.store');

        Route::post('/stock-inventory/suppliers', function(\Illuminate\Http\Request $r){ $d=$r->validate(['name'=>'required|string|max:160','phone'=>'nullable|string|max:40','email'=>'nullable|email|max:190','address'=>'nullable|string|max:1000']); \App\Models\StockInventorySupplier::create($d); return back()->with('inventory_message','Supplier added.'); })->name('stock-inventory.suppliers.store');
        Route::post('/stock-inventory/suppliers/{supplier}/payments', function(\Illuminate\Http\Request $r, \App\Models\StockInventorySupplier $supplier){ $d=$r->validate(['payment_date'=>'required|date','amount'=>'required|numeric|min:0.01','method'=>'required|in:cash,bank,bkash,nagad,rocket,other','reference'=>'nullable|string|max:120','notes'=>'nullable|string|max:1000']); \App\Models\StockInventorySupplierPayment::create($d+['supplier_id'=>$supplier->id,'recorded_by'=>auth()->id()]); return back()->with('inventory_message','Supplier payment recorded.'); })->name('stock-inventory.suppliers.payments.store');
        Route::get('/stock-inventory/purchases', function(){ return view('xlink.stock-inventory',['tab'=>'purchases','title'=>'Purchases','products'=>\App\Models\StockInventoryProduct::orderBy('name')->get(),'suppliers'=>\App\Models\StockInventorySupplier::withSum('purchases as purchase_total','total_amount')->withSum('payments as paid_total','amount')->orderBy('name')->get(),'purchases'=>\App\Models\StockInventoryPurchase::with(['supplier','items.product'])->latest('purchase_date')->paginate(25),'stats'=>[]]); })->name('stock-inventory.purchases');
        Route::post('/stock-inventory/purchases', function(\Illuminate\Http\Request $r){
            $d=$r->validate(['supplier_id'=>'nullable|exists:stock_inventory_suppliers,id','invoice_no'=>'nullable|string|max:120','purchase_date'=>'required|date','product_id'=>'required|exists:stock_inventory_products,id','quantity'=>'required|integer|min:1','unit_cost'=>'required|numeric|min:0','notes'=>'nullable|string|max:1000']);
            $total=(float)$d['quantity']*(float)$d['unit_cost'];
            \Illuminate\Support\Facades\DB::transaction(function() use($d,$total){
                $product=\App\Models\StockInventoryProduct::whereKey($d['product_id'])->lockForUpdate()->firstOrFail();
                abort_if($product->status!=='active',422,'Inactive products cannot receive purchases.');
                $oldQuantity=(int)$product->quantity;
                $newQuantity=$oldQuantity+(int)$d['quantity'];
                $oldValue=$oldQuantity*(float)$product->unit_cost;
                $newAverageCost=$newQuantity>0 ? (($oldValue+$total)/$newQuantity) : (float)$d['unit_cost'];
                $purchase=\App\Models\StockInventoryPurchase::create(['supplier_id'=>$d['supplier_id']??null,'invoice_no'=>$d['invoice_no']??null,'purchase_date'=>$d['purchase_date'],'total_amount'=>$total,'status'=>'received','notes'=>$d['notes']??null]);
                \App\Models\StockInventoryPurchaseItem::create(['purchase_id'=>$purchase->id,'product_id'=>$product->id,'quantity'=>$d['quantity'],'unit_cost'=>$d['unit_cost'],'line_total'=>$total]);
                $product->update(['quantity'=>$newQuantity,'unit_cost'=>round($newAverageCost,2)]);
                \App\Models\StockInventoryMovement::create(['product_id'=>$product->id,'movement_type'=>'stock-in','quantity'=>(int)$d['quantity'],'reference'=>$d['invoice_no']??('Purchase #'.$purchase->id),'source'=>isset($d['supplier_id'])?\App\Models\StockInventorySupplier::whereKey($d['supplier_id'])->value('name'):null,'notes'=>'Received purchase #'.$purchase->id.($d['notes']?' — '.$d['notes']:'')]);
            });
            return back()->with('inventory_message','Purchase received, stock updated, and movement ledger recorded.');
        })->name('stock-inventory.purchases.store');
        Route::get('/stock-inventory/assets', function(){ $q=trim((string)request('q','')); $type=trim((string)request('asset_type','')); $status=trim((string)request('status','')); $query=\App\Models\StockInventoryAsset::with('product')->latest('id'); if($q!=='')$query->where(function($x)use($q){$x->where('asset_tag','like',"%$q%")->orWhere('serial_no','like',"%$q%")->orWhere('mac_address','like',"%$q%")->orWhere('vendor','like',"%$q%")->orWhere('model','like',"%$q%")->orWhere('location','like',"%$q%");}); if($type!=='')$query->where('asset_type',$type); if($status!=='')$query->where('status',$status); return view('xlink.stock-inventory',['tab'=>'assets','products'=>\App\Models\StockInventoryProduct::orderBy('name')->get(),'assets'=>$query->paginate(25)->withQueryString(),'stats'=>[]]); })->name('stock-inventory.assets');
        Route::post('/stock-inventory/assets', function(\Illuminate\Http\Request $r){ $d=$r->validate(['asset_type'=>'required|string|max:40','product_id'=>'nullable|exists:stock_inventory_products,id','asset_tag'=>'nullable|string|max:120','serial_no'=>'nullable|string|max:160','mac_address'=>'nullable|string|max:80','vendor'=>'nullable|string|max:120','model'=>'nullable|string|max:120','status'=>'required|string|max:40','location'=>'nullable|string|max:160','assigned_to'=>'nullable|string|max:160','purchase_date'=>'nullable|date','warranty_end'=>'nullable|date','reference'=>'nullable|string|max:120','notes'=>'nullable|string|max:1000']); \App\Models\StockInventoryAsset::create($d); return back()->with('inventory_message','Network asset saved.'); })->name('stock-inventory.assets.store');
        Route::get('/stock-inventory/reports', function(){
            $from=request('from',now()->startOfMonth()->toDateString()); $to=request('to',now()->endOfMonth()->toDateString());
            abort_if(!\Illuminate\Support\Facades\Validator::make(['from'=>$from,'to'=>$to],['from'=>'required|date','to'=>'required|date|after_or_equal:from'])->passes(),422,'Invalid report date range.');
            $signed=fn($m)=>in_array($m->movement_type,['issue','sale'],true)?-(int)$m->quantity:(int)$m->quantity;
            $rows=\App\Models\StockInventoryMovement::with('product')->whereBetween('created_at',[$from.' 00:00:00',$to.' 23:59:59'])->get();
            $movementSummary=$rows->groupBy('movement_type')->map(fn($g)=>['count'=>$g->count(),'qty'=>$g->sum('quantity')]);
            $productUsage=$rows->groupBy('product_id')->map->sum('quantity'); $usageProducts=\App\Models\StockInventoryProduct::whereIn('id',$productUsage->keys())->get()->keyBy('id');
            $allProducts=\App\Models\StockInventoryProduct::orderBy('name')->get();
            $laterMovements=\App\Models\StockInventoryMovement::where('created_at','>=',$from.' 00:00:00')->get()->groupBy('product_id');
            $periodMovements=$rows->groupBy('product_id');
            $reconciliation=$allProducts->map(function($product)use($laterMovements,$periodMovements,$signed){ $after=($laterMovements[$product->id]??collect())->sum(fn($m)=>$signed($m)); $period=($periodMovements[$product->id]??collect())->sum(fn($m)=>$signed($m)); $opening=(int)$product->quantity-(int)$after; return ['product'=>$product,'opening'=>$opening,'stock_in'=>($periodMovements[$product->id]??collect())->filter(fn($m)=>in_array($m->movement_type,['stock-in','return','adjustment'],true))->sum('quantity'),'out'=>($periodMovements[$product->id]??collect())->filter(fn($m)=>in_array($m->movement_type,['issue','sale'],true))->sum('quantity'),'period_net'=>$period,'closing'=>$opening+$period]; });
            $assetSummary=\App\Models\StockInventoryAsset::selectRaw('status,count(*) as total')->groupBy('status')->pluck('total','status'); $warrantySummary=\App\Models\StockInventoryWarranty::selectRaw('status,count(*) as total')->groupBy('status')->pluck('total','status'); $damageSummary=\App\Models\StockInventoryDamageRecord::selectRaw('record_type,status,sum(quantity) as qty')->groupBy('record_type','status')->get();
            return view('xlink.stock-inventory',['tab'=>'reports','from'=>$from,'to'=>$to,'movementSummary'=>$movementSummary,'productUsage'=>$productUsage,'usageProducts'=>$usageProducts,'reconciliation'=>$reconciliation,'assetSummary'=>$assetSummary,'warrantySummary'=>$warrantySummary,'damageSummary'=>$damageSummary,'products'=>\App\Models\StockInventoryProduct::orderBy('name')->paginate(20),'stats'=>[]]);
        })->name('stock-inventory.reports');
        Route::get('/stock-inventory/reports/print', function(){ return redirect()->route('stock-inventory.reports',request()->query()); })->name('stock-inventory.reports.print');
        Route::get('/stock-inventory/export/movements.csv', function(){ $rows=\App\Models\StockInventoryMovement::with('product')->when(request('from'),fn($q)=>$q->whereDate('created_at','>=',request('from')))->when(request('to'),fn($q)=>$q->whereDate('created_at','<=',request('to')))->latest()->get(); $stream=fopen('php://temp','r+'); fputcsv($stream,['SKU','Product','Type','Quantity','Reference','Created']); foreach($rows as $r){fputcsv($stream,[$r->product?->sku,$r->product?->name,$r->movement_type,$r->quantity,$r->reference,$r->created_at]);} rewind($stream); $csv=stream_get_contents($stream); fclose($stream); return response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="stock-movements.csv"']); })->name('stock-inventory.export.movements.csv');
        Route::get('/stock-inventory/export/products.xlsx', function(){ $rows=\App\Models\StockInventoryProduct::orderBy('name')->get(); $spreadsheet=new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sheet=$spreadsheet->getActiveSheet(); $sheet->setTitle('Products'); $headers=['SKU','Product','Category','Unit','Quantity','Reorder Level','Unit Cost','Status']; $columns=range('A','H'); foreach($headers as $i=>$header){$sheet->setCellValue($columns[$i].'1',$header);} foreach($rows as $ri=>$r){$values=[$r->sku,$r->name,$r->category,$r->unit,$r->quantity,$r->reorder_level,(float)$r->unit_cost,$r->status]; foreach($values as $ci=>$value){$sheet->setCellValue($columns[$ci].($ri+2),$value);}} $sheet->getStyle('A1:H1')->getFont()->setBold(true); $sheet->freezePane('A2'); $sheet->setAutoFilter('A1:H'.max(1,$rows->count()+1)); foreach(range('A','H') as $col){$sheet->getColumnDimension($col)->setAutoSize(true);} $writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet); ob_start(); $writer->save('php://output'); $xlsx=ob_get_clean(); return response($xlsx,200,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Content-Disposition'=>'attachment; filename="stock-products.xlsx"','Content-Length'=>strlen($xlsx)]); })->name('stock-inventory.export.products.xlsx');
        Route::post('/stock-inventory/warranty', function(\Illuminate\Http\Request $r){ $d=$r->validate(['product_id'=>'required|exists:stock_inventory_products,id','asset_serial'=>'nullable|string|max:160','asset_mac'=>'nullable|string|max:80','vendor'=>'nullable|string|max:120','warranty_start'=>'nullable|date','warranty_end'=>'nullable|date','status'=>'required|in:active,expired,claimed,void','reference'=>'nullable|string|max:120','notes'=>'nullable|string|max:1000']); \App\Models\StockInventoryWarranty::create($d); return back()->with('inventory_message','Warranty record saved.'); })->name('stock-inventory.warranty.store');
        Route::post('/stock-inventory/damaged', function(\Illuminate\Http\Request $r){
            $d=$r->validate(['product_id'=>'required|exists:stock_inventory_products,id','quantity'=>'required|integer|min:1','asset_serial'=>'nullable|string|max:160','asset_mac'=>'nullable|string|max:80','record_type'=>'required|in:damaged,lost','incident_date'=>'required|date','reason'=>'nullable|string|max:500','status'=>'required|in:open,recovered,resolved,disposed','reference'=>'nullable|string|max:120','notes'=>'nullable|string|max:1000']);
            return \Illuminate\Support\Facades\DB::transaction(function() use($d){
                $product=\App\Models\StockInventoryProduct::whereKey($d['product_id'])->lockForUpdate()->firstOrFail();
                abort_if($product->status!=='active',422,'Inactive products cannot be adjusted.');
                abort_if($product->quantity < (int)$d['quantity'],422,'Insufficient stock for this lost/damaged record.');
                $record=\App\Models\StockInventoryDamageRecord::create($d);
                $reference=$d['reference']??('Damage/Loss #'.$record->id);
                $product->decrement('quantity',(int)$d['quantity']);
                \App\Models\StockInventoryMovement::create(['product_id'=>$product->id,'movement_type'=>'issue','quantity'=>(int)$d['quantity'],'reference'=>$reference,'source'=>'Stock inventory','destination'=>$d['record_type'],'notes'=>ucfirst($d['record_type']).' stock deduction: '.($d['reason']??'No reason provided').' (record #'.$record->id.')']);
                if($d['status']==='recovered'){
                    $product->increment('quantity',(int)$d['quantity']);
                    \App\Models\StockInventoryMovement::create(['product_id'=>$product->id,'movement_type'=>'return','quantity'=>(int)$d['quantity'],'reference'=>$reference,'source'=>'Recovered stock','destination'=>'Stock inventory','notes'=>'Recovered stock returned to inventory (record #'.$record->id.')']);
                }
                return back()->with('inventory_message','Lost/damaged record saved and stock ledger reconciled.');
            });
        })->name('stock-inventory.damaged.store');
        Route::get('/stock-inventory/warranty', function(){ $q=trim((string)request('q','')); $status=trim((string)request('status','')); $query=\App\Models\StockInventoryWarranty::with('product')->latest('id'); if($q!=='')$query->where(function($x)use($q){$x->where('asset_serial','like',"%$q%")->orWhere('asset_mac','like',"%$q%")->orWhere('vendor','like',"%$q%")->orWhere('reference','like',"%$q%");}); if($status!=='')$query->where('status',$status); if(request('expiring')==='1')$query->where('status','active')->whereNotNull('warranty_end')->whereBetween('warranty_end',[now()->toDateString(),now()->addDays(30)->toDateString()]); return view('xlink.stock-inventory',['tab'=>'warranty','title'=>'Warranty','products'=>\App\Models\StockInventoryProduct::orderBy('name')->get(),'warranties'=>$query->paginate(25)->withQueryString(),'stats'=>[]]); })->name('stock-inventory.warranty');
        Route::get('/stock-inventory/damaged', function(){ $q=trim((string)request('q','')); $status=trim((string)request('status','')); $query=\App\Models\StockInventoryDamageRecord::with('product')->latest('incident_date'); if($q!=='')$query->where(function($x)use($q){$x->where('asset_serial','like',"%$q%")->orWhere('asset_mac','like',"%$q%")->orWhere('reason','like',"%$q%")->orWhere('reference','like',"%$q%");}); if($status!=='')$query->where('status',$status); return view('xlink.stock-inventory',['tab'=>'damaged','title'=>'Lost & Damaged','products'=>\App\Models\StockInventoryProduct::orderBy('name')->get(),'damageRecords'=>$query->paginate(25)->withQueryString(),'stats'=>[]]); })->name('stock-inventory.damaged');
        Route::get('/stock-inventory/settings', fn()=>view('xlink.stock-inventory',['tab'=>'settings','title'=>'Settings','products'=>\App\Models\StockInventoryProduct::orderBy('name')->paginate(20),'stats'=>[]]))->name('stock-inventory.settings');

        Route::get('/communication-center', function () {
            $templates = \App\Models\SmsTemplate::count();
            $notifications = \App\Models\NotificationLogs::count();
            $unread = \App\Models\NotificationLogs::whereNull('read_by')->count();
            $conversations = \App\Models\SupportTicket::count();
            $openTickets = \App\Models\SupportTicket::whereIn('status',['open','pending','in_progress'])->count();
            $whatsapp = \App\Models\MainSiteData::where('type','site_whatsapp')->value('value');
            $recentNotifications = \App\Models\NotificationLogs::latest()->limit(6)->get();
            $recentConversations = \App\Models\SupportTicket::with('customer')->latest()->limit(6)->get();
            return view('xlink.communication-center', compact(
                'templates','notifications','unread','conversations','openTickets','whatsapp',
                'recentNotifications','recentConversations'
            ) + ['tab'=>'dashboard']);
        })->name('xlink.communication-center');
        Route::get('/communication-center/chat', function () {
            $tickets = \App\Models\SupportTicket::with('customer')->latest()->paginate(20)->withQueryString();
            return view('xlink.communication-center', ['tab'=>'chat','tickets'=>$tickets]);
        })->name('communication-center.chat');
        Route::get('/communication-center/settings', function () {
            $whatsapp = \App\Models\MainSiteData::where('type','site_whatsapp')->value('value');
            return view('xlink.communication-center', ['tab'=>'settings','whatsapp'=>$whatsapp]);
        })->name('communication-center.settings');
        // SMS service pages: individual recipient audit records and safe, explicit dispatch.
        Route::get('/sms-service', function () {
            $base = \App\Models\SmsDispatchLog::query();
            $stats = ['total'=>(clone $base)->count(),'sent'=>(clone $base)->where('status','sent')->count(),'failed'=>(clone $base)->where('status','failed')->count(),'templates'=>\App\Models\SmsTemplate::where('is_active',1)->count()];
            $recent = \App\Models\SmsDispatchLog::latest('id')->limit(12)->get();
            return view('xlink.sms-service',compact('stats','recent'));
        })->name('sms-service');
        Route::get('/sms-service/send', function () {
            $customers = \App\Models\CustomersInfo::query()->whereNotNull('mobile')->where('mobile','<>','')->orderBy('customer_name')->limit(1000)->get(['id','customer_unique_id','customer_name','mobile']);
            return view('xlink.sms-service-send',compact('customers'));
        })->name('sms-service.send');
        Route::post('/sms-service/send', function (\Illuminate\Http\Request $request) {
            $data = $request->validate([
                'recipient_type'=>'required|in:customer,custom',
                'customer_id'=>'nullable|required_if:recipient_type,customer|exists:customers_infos,id',
                'recipient'=>'nullable|required_if:recipient_type,custom|string|max:40',
                'sms_type'=>'required|string|max:80',
                'message'=>'required|string|min:1|max:1000',
            ]);
            $customer = !empty($data['customer_id']) ? \App\Models\CustomersInfo::with(['billing','package'])->findOrFail($data['customer_id']) : null;
            $recipient = $customer ? (string)$customer->mobile : trim((string)($data['recipient']??''));
            abort_if($recipient==='',422,'A recipient mobile number is required.');
            $digits = preg_replace('/\D+/', '', $recipient);
            abort_unless(preg_match('/^(?:01\d{9}|8801\d{9}|008801\d{9})$/',$digits)===1,422,'Enter a valid Bangladesh mobile number.');
            $message = str_replace(
                ['{customer_name}','{customer_id}','{due_amount}','{bill_amount}','{package_name}','{company_name}','{helpline}'],
                [$customer?->customer_name ?? '',$customer?->customer_unique_id ?? '',(string)($customer?->billing?->due_amount ?? 0),(string)($customer?->billing?->total_amount ?? 0),$customer?->package?->name ?? '',siteUrlSettings('site_name') ?? config('app.name'),siteUrlSettings('site_phone') ?? ''],
                $data['message']
            );
            $status='failed'; $responseText='SMS gateway exception.';
            try {
                $response = \XLink\SmsBridge\Facades\SmsBridge::to($recipient)->message($message)->send();
                if ($response && $response->isSuccessful()) { $status='sent'; $responseText=(string)($response->getMessage() ?: 'Gateway accepted the message.'); }
                else { $responseText=(string)($response ? $response->getMessage() : 'No response from SMS gateway.'); }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Manual SMS dispatch failed.', ['user_id'=>auth()->id(),'customer_id'=>$customer?->id,'exception_class'=>get_class($e)]);
                $responseText='SMS gateway request failed. Check gateway settings and application logs.';
            }
            \App\Models\SmsDispatchLog::create([
                'customer_id'=>$customer?->id,'user_id'=>auth()->id(),'recipient'=>$recipient,
                'customer_code'=>$customer?->customer_unique_id,'customer_name'=>$customer?->customer_name,
                'sms_type'=>$data['sms_type'],'sms_count'=>(function($text){$unicode=preg_match('/[^\x00-\x7F]/',$text)===1;$len=mb_strlen($text,'UTF-8');$single=$unicode?70:160;$multi=$unicode?67:153;return $len<=$single?1:max(1,(int)ceil($len/$multi));})($message),
                'message'=>$message,'status'=>$status,'gateway_response'=>\Illuminate\Support\Str::limit($responseText,4000),
                'sent_at'=>$status==='sent'?now():null,
            ]);
            return redirect()->route('sms-service.send')->with('sms_dispatch_success',$status==='sent')->with('sms_dispatch_message',$status==='sent'?'Gateway accepted the SMS. See delivery history for the recorded attempt.':'SMS failed or was not accepted by the gateway. The attempt was recorded.');
        })->name('sms-service.send.store');
        Route::get('/sms-service/reports', function () {
            $query=\App\Models\SmsDispatchLog::query()->latest('id');
            $q=trim((string)request('q','')); $status=trim((string)request('status','')); $smsType=trim((string)request('type',''));
            $from=trim((string)request('from','')); $to=trim((string)request('to',''));
            if($q!=='')$query->where(function($w)use($q){$w->where('recipient','like',"%{$q}%")->orWhere('customer_code','like',"%{$q}%")->orWhere('customer_name','like',"%{$q}%")->orWhere('message','like',"%{$q}%");});
            if(in_array($status,['sent','failed','pending'],true))$query->where('status',$status);
            if($smsType!=='')$query->where('sms_type',$smsType);
            if($from!==''&&\Illuminate\Support\Facades\Validator::make(['from'=>$from],['from'=>'date'])->passes())$query->whereDate('created_at','>=',$from);
            if($to!==''&&\Illuminate\Support\Facades\Validator::make(['to'=>$to],['to'=>'date'])->passes())$query->whereDate('created_at','<=',$to);
            $stats=['total'=>(clone $query)->count(),'sent'=>(clone $query)->where('status','sent')->count(),'failed'=>(clone $query)->where('status','failed')->count()];
            $types=\App\Models\SmsDispatchLog::query()->distinct()->orderBy('sms_type')->pluck('sms_type');
            $logs=$query->paginate(30)->withQueryString();
            return view('xlink.sms-service-reports',compact('logs','stats','types','q','status','smsType','from','to'));
        })->name('sms-service.reports');
        Route::get('/sms-service/reports/export.csv', function () {
            $query=\App\Models\SmsDispatchLog::query()->latest('id');
            $q=trim((string)request('q','')); $status=trim((string)request('status','')); $smsType=trim((string)request('type','')); $from=trim((string)request('from','')); $to=trim((string)request('to',''));
            if($q!=='')$query->where(function($w)use($q){$w->where('recipient','like',"%{$q}%")->orWhere('customer_code','like',"%{$q}%")->orWhere('customer_name','like',"%{$q}%")->orWhere('message','like',"%{$q}%");});
            if(in_array($status,['sent','failed','pending'],true))$query->where('status',$status); if($smsType!=='')$query->where('sms_type',$smsType);
            if($from!==''&&\Illuminate\Support\Facades\Validator::make(['from'=>$from],['from'=>'date'])->passes())$query->whereDate('created_at','>=',$from);
            if($to!==''&&\Illuminate\Support\Facades\Validator::make(['to'=>$to],['to'=>'date'])->passes())$query->whereDate('created_at','<=',$to);
            $stream=fopen('php://temp','r+'); fputcsv($stream,['Log ID','Date','Customer ID','Customer Name','Type','Mobile','SMS Count','Message','Status','Gateway Response','Sent At']);
            foreach($query->get() as $log)fputcsv($stream,[$log->id,$log->created_at,$log->customer_code,$log->customer_name,$log->sms_type,$log->recipient,$log->sms_count,$log->message,$log->status,$log->gateway_response,$log->sent_at]);
            rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
            return response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="xlink-sms-delivery-history.csv"']);
        })->name('sms-service.reports.export');
        Route::get('/sms-service/templates', fn()=>redirect()->route('sms-setup'))->name('sms-service.templates');
        Route::get('/sms-service/bulk', fn()=>redirect()->route('sms-setup',['tab'=>'bulk']))->name('sms-service.bulk');
        Route::get('/sms-service/gateway', fn()=>redirect()->route('sms-bridge.index'))->name('sms-service.gateway');

        Route::get('/communication-center/sms', fn () => redirect()->route('sms-setup'))->name('communication-center.sms');
        Route::get('/communication-center/notifications', fn () => redirect()->route('notifications'))->name('communication-center.notifications');
        Route::post('/communication-center/settings', function (\Illuminate\Http\Request $r) {
            $d=$r->validate(['whatsapp'=>'nullable|string|max:30','notification_email'=>'nullable|email|max:190','notification_url'=>'nullable|url|max:500']);
            foreach ([['site_whatsapp',$d['whatsapp']??null],['notification_email',$d['notification_email']??null],['notification_url',$d['notification_url']??null]] as [$k,$v]) {
                if ($v===null || $v==='') continue;
                \App\Models\MainSiteData::updateOrCreate(['type'=>$k],['value'=>$v]);
            }
            return back()->with('communication_message','Communication settings updated.');
        })->name('communication-center.settings.update');
        Route::get('/communication-center/whatsapp', function () {
            $tickets = \App\Models\SupportTicket::with('customer')->latest()->paginate(20)->withQueryString();
            return view('xlink.communication-center', ['tab'=>'whatsapp','tickets'=>$tickets]);
        })->name('communication-center.whatsapp');
        Route::get('/support-center', [SupportCenterController::class, 'dashboard'])->name('xlink.support-center');
        Route::get('/support-center/tickets', [SupportCenterController::class, 'tickets'])->name('support-center.tickets');
        Route::get('/support-center/tickets/export', [SupportCenterController::class, 'exportTickets'])->name('support-center.tickets-export');
        Route::get('/support-center/tickets/create', [SupportCenterController::class, 'createTicket'])->name('support-center.create-ticket');
        Route::post('/support-center/tickets', [SupportCenterController::class, 'storeTicket'])->name('support-center.store-ticket');
        Route::put('/support-center/tickets/{ticket}', [SupportCenterController::class, 'updateTicket'])->name('support-center.ticket-update');
        Route::get('/support-center/sales-queries', [SupportCenterController::class, 'salesQueries'])->name('support-center.sales');
        Route::get('/support-center/sales-queries/create', [SupportCenterController::class, 'createSalesQuery'])->name('support-center.sales-create');
        Route::post('/support-center/sales-queries', [SupportCenterController::class, 'storeSalesQuery'])->name('support-center.sales-store');
        Route::put('/support-center/sales-queries/{query}', [SupportCenterController::class, 'updateSalesQuery'])->name('support-center.sales-update');
        Route::get('/support-center/kyc', [SupportCenterController::class, 'kyc'])->name('support-center.kyc');
        Route::put('/support-center/kyc/{kyc}', [SupportCenterController::class, 'updateKyc'])->name('support-center.kyc-update');
        Route::get('/support-center/templates', [SupportCenterController::class, 'templates'])->name('support-center.templates');
        Route::post('/support-center/templates', [SupportCenterController::class, 'storeTemplate'])->name('support-center.template-store');
        Route::post('/support-center/templates/{template}/toggle', [SupportCenterController::class, 'toggleTemplate'])->name('support-center.template-toggle');
        Route::get('/support-center/tickets/bulk', [SupportCenterController::class, 'tickets'])->name('support-center.tickets-bulk-page');
        Route::post('/support-center/tickets/bulk', [SupportCenterController::class, 'bulkUpdateTickets'])->name('support-center.tickets-bulk');
        Route::get('/support-center/settings', [SupportCenterController::class, 'generalSettings'])->name('support-center.settings');
        Route::post('/support-center/settings', [SupportCenterController::class, 'saveGeneralSettings'])->name('support-center.settings.save');
        Route::post('/support-center/kyc/bulk', [SupportCenterController::class, 'bulkUpdateKyc'])->name('support-center.kyc-bulk');
        Route::get('/team-access', [\App\Http\Controllers\TeamAccessController::class, 'overview'])->name('xlink.team-access');
// Team & Access reference-parity routes.
                Route::get('/staff-team', [\App\Http\Controllers\TeamAccessController::class, 'teamOverview'])->name('staff-team');
        Route::get('/attendance', [\App\Http\Controllers\TeamAccessController::class, 'attendance'])->name('attendance');
        Route::post('/attendance/check-in', [\App\Http\Controllers\TeamAccessController::class, 'checkIn'])->name('team-attendance.checkin');
        Route::post('/attendance/check-out', [\App\Http\Controllers\TeamAccessController::class, 'checkOut'])->name('team-attendance.checkout');
        Route::get('/attendance-report', [\App\Http\Controllers\TeamAccessController::class, 'attendanceReport'])->name('attendance-report');
        Route::get('/attendance-settings', [\App\Http\Controllers\TeamAccessController::class, 'attendanceSettings'])->name('attendance-settings');
        Route::post('/attendance-settings', [\App\Http\Controllers\TeamAccessController::class, 'saveAttendanceSettings'])->name('attendance-settings.save');
        Route::post('/attendance-settings/devices', [\App\Http\Controllers\TeamAccessController::class, 'storeDevice'])->name('attendance-devices.store');
        Route::post('/attendance-settings/locations', [\App\Http\Controllers\TeamAccessController::class, 'storeLocation'])->name('attendance-locations.store');
        Route::get('/attendance-leave', [\App\Http\Controllers\TeamAccessController::class, 'leaves'])->name('attendance-leave');
        Route::post('/attendance-leave', [\App\Http\Controllers\TeamAccessController::class, 'submitLeave'])->name('attendance-leave.submit');
        Route::put('/attendance-leave/{leave}', [\App\Http\Controllers\TeamAccessController::class, 'updateLeave'])->name('attendance-leave.update');
        Route::get('/attendance-payroll', [\App\Http\Controllers\TeamAccessController::class, 'payroll'])->name('attendance-payroll');
        Route::post('/attendance-payroll', [\App\Http\Controllers\TeamAccessController::class, 'savePayroll'])->name('attendance-payroll.save');
        Route::get('/staff-conveyance', [\App\Http\Controllers\TeamAccessController::class, 'visits'])->name('staff-conveyance');
        Route::post('/staff-conveyance/visit', [\App\Http\Controllers\TeamAccessController::class, 'checkVisit'])->name('staff-conveyance.visit');
        Route::post('/staff-conveyance/claim', [\App\Http\Controllers\TeamAccessController::class, 'submitClaim'])->name('staff-conveyance.claim');
        Route::put('/staff-conveyance/claim/{claim}', [\App\Http\Controllers\TeamAccessController::class, 'reviewClaim'])->name('staff-conveyance.claim.review');
        Route::get('/user-activity', fn () => redirect()->route('admin.activity-logs'))->name('user-activity');
        Route::get('/team-access/demo', [\App\Http\Controllers\TeamAccessController::class, 'demo'])->name('team-access.demo');
                // Company settings hub and the operational values already consumed by billing jobs.
        Route::get('/company-settings', function () {
            return view('xlink.company-settings');
        })->name('company-settings');
        Route::get('/company-settings/billing-operations', function () {
            $settings = [
                'disable_check_no'=>\App\Models\MainSiteData::getValue('disable_check_no',1),
                'disable_check_days'=>\App\Models\MainSiteData::getValue('disable_check_days',1),
                'expired_profile_name'=>\App\Models\MainSiteData::getValue('expired_profile_name','Expired'),
                'site_invoice_prefix'=>\App\Models\MainSiteData::getValue('site_invoice_prefix','INV-'),
                'site_currency'=>\App\Models\MainSiteData::getValue('site_currency','BDT'),
            ];
            return view('xlink.company-billing-operations',compact('settings'));
        })->name('company-settings.billing-operations');
        Route::post('/company-settings/billing-operations', function (\Illuminate\Http\Request $request) {
            $data=$request->validate([
                'disable_check_no'=>'required|integer|min:0|max:30',
                'disable_check_days'=>'required|integer|min:0|max:90',
                'expired_profile_name'=>'required|string|max:100',
                'site_invoice_prefix'=>'required|string|max:30',
                'site_currency'=>'required|string|max:10',
            ]);
            foreach($data as $key=>$value) \App\Models\MainSiteData::setValue($key,(string)$value);
            \Illuminate\Support\Facades\Cache::forget('main_site_data_active');
            return redirect()->route('company-settings.billing-operations')->with('company_settings_message','Billing operations settings saved. The existing scheduled billing/disconnect jobs read these operational values.');
        })->name('company-settings.billing-operations.save');
        Route::get('/company-settings/service-configuration', function () {
            $category=trim((string)request('category','connection_type'));
            $categories=['connection_type'=>'Connection Types','protocol'=>'Network Protocols','customer_type'=>'Customer Types','billing_status'=>'Billing Statuses','bandwidth_type'=>'Bandwidth Types'];
            if(!array_key_exists($category,$categories))$category='connection_type';
            $options=\App\Models\CompanyServiceOption::where('category',$category)->orderBy('name')->paginate(20)->withQueryString();
            $counts=\App\Models\CompanyServiceOption::selectRaw('category,count(*) as total,sum(case when is_active = 1 then 1 else 0 end) as active_total')->groupBy('category')->get()->keyBy('category');
            return view('xlink.company-service-configuration',compact('category','categories','options','counts'));
        })->name('company-settings.service-configuration');
        Route::post('/company-settings/service-configuration', function (\Illuminate\Http\Request $request) {
            $data=$request->validate([
                'category'=>'required|in:connection_type,protocol,customer_type,billing_status,bandwidth_type',
                'code'=>'required|string|max:80|regex:/^[A-Za-z0-9_-]+$/',
                'name'=>'required|string|max:120',
                'description'=>'nullable|string|max:500',
            ]);
            $data['code']=trim($data['code']);
            $data['name']=trim($data['name']);
            \Illuminate\Support\Facades\Validator::make($data,[
                'code'=>['required','string','max:80',\Illuminate\Validation\Rule::unique('company_service_options','code')->where(fn($q)=>$q->where('category',$data['category']))],
            ])->validate();
            \App\Models\CompanyServiceOption::create($data+['is_active'=>true,'is_system'=>false]);
            return redirect()->route('company-settings.service-configuration',['category'=>$data['category']])->with('company_settings_message','Service option added.');
        })->name('company-settings.service-configuration.store');
        Route::put('/company-settings/service-configuration/{option}', function (\Illuminate\Http\Request $request, \App\Models\CompanyServiceOption $option) {
            $data=$request->validate(['name'=>'required|string|max:120','description'=>'nullable|string|max:500']);
            $option->update($data);
            return back()->with('company_settings_message','Service option updated.');
        })->name('company-settings.service-configuration.update');
        Route::post('/company-settings/service-configuration/{option}/toggle', function (\App\Models\CompanyServiceOption $option) {
            $option->update(['is_active'=>!$option->is_active]);
            return back()->with('company_settings_message','Service option status updated.');
        })->name('company-settings.service-configuration.toggle');
        Route::delete('/company-settings/service-configuration/{option}', function (\App\Models\CompanyServiceOption $option) {
            $used=false;
            if($option->category==='connection_type')$used=\App\Models\OfficialInfo::where('connection_type',$option->code)->exists();
            elseif($option->category==='customer_type')$used=\App\Models\OfficialInfo::where('client_type',$option->code)->exists();
            elseif($option->category==='bandwidth_type')$used=\App\Models\OfficialInfo::where('connectivity_type',$option->code)->exists();
            elseif($option->category==='billing_status')$used=\App\Models\CustomersInfo::where('status',$option->code)->exists();
            abort_if($used,422,'This option is used by existing customer records. Disable it instead of deleting it.');
            $option->delete();
            return back()->with('company_settings_message','Service option deleted.');
        })->name('company-settings.service-configuration.delete');

        Route::get('/company-settings/email', function () {
            $settings=\App\Models\CompanyMailSetting::first();
            return view('xlink.company-email-settings',[
                'settings'=>$settings,
                'passwordConfigured'=>(bool)($settings?->password_encrypted),
            ]);
        })->name('company-settings.email');
        Route::post('/company-settings/email', function (\Illuminate\Http\Request $request) {
            $data=$request->validate([
                'enabled'=>'nullable|boolean',
                'host'=>'required_if:enabled,1|nullable|string|max:190',
                'port'=>'required_if:enabled,1|nullable|integer|min:1|max:65535',
                'encryption'=>'required|in:tls,ssl,none',
                'username'=>'nullable|string|max:190',
                'password'=>'nullable|string|max:1000',
                'from_address'=>'required|email|max:190',
                'from_name'=>'required|string|max:190',
                'reply_to'=>'nullable|email|max:190',
                'signature'=>'nullable|string|max:4000',
            ]);
            $settings=\App\Models\CompanyMailSetting::first() ?: new \App\Models\CompanyMailSetting();
            $settings->enabled=(bool)($data['enabled']??false);
            $settings->mailer='smtp';
            $settings->host=$data['host']??null;
            $settings->port=(int)($data['port']??587);
            $settings->encryption=$data['encryption'];
            $settings->username=$data['username']??null;
            if(trim((string)($data['password']??''))!=='') $settings->password_encrypted=\Illuminate\Support\Facades\Crypt::encryptString($data['password']);
            $settings->from_address=$data['from_address'];
            $settings->from_name=$data['from_name'];
            $settings->reply_to=$data['reply_to']??null;
            $settings->signature=$data['signature']??null;
            if($settings->enabled && empty($settings->password_encrypted) && !empty($settings->username)) {
                return back()->withErrors(['password'=>'Enter the SMTP password/app secret before enabling SMTP.'])->withInput($request->except('password'));
            }
            $settings->save();
            return redirect()->route('company-settings.email')->with('company_settings_message','Email settings saved. Custom SMTP is '.($settings->enabled?'enabled for subsequent application/queue processes.':'disabled; the existing .env mailer remains in use.'));
        })->name('company-settings.email.save');
        Route::post('/company-settings/email/test', function (\Illuminate\Http\Request $request) {
            $data=$request->validate(['test_email'=>'required|email|max:190']);
            $settings=\App\Models\CompanyMailSetting::first();
            abort_unless($settings && $settings->enabled,422,'Enable and save SMTP settings before sending a test email.');
            try {
                \Illuminate\Support\Facades\Mail::raw(
                    "This is a test email from ".($settings->from_name ?: config('app.name')).".\n\nSMTP transport test successful.",
                    function($message)use($data,$settings){$message->to($data['test_email'])->subject('X-Link Billing SMTP Test'); if($settings->reply_to)$message->replyTo($settings->reply_to);}
                );
                return back()->with('email_test_success',true)->with('email_test_message','Test email was accepted by the configured mail transport.');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Company SMTP test failed.', ['exception_class'=>get_class($e)]);
                return back()->with('email_test_success',false)->with('email_test_message','Test email failed. Verify SMTP host, port, encryption, credentials and outbound network access.');
            }
        })->name('company-settings.email.test');

        Route::get('/system-settings', function () {
            $debug = config('app.debug') ? 'ON' : 'OFF';
            $backups = is_dir(base_path('backups')) ? count(glob(base_path('backups/*'))) : 0;
            return view('xlink.system-settings', [
                'environment'=>app()->environment(),
                'debug'=>$debug,
                'backups'=>$backups,
                'siteName'=>\App\Models\MainSiteData::where('type','site_name')->value('value') ?: config('app.name'),
            ]);
        })->name('xlink.system-settings');
        Route::get('/billing-helpline', function () {
            $tickets = \App\Models\SupportTicket::query();
            $open = (clone $tickets)->whereIn('status',['open','pending'])->count();
            $inProgress = (clone $tickets)->where('status','in_progress')->count();
            $resolved = (clone $tickets)->where('status','resolved')->count();
            $total = (clone $tickets)->count();
            $highPriority = (clone $tickets)->where('priority','high')->whereIn('status',['open','pending','in_progress'])->count();
            $recentTickets = \App\Models\SupportTicket::with('customer')->latest()->limit(10)->get();
            return view('xlink.billing-helpline', compact('open','inProgress','resolved','total','highPriority','recentTickets'));
        })->name('xlink.billing-helpline');
        Route::get('/profile-security', function () {
            $user = auth()->user();
            $twoFactorEnabled = ! is_null($user->two_factor_confirmed_at);
            $tokenCount = class_exists(\Laravel\Sanctum\PersonalAccessToken::class)
                ? \Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', get_class($user))->where('tokenable_id', $user->getKey())->count()
                : 0;
            return view('xlink.profile-security', compact('user', 'twoFactorEnabled', 'tokenCount'));
        })->name('xlink.profile-security');

        Route::get('/all-notifications', NotificationListAll::class)->name('notifications');
        // Route::get('/edit-customer', EditCustomer::class);
        // Route::get('/customers', CustomerList::class);

        // X-Link Billing parity aliases — reuse existing production modules.
        Route::get('/mobile-banking', [\App\Http\Controllers\MobileBankingController::class, 'index'])->name('xlink.mobile-banking');
        Route::get('/mobile-banking/logs', [\App\Http\Controllers\MobileBankingController::class, 'logs'])->name('mobile-banking.logs');
        Route::get('/mobile-banking/gateways', [\App\Http\Controllers\MobileBankingController::class, 'gateways'])->name('mobile-banking.gateways');
        Route::get('/mobile-banking/methods', [\App\Http\Controllers\MobileBankingController::class, 'methods'])->name('mobile-banking.methods');
        Route::post('/mobile-banking/methods', [\App\Http\Controllers\MobileBankingController::class, 'saveMethod'])->name('mobile-banking.methods.save');
        Route::get('/mobile-banking/settings', [\App\Http\Controllers\MobileBankingController::class, 'settings'])->name('mobile-banking.settings');
        Route::post('/mobile-banking/settings', [\App\Http\Controllers\MobileBankingController::class, 'updateSettings'])->name('mobile-banking.settings.update');
        Route::get('/bandwidth-reseller', [\App\Http\Controllers\Admin\BandwidthResellerController::class, 'index'])->name('xlink.bandwidth-reseller');
        Route::get('/bandwidth-resellers', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'index'])->name('bandwidth-resellers');
        Route::post('/bandwidth-resellers', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'store'])->name('bandwidth-resellers.store');
        Route::put('/bandwidth-resellers/{bandwidthReseller}', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'update'])->name('bandwidth-resellers.update');
        Route::delete('/bandwidth-resellers/{bandwidthReseller}', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'destroy'])->name('bandwidth-resellers.destroy');
        Route::get('/bandwidth-services', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'services'])->name('bandwidth-services');
        Route::post('/bandwidth-services', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'storeService'])->name('bandwidth-services.store');
        Route::post('/bandwidth-services/{service}/toggle', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'toggleService'])->name('bandwidth-services.toggle');
        Route::get('/bandwidth-invoices', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'invoices'])->name('bandwidth-invoices');
        Route::post('/bandwidth-invoices', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'storeInvoice'])->name('bandwidth-invoices.store');
        Route::post('/bandwidth-invoices/{invoice}/pay', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'payInvoice'])->name('bandwidth-invoices.pay');
        Route::get('/bandwidth-tickets', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'tickets'])->name('bandwidth-tickets');
        Route::post('/bandwidth-tickets', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'storeTicket'])->name('bandwidth-tickets.store');
        Route::put('/bandwidth-tickets/{ticket}', [\App\Http\Controllers\Admin\BandwidthResellerController::class,'updateTicket'])->name('bandwidth-tickets.update');
        Route::get('/devices-inventory', fn () => redirect()->route('mikrotik-server'))->name('xlink.devices-inventory');

        Route::get('/all-notifications', NotificationListAll::class)->name('notifications');
        // Route::get('/edit-customer', EditCustomer::class);
        // Route::get('/customers', CustomerList::class);

        Route::get('import-form', [ImportController::class, 'importForm'])->name('import.form');
        Route::post('collection-form', [ImportController::class, 'collectionForm'])->name('collection.form');
        Route::post('monthly-bill-form', [ImportController::class, 'monthlyBillForm'])->name('monthly.bill.form');
        // Route::post('import-preview', [ImportController::class, 'importView'])->name('import.preview');
        Route::post('import-store', [ImportController::class, 'import'])->name('import');

        // Route::get('/user/profile', [UserProfileController::class, 'index'])->name('user.profile');
        // Route::post('/user/profile/upload', [UserProfileController::class, 'uploadFile'])->name('user.profile.upload');
        // Route::get('/user/profile/update', [UserProfileController::class, 'update'])->name('user.profile.update');
        // Route::get('/user/password/update', [UserProfileController::class, 'updatePassword'])->name('user.password.update');

        Route::get('/submit-comment', CommentSubmit::class)->name('submit.comment');

        // Dedicated Billing & Finance dashboard.
        Route::get('/billing-finance', [BillingFinanceDashboardController::class, 'index'])->name('billing-finance');

        Route::get('/income-summary', function () {
            $from = request('from', now()->startOfMonth()->toDateString());
            $to = request('to', now()->endOfMonth()->toDateString());
            $rows = \App\Models\CollectionSummary::with('customer')
                ->whereBetween('collection_date', [$from, $to])
                ->latest('collection_date')->latest('id')->get();
            $own = $rows->whereNull('customer.reseller_id')->sum('collection_amount');
            $reseller = $rows->filter(fn($r) => optional($r->customer)->reseller_id !== null)->sum('collection_amount');
            return view('billing.income-summary', compact('rows','from','to','own','reseller'));
        })->name('income-summary');

        Route::get('/ledger-summary', function () {
            $from = request('from', now()->startOfMonth()->toDateString());
            $to = request('to', now()->endOfMonth()->toDateString());
            $credits = \App\Models\CollectionSummary::with('customer')
                ->whereBetween('collection_date', [$from, $to])->latest('collection_date')->limit(100)->get();
            $debits = \App\Models\IspExpense::with('addedBy')
                ->whereBetween('expense_date', [$from, $to])->latest('expense_date')->limit(100)->get();
            $creditTotal = $credits->sum('collection_amount');
            $debitTotal = $debits->sum('amount');
            return view('billing.ledger-summary', compact('credits','debits','creditTotal','debitTotal','from','to'));
        })->name('ledger-summary');

        Route::get('/extra-charges', function () {
            $from = request('from', now()->startOfMonth()->toDateString());
            $to = request('to', now()->endOfMonth()->toDateString());
            $rows = \App\Models\BillingInfo::with('customer')
                ->whereBetween('created_at', [$from, $to])
                ->where('additional_charge', '>', 0)
                ->latest('created_at')->limit(100)->get();
            $total = $rows->sum('additional_charge');
            return view('billing.extra-charges', compact('rows','total','from','to'));
        })->name('extra-charges');

        // Admin Reseller Management
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::resource('resellers', ResellerController::class)->except(['show']);
            Route::post('resellers/{reseller}/adjust-balance', [ResellerController::class, 'adjustBalance'])->name('resellers.adjust-balance');
            Route::get('resellers/{reseller}/transactions', [ResellerController::class, 'getTransactionsJson'])->name('resellers.transactions');

            // Expense tracking
            Route::get('expenses', ExpenseManager::class)->name('expenses');

            // Profit & Loss summary
            Route::get('profit-summary', [ProfitSummaryController::class, 'index'])->name('profit-summary');

            // Activity Log Viewer
            Route::get('activity-logs', ActivityLogViewer::class)->name('activity-logs');

            // System Logs Viewer
            Route::get('system-logs', SystemLogViewer::class)->name('system-logs');

            // Login Logs Viewer
            Route::get('login-logs', LoginLogViewer::class)->name('login-logs');

            // Customer Reviews Management
            Route::get('reviews', ManageReviews::class)->name('reviews');

            // Reseller Vouchers Audit
            Route::get('vouchers', AdminVoucherList::class)->name('vouchers');

            // Package purchase requests
            Route::get('purchase-requests', ManagePurchaseRequests::class)->name('purchase-requests');
        });

        // Reseller Portal Routes
        // MAC Reseller: isolated customer-management panel.
        Route::prefix('mac-reseller')->middleware(['mac-reseller'])->name('mac-reseller.')->group(function () {
            Route::get('/choose', [MacResellerDashboardController::class, 'choose'])->name('choose');
            Route::post('/select', [MacResellerDashboardController::class, 'select'])->name('select');
            Route::post('/exit', [MacResellerDashboardController::class, 'exit'])->name('exit');
            Route::get('/dashboard', [MacResellerDashboardController::class, 'index'])->name('dashboard');
            Route::get('customers/data', [ResellerCustomerList::class, 'getData'])->name('customers.data');
            Route::get('customers', ResellerCustomerList::class)->name('customers.index');
            Route::get('customers/create', NewCustomer::class)->name('customers.create');
            Route::get('customers/{customerId}/edit', EditCustomer::class)->name('customers.edit');
        });

        Route::prefix('reseller')->middleware(['reseller'])->name('reseller.')->group(function () {
            Route::get('/dashboard', [ResellerDashboardController::class, 'index'])->name('dashboard');
            Route::get('customers/data', [ResellerCustomerList::class, 'getData'])->name('customers.data');
            Route::get('customers', ResellerCustomerList::class)->name('customers.index');
            Route::get('customers/create', NewCustomer::class)->name('customers.create');
            Route::get('customers/{customerId}/edit', EditCustomer::class)->name('customers.edit');

            Route::get('packages', ResellerPackageManagement::class)->name('packages.index');

            // Vouchers & Wallet — always accessible to all resellers
            Route::get('vouchers', ResellerVoucherManagement::class)->name('vouchers.index');
            Route::get('wallet', ResellerWalletManagement::class)->name('wallet.index');
        });
    });
});


// portal domain routes
Route::middleware(EnsurePortalPort::class)->group(function () {
    // Authenticated portal payment initiation routes
    Route::middleware(['auth:ppp'])->group(function () {
        Route::get('/payment/bkash/initiate', [BkashPaymentController::class, 'initiate'])->name('payment.bkash.initiate');
        Route::get('/payment/nagad/initiate', [NagadPaymentController::class, 'initiate'])->name('payment.nagad.initiate');
        Route::get('/payment/sslcommerz/initiate', [SslCommerzPaymentController::class, 'initiate'])->name('payment.sslcommerz.initiate');
    });

    // Public payment callback routes (Gateways redirect here, CSRF is disabled for POSTs)
    Route::any('/payment/bkash/callback', [BkashPaymentController::class, 'callback'])->name('payment.bkash.callback');
    Route::any('/payment/nagad/callback', [NagadPaymentController::class, 'callback'])->name('payment.nagad.callback');
    Route::any('/payment/sslcommerz/callback', [SslCommerzPaymentController::class, 'callback'])->name('payment.sslcommerz.callback');
    Route::post('/payment/mock/submit', [BkashPaymentController::class, 'mockSubmit'])->name('payment.mock.submit');

    // Public voucher redemption route
    Route::get('/recharge/voucher', [PortalVoucherController::class, 'showRechargeForm'])->name('portal.voucher.recharge');
    Route::post('/recharge/voucher', [PortalVoucherController::class, 'redeem'])->name('portal.voucher.redeem');
});

// Final fallback: redirect unknown external hosts/IP requests without shadowing application routes.
Route::any('{any}', function () use ($baseDomain) {
    $host = request()->getHost();
    $allowedHosts = [$baseDomain, 'bill.xlinkbd.net', 'portal.'.$baseDomain, 'billing.'.$baseDomain];

    if (in_array($host, $allowedHosts, true)) {
        abort(404);
    }

    return redirect()->away(config('app.url') . '/warning');
})->where('any', '.*');

