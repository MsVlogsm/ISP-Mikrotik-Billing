<?php

namespace App\Console\Commands;

use App\Models\NetworkInventoryDevice;
use App\Services\Olt\BridgeFdbSnmpService;
use Illuminate\Console\Command;

class PollBridgeFdb extends Command
{
    protected $signature = 'olt:poll-bridge-fdb {--device= : Poll only one inventory device ID}';

    protected $description = 'Collect read-only SNMP Bridge FDB snapshots from active OLTs and switches';

    public function handle(BridgeFdbSnmpService $service): int
    {
        if (! (bool) config('bridge_fdb.scheduled_poll_enabled', false)) {
            $this->components->info('Scheduled Bridge FDB polling is disabled.');

            return self::SUCCESS;
        }

        $query = NetworkInventoryDevice::query()
            ->whereIn('type', ['olt', 'switch'])
            ->where('is_active', true)
            ->whereNotNull('snmp_community')
            ->where(function ($query): void {
                $query->whereNull('snmp_version')->orWhere('snmp_version', '!=', '3');
            })
            ->orderBy('id');

        if ($deviceId = $this->option('device')) {
            $query->whereKey((int) $deviceId);
        }

        $devices = $query->get();
        if ($devices->isEmpty()) {
            $this->components->warn('No active OLT/switch with configured SNMP v1/v2c community was found.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($devices as $device) {
            $snapshot = $service->poll($device);
            $label = sprintf('%s (#%d)', $device->name, $device->id);

            if (in_array($snapshot->status, ['ok', 'empty'], true)) {
                $ok++;
                $this->line(sprintf(
                    '%s: %s, %d entries (%d learned), %d ms',
                    $label,
                    strtoupper($snapshot->status),
                    $snapshot->entry_count,
                    $snapshot->learned_count,
                    $snapshot->poll_duration_ms
                ));
            } else {
                $failed++;
                $this->components->error($label.': '.($snapshot->error_message ?: 'SNMP polling failed.'));
            }

            // Avoid back-to-back SNMP walks across multiple management agents.
            usleep(250000);
        }

        $this->newLine();
        $this->info(sprintf('Bridge FDB polling complete: %d successful, %d failed, %d total.', $ok, $failed, $devices->count()));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
