<?php

namespace App\Jobs;

use App\Models\IntegrationWebhookDelivery;
use App\Services\PublicWebhookDestinationResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverIntegrationWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(PublicWebhookDestinationResolver $resolver): void
    {
        $delivery = IntegrationWebhookDelivery::with('endpoint')->find($this->deliveryId);
        if (! $delivery || ! $delivery->endpoint || ! $delivery->endpoint->is_active) return;

        $endpoint = $delivery->endpoint;
        $url = trim((string) $endpoint->url);

        try {
            $target = $resolver->resolve($url);
        } catch (\Throwable $exception) {
            $this->markFailed($delivery, $exception->getMessage());
            return;
        }

        if (! defined('CURLOPT_RESOLVE')) {
            $this->markFailed($delivery, 'The server cURL resolver is unavailable; webhook delivery was blocked safely.');
            return;
        }

        $secret = $endpoint->secret;
        if (! filled($secret)) {
            $this->markFailed($delivery, 'Webhook signing secret could not be decrypted.');
            return;
        }

        $body = json_encode([
            'id' => (string) $delivery->id,
            'event' => $delivery->event,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($body)) {
            $this->markFailed($delivery, 'Webhook payload could not be encoded as JSON.');
            return;
        }

        $signature = hash_hmac('sha256', $body, $secret);
        $delivery->increment('attempts');

        try {
            $response = Http::timeout(12)
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => [$target['curl_resolve']],
                    ],
                ])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Signature' => 'sha256='.$signature,
                    'User-Agent' => 'X-Link-Billing-Webhooks/1.0',
                ])->send('POST', $url, ['body' => $body]);

            $delivery->forceFill([
                'response_status' => $response->status(),
                'response_excerpt' => mb_substr((string) $response->body(), 0, 2000),
                'status' => $response->successful() ? 'delivered' : 'retrying',
                'last_error' => $response->successful() ? null : 'HTTP '.$response->status(),
                'delivered_at' => $response->successful() ? now() : null,
            ])->save();

            $endpoint->forceFill([
                'last_delivered_at' => $response->successful() ? now() : $endpoint->last_delivered_at,
                'last_failed_at' => $response->successful() ? $endpoint->last_failed_at : now(),
            ])->save();

            if (! $response->successful()) {
                throw new \RuntimeException('Webhook endpoint returned HTTP '.$response->status());
            }
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => 'retrying',
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();
            Log::warning('X-Link webhook delivery attempt failed.', [
                'delivery_id' => $delivery->id,
                'endpoint_id' => $endpoint->id,
                'attempt' => $delivery->attempts,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        IntegrationWebhookDelivery::whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }

    private function markFailed(IntegrationWebhookDelivery $delivery, string $message): void
    {
        $delivery->forceFill(['status' => 'failed', 'last_error' => mb_substr($message, 0, 1000)])->save();
    }
}
