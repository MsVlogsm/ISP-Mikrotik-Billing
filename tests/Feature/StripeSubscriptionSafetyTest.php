<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Services\StripeSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Tests\TestCase;

class StripeSubscriptionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function signedEvent(string $secret = 'whsec_unit_test_secret'): array
    {
        $payload = json_encode([
            'id' => 'evt_xlink_stripe_signature_test',
            'object' => 'event',
            'type' => 'invoice.paid',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'in_xlink_stripe_signature_test',
                    'object' => 'invoice',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return [
            $payload,
            't='.$timestamp.',v1='.$signature,
            $secret,
        ];
    }

    public function test_valid_stripe_webhook_signature_is_accepted(): void
    {
        [$payload, $header, $secret] = $this->signedEvent();

        $event = Webhook::constructEvent($payload, $header, $secret);

        $this->assertSame('evt_xlink_stripe_signature_test', $event->id);
        $this->assertSame('invoice.paid', $event->type);
    }

    public function test_invalid_stripe_webhook_signature_is_rejected(): void
    {
        [$payload, $header] = $this->signedEvent();

        $this->expectException(SignatureVerificationException::class);

        Webhook::constructEvent($payload, $header, 'whsec_wrong_secret');
    }

    public function test_stripe_checkout_remains_disabled_without_explicit_enable_flag(): void
    {
        config([
            'services.stripe.enabled' => false,
            'services.stripe.secret' => 'sk_test_unit_placeholder',
            'services.stripe.webhook_secret' => 'whsec_unit_placeholder',
        ]);

        $service = app(StripeSubscriptionService::class);

        $this->assertTrue($service->configured());
        $this->assertFalse($service->enabled());

        try {
            $service->startCheckout(new CustomersInfo());
            $this->fail('Stripe checkout must not run while STRIPE_ENABLED is false.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled', strtolower($exception->getMessage()));
        }
    }

    public function test_stripe_is_not_configured_when_server_secrets_are_missing(): void
    {
        config([
            'services.stripe.enabled' => false,
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
        ]);

        $service = app(StripeSubscriptionService::class);

        $this->assertFalse($service->configured());
        $this->assertFalse($service->enabled());
    }

    public function test_api_key_environment_must_match_stripe_mode(): void
    {
        config([
            'services.stripe.enabled' => false,
            'services.stripe.mode' => 'test',
            'services.stripe.secret' => 'sk_live_placeholder',
            'services.stripe.webhook_secret' => 'whsec_placeholder',
        ]);

        $this->assertFalse(app(StripeSubscriptionService::class)->configured());

        config([
            'services.stripe.mode' => 'live',
            'services.stripe.secret' => 'sk_live_placeholder',
        ]);

        $this->assertTrue(app(StripeSubscriptionService::class)->configured());
    }

    public function test_restricted_api_keys_are_supported_when_their_mode_matches(): void
    {
        config([
            'services.stripe.mode' => 'test',
            'services.stripe.secret' => 'rk_test_placeholder',
            'services.stripe.webhook_secret' => 'whsec_placeholder',
        ]);

        $this->assertTrue(app(StripeSubscriptionService::class)->configured());

        config(['services.stripe.mode' => 'live']);

        $this->assertFalse(app(StripeSubscriptionService::class)->configured());
    }

    public function test_webhook_endpoint_rejects_an_invalid_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_unit_test_secret']);

        $response = $this->postJson('/payment/stripe/webhook', [
            'id' => 'evt_invalid_webhook_test',
            'object' => 'event',
            'type' => 'invoice.paid',
        ], [
            'Stripe-Signature' => 't='.time().',v1=invalid',
        ]);

        $response->assertStatus(400);
    }

    public function test_webhook_endpoint_is_unavailable_without_signing_secret(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $response = $this->postJson('/payment/stripe/webhook', [
            'id' => 'evt_unconfigured_webhook_test',
            'object' => 'event',
            'type' => 'invoice.paid',
        ]);

        $response->assertStatus(503);
    }

    public function test_valid_webhook_is_processed_once_when_stripe_retries_same_event(): void
    {
        $secret = 'whsec_idempotency_test_secret';
        config(['services.stripe.webhook_secret' => $secret]);

        $payload = json_encode([
            'id' => 'evt_xlink_idempotency_test',
            'object' => 'event',
            'type' => 'customer.updated',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'cus_xlink_idempotency_test',
                    'object' => 'customer',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
        ];

        $first = $this->call('POST', '/payment/stripe/webhook', [], [], [], $headers, $payload);
        $first->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_xlink_idempotency_test',
            'processing_status' => 'processed',
            'attempts' => 1,
        ]);

        $second = $this->call('POST', '/payment/stripe/webhook', [], [], [], $headers, $payload);
        $second->assertOk();

        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_xlink_idempotency_test',
            'processing_status' => 'processed',
            'attempts' => 1,
        ]);
    }
}
