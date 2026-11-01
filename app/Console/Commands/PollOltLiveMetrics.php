<?php

namespace App\Console\Commands;

use App\Models\NetworkInventoryDevice;
use App\Services\Olt\OltLiveMetricsService;
use Illuminate\Console\Command;
use Throwable;

class PollOltLiveMetrics extends Command
{
    protected $signature = 'olt:poll-live-metrics {--device= : Poll one OLT device ID only}';
    protected $description = 'Poll read-only OLT uptime, hardware, IF-MIB ports, ONU and optical metrics';

    public function handle(OltLiveMetricsService $service): int
    {
        $query = NetworkInventoryDevice::where('type', 'olt')->where('is_active', true)->orderBy('id');
        if ($this->option('device')) $query->whereKey((int) $this->option('device'));
        $devices = $query->get();
        if ($devices->isEmpty()) {
            $this->warn('No active OLT devices matched. No SNMP requests were sent.');
            return self::SUCCESS;
        }

        $success = 0; $failed = 0;
        foreach ($devices as $device) {
            try {
                $snapshot = $service->poll($device);
                $this->line(sprintf(
                    'OLT #%d %s: %d ONU (%d online), %d interfaces (%d PON up, %d uplinks up), sampled in %d ms; snapshot #%d.',
                    $device->id, $device->name, $snapshot->onu_total, $snapshot->onu_online,
                    $snapshot->interface_count, $snapshot->pon_up, $snapshot->uplink_up,
                    $snapshot->poll_duration_ms, $snapshot->id
                ));
                $success++;
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error('OLT #'.$device->id.' '.$device->name.': '.$exception->getMessage());
            }
        }

        $this->info("Live metrics poll complete: {$success} successful, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
