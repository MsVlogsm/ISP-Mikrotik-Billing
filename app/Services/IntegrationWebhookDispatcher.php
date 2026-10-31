<?php

namespace App\Services;

use App\Jobs\DeliverIntegrationWebhook;
use App\Models\IntegrationWebhookEndpoint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class IntegrationWebhookDispatcher
{
    public function dispatch(string $event, array $payload): int
    {
        if (! Schema::hasTable('integration_webhook_endpoints')) return 0;

        $endpoints = IntegrationWebhookEndpoint::query()
            ->where('is_active', true)
            ->whereJsonContains('events', $event)
            ->get();

        $queued = 0;
        foreach ($endpoints as $endpoint) {
            try {
                $delivery = $endpoint->deliveries()->create([
                    'event' => $event,
                    'payload' => $payload,
                    'status' => 'queued',
                ]);
                DeliverIntegrationWebhook::dispatch($delivery->id)->afterCommit();
                $queued++;
            } catch (\Throwable $exception) {
                Log::warning('X-Link webhook event could not be queued.', [
                    'endpoint_id' => $endpoint->id,
                    'event' => $event,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $queued;
    }
}
