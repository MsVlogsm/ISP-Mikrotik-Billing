<?php

use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\ScheduledTasksController;
use App\Jobs\QueueWorkerHeartbeatJob;
use App\Livewire\MikrotikSync;
use App\Models\MainSiteData;
use App\Services\AutomationSyncMonitor;
use App\Services\DataIntegrityAuditor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

$timezone = config('app.timezone') ?: 'Asia/Dhaka';

$track = static function (string $key, string $label, string $cadence, callable $callback) {
    return app(AutomationSyncMonitor::class)->run($key, $label, $cadence, $callback);
};

$runCommand = static function (string $key, string $label, string $cadence, string $signature) use ($track) {
    return $track($key, $label, $cadence, static function () use ($signature) {
        Artisan::output();
        $exitCode = Artisan::call($signature);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new \RuntimeException($signature.' exited with code '.$exitCode.': '.Str::limit($output, 1500));
        }

        return ['message' => $output !== '' ? Str::limit($output, 1800) : $signature.' completed successfully.'];
    });
};

// This row goes stale if cron stops invoking Laravel's scheduler. It does not
// launch or re-run any network/payment task.
Schedule::call(fn () => $track(
    'scheduler-heartbeat',
    'Scheduler Heartbeat',
    'Every minute',
    fn () => [
        'message' => 'Laravel scheduler invoked this heartbeat.',
        'metadata' => [
            'checked_at' => now()->toIso8601String(),
            'timezone' => (string) config('app.timezone', 'Asia/Dhaka'),
        ],
    ]
))->everyMinute()->timezone($timezone)->name('scheduler-heartbeat')->withoutOverlapping();

Schedule::call(fn () => $track(
    'monthly-bill-generation',
    'Monthly Bill Generation',
    'Monthly · last day 23:45',
    fn () => app(ScheduledTasksController::class)->createMonthlyBill()
))->lastDayOfMonth('23:45')->timezone($timezone)->name('monthly-bill-generation')->withoutOverlapping();

Schedule::call(fn () => $track(
    'monthly-bill-sms',
    'Monthly Bill SMS',
    'Monthly · day 1 at 10:00',
    fn () => app(ScheduledTasksController::class)->allCustomersMonthlyBillSMS()
))->monthlyOn(1, '10:00')->timezone($timezone)->name('monthly-bill-sms')->withoutOverlapping();

Schedule::call(fn () => $track(
    'daily-due-warning-sms',
    'Due / Disconnection Warning SMS',
    'Daily · 08:00',
    fn () => app(ScheduledTasksController::class)->createAlert()
))->dailyAt('08:00')->timezone($timezone)->name('daily-due-warning-sms')->withoutOverlapping();

Schedule::call(fn () => $track(
    'auto-disable-customers',
    'Automatic Customer Disable',
    'Every 5 minutes',
    fn () => app(ScheduledTasksController::class)->userDisable()
))->name('auto-disable-customers')->everyFiveMinutes()->timezone($timezone)->withoutOverlapping();

Schedule::call(fn () => $track(
    'mikrotik-ppp-secret-sync',
    'MikroTik PPP Secret Sync',
    'Daily · 00:00',
    fn () => app(MikrotikSync::class)->allSync()
))->daily()->timezone($timezone)->name('mikrotik-ppp-secret-sync')->withoutOverlapping();

Schedule::call(fn () => $runCommand(
    'mikrotik-router-log-poll',
    'MikroTik Router Log Poll',
    'Every minute',
    'app:poll-router-logs'
))->everyMinute()->timezone($timezone)->name('mikrotik-router-log-poll')->withoutOverlapping();

Schedule::call(fn () => $runCommand(
    'expire-realtime-hotspot-users',
    'Realtime Hotspot Voucher Sync / Expiry',
    'Every minute',
    'app:expire-realtime-hotspot-users'
))->name('expire-realtime-hotspot-users')->everyMinute()->timezone($timezone)->withoutOverlapping();

Schedule::call(fn () => $runCommand(
    'check-device-watchers',
    'Device Watcher Health Check',
    'Every minute',
    'app:check-device-watchers'
))->name('check-device-watchers')->everyMinute()->timezone($timezone)->withoutOverlapping();

Schedule::call(fn () => $track(
    'prune-router-logs',
    'Old Router Log Cleanup',
    'Daily · 04:00',
    function () {
        $days = (int) MainSiteData::getValue('log_retention_days', 30);
        app(MikrotikController::class)->pruneOldLogs($days);

        return ['message' => 'Router log retention cleanup completed.', 'metadata' => ['retention_days' => $days]];
    }
))->dailyAt('04:00')->timezone($timezone)->name('prune-router-logs')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(fn () => $runCommand(
    'check-network-inventory',
    'Network Inventory Health Check',
    'Every minute',
    'app:check-network-inventory'
))->everyMinute()->timezone($timezone)->name('check-network-inventory')->withoutOverlapping();

Schedule::call(fn () => $runCommand(
    'reconcile-mikrotik-pending-actions',
    'Pending MikroTik Action Reconciliation',
    'Every minute',
    'app:reconcile-mikrotik-pending-actions'
))->everyMinute()->timezone($timezone)->name('reconcile-mikrotik-pending-actions')->withoutOverlapping();

Schedule::call(fn () => $runCommand(
    'onu-mapping-sync',
    'OLT / ONU Mapping & Telemetry Sync',
    'Every minute',
    'olt:sync-onu-mappings'
))->everyMinute()->timezone($timezone)->name('onu-mapping-sync')->withoutOverlapping()->onOneServer();

Schedule::call(fn () => $runCommand(
    'customer-connection-snapshots',
    'Customer Online / Offline Snapshots',
    'Every 5 minutes',
    'app:capture-customer-connection-snapshots'
))->everyFiveMinutes()->timezone($timezone)->name('customer-connection-snapshots')->withoutOverlapping();

Schedule::call(fn () => $runCommand(
    'due-call-reminders',
    'Due Customer Voice Call Reminder',
    'Daily · 11:00',
    'billing:due-call-reminders'
))->dailyAt('11:00')->timezone($timezone)->name('due-call-reminders')->withoutOverlapping();

Schedule::call(fn () => $track(
    'queue-heartbeat-dispatch',
    'Queue Worker Heartbeat Dispatch',
    'Every 5 minutes',
    function () {
        QueueWorkerHeartbeatJob::dispatch();

        return ['message' => 'Heartbeat queued. Worker execution is verified separately.'];
    }
))->everyFiveMinutes()->timezone($timezone)->name('queue-heartbeat-dispatch')->withoutOverlapping();

Schedule::call(fn () => $track(
    'ipilot-zabbix-sync',
    'iPilot Zabbix Monitoring Sync',
    'Every 5 minutes',
    fn () => app(\App\Services\ZabbixMonitoringService::class)->syncAll()
))->everyFiveMinutes()->timezone($timezone)->name('ipilot-zabbix-sync')->withoutOverlapping()->onOneServer();

Schedule::call(fn () => $track(
    'data-integrity-audit',
    'Read-only Data Integrity Audit',
    'Every 15 minutes',
    fn () => app(DataIntegrityAuditor::class)->run()
))->everyFifteenMinutes()->timezone($timezone)->name('data-integrity-audit')->withoutOverlapping();