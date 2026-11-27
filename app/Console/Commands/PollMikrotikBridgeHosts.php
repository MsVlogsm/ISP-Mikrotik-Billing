<?php

namespace App\Console\Commands;

use App\Models\RouterList;
use App\Services\MikrotikBridgeHostService;
use Illuminate\Console\Command;

class PollMikrotikBridgeHosts extends Command
{
    protected $signature = 'app:poll-mikrotik-bridge-hosts';

    protected $description = 'Collect read-only MikroTik RouterOS bridge host snapshots and MAC movements';

    public function handle(MikrotikBridgeHostService $service): int
    {
        if (! (bool) config('mikrotik_bridge_host.scheduled_poll_enabled', false)) {
            $this->components->info('Scheduled MikroTik Bridge Host polling is disabled.');

            return self::SUCCESS;
        }

        $routers = RouterList::query()
            ->where('action', 'connected')
            ->whereNotNull('api_port')
            ->orderBy('id')
            ->get();

        if ($routers->isEmpty()) {
            $this->components->warn('No connected MikroTik router with API access was found.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($routers as $router) {
            $snapshot = $service->poll($router);

            if (in_array($snapshot->status, ['ok', 'empty'], true)) {
                $ok++;
                $this->line(sprintf(
                    '%s (#%d): %s, %d hosts, %d ms',
                    $router->router_name,
                    $router->id,
                    strtoupper($snapshot->status),
                    $snapshot->entry_count,
                    $snapshot->poll_duration_ms ?? 0
                ));
            } else {
                $failed++;
                $this->components->error(sprintf(
                    '%s (#%d): %s',
                    $router->router_name,
                    $router->id,
                    $snapshot->error_message ?: 'RouterOS Bridge Host polling failed.'
                ));
            }

            usleep(250000);
        }

        $this->newLine();
        $this->info(sprintf(
            'MikroTik Bridge Host polling complete: %d successful, %d failed, %d total.',
            $ok,
            $failed,
            $routers->count()
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
