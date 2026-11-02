<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use App\Services\StripeSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeSubscriptionService $subscriptions)
    {
        $secret = config('services.stripe.webhook_secret');
        if (! filled($secret)) {
            return response('Stripe webhook is not configured.', 503);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature or payload validation failed.', [
                'message' => $e->getMessage(),
            ]);

            return response('Invalid Stripe webhook signature or payload.', 400);
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook could not be parsed.', [
                'message' => $e->getMessage(),
            ]);

            return response('Invalid Stripe webhook payload.', 400);
        }

        $objectId = data_get($event, 'data.object.id');
        $claim = DB::transaction(function () use ($event, $objectId) {
            StripeWebhookEvent::firstOrCreate(
                ['stripe_event_id' => $event->id],
                [
                    'event_type' => $event->type,
                    'object_id' => $objectId,
                    'processing_status' => 'received',
                ]
            );

            $record = StripeWebhookEvent::where('stripe_event_id', $event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->processing_status === 'processed') {
                return ['record' => $record, 'skip' => true, 'status' => 200];
            }

            if ($record->processing_status === 'processing'
                && $record->updated_at?->gt(now()->subMinutes(5))) {
                // Ask Stripe to retry if another delivery is still processing this event.
                return ['record' => $record, 'skip' => true, 'status' => 503];
            }

            $record->update([
                'event_type' => $event->type,
                'object_id' => $objectId,
                'processing_status' => 'processing',
                'attempts' => $record->attempts + 1,
                'last_error' => null,
            ]);

            return ['record' => $record->fresh(), 'skip' => false, 'status' => 200];
        });

        if ($claim['skip']) {
            return response(
                'Webhook already processed or another delivery is still processing this event.',
                $claim['status']
            );
        }

        try {
            $subscriptions->processEvent($event);

            $claim['record']->update([
                'processing_status' => 'processed',
                'processed_at' => now(),
                'last_error' => null,
            ]);

            return response('Webhook processed.', 200);
        } catch (\Throwable $e) {
            $claim['record']->update([
                'processing_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 4000),
            ]);

            Log::error('Stripe webhook processing failed and will be retried.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'object_id' => $objectId,
                'error' => $e->getMessage(),
            ]);

            return response('Stripe webhook processing failed.', 500);
        }
    }
}
