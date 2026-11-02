<?php

namespace App\Services;

use App\Models\CustomersInfo;
use App\Models\PackageList;
use App\Models\StripeCustomerLink;
use App\Models\StripePackagePrice;
use App\Models\StripeSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\StripeClient;

class StripeSubscriptionService
{
    public function enabled(): bool
    {
        return (bool) config('services.stripe.enabled', false);
    }

    private function apiKeyMatchesMode(): bool
    {
        $secret = (string) config('services.stripe.secret', '');
        $mode = strtolower((string) config('services.stripe.mode', 'test'));

        return match ($mode) {
            'test' => str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_'),
            'live' => str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_'),
            default => false,
        };
    }

    public function configured(): bool
    {
        // Configuration can be validated in the admin screen while checkout remains disabled.
        return $this->apiKeyMatchesMode()
            && filled(config('services.stripe.webhook_secret'));
    }

    public function client(): StripeClient
    {
        $secret = config('services.stripe.secret');
        if (! filled($secret)) {
            throw new RuntimeException('Stripe secret key is not configured on the server.');
        }

        if (! $this->apiKeyMatchesMode()) {
            throw new RuntimeException('Stripe API key prefix does not match STRIPE_MODE. Use a test key for test mode and a live key for live mode.');
        }

        return new StripeClient([
            'api_key' => $secret,
            'max_network_retries' => 2,
        ]);
    }

    /**
     * Fetch and verify a recurring Price before allowing an admin to map it.
     * X-Link's current customer ledger is in BDT, so non-BDT Prices are rejected.
     */
    public function inspectPriceForPackage(PackageList $package, string $priceId): array
    {
        $price = $this->client()->prices->retrieve($priceId, []);
        $currency = strtolower((string) ($price->currency ?? ''));
        $expectedCurrency = strtolower((string) config('services.stripe.currency', 'bdt'));
        $recurring = $price->recurring ?? null;
        $unitAmount = $price->unit_amount;

        if (! ($price->active ?? false)) {
            throw new RuntimeException('The selected Stripe Price is not active.');
        }

        if (! $recurring || ($recurring->interval ?? null) !== 'month' || (int) ($recurring->interval_count ?? 1) !== 1) {
            throw new RuntimeException('X-Link internet packages require a monthly Stripe Price with a one-month billing interval.');
        }

        if ($currency !== $expectedCurrency) {
            throw new RuntimeException('Stripe Price currency must match X-Link billing currency ('.strtoupper($expectedCurrency).').');
        }

        $expectedMinorAmount = (int) round(((float) $package->price) * 100);
        if (! is_numeric($unitAmount) || (int) $unitAmount !== $expectedMinorAmount) {
            throw new RuntimeException(
                'Stripe Price amount does not match the X-Link package price of '.number_format((float) $package->price, 2).' '.strtoupper($expectedCurrency).'.'
            );
        }

        $product = $price->product ?? null;
        $productId = is_string($product) ? $product : ($product->id ?? null);

        return [
            'stripe_product_id' => $productId,
            'stripe_price_id' => $price->id,
            'currency' => $currency,
            'unit_amount' => (int) $unitAmount,
            'recurring_interval' => (string) $recurring->interval,
            'interval_count' => (int) ($recurring->interval_count ?? 1),
            'verified_at' => now(),
        ];
    }

    public function startCheckout(CustomersInfo $customer): string
    {
        $lock = Cache::lock('xlink:stripe:checkout:'.$customer->customer_unique_id, 180);

        return $lock->block(10, fn () => $this->startCheckoutLocked($customer));
    }

    private function startCheckoutLocked(CustomersInfo $customer): string
    {
        if (! $this->enabled() || ! $this->configured()) {
            throw new RuntimeException('Stripe subscriptions are disabled or the server credentials are incomplete.');
        }

        $customer->loadMissing(['package', 'billing']);
        if (! $customer->package || ! $customer->billing) {
            throw new RuntimeException('A valid X-Link package and billing record are required before starting a subscription.');
        }

        $mapping = StripePackagePrice::query()
            ->where('package_id', $customer->package_id)
            ->where('is_active', true)
            ->first();

        if (! $mapping) {
            throw new RuntimeException('A verified Stripe recurring Price has not been configured for your current package.');
        }

        $customerRentMinor = (int) round(((float) $customer->billing->monthly_rent) * 100);
        if ($customerRentMinor !== (int) $mapping->unit_amount) {
            throw new RuntimeException('The Stripe monthly Price must exactly match this customer\'s current monthly rent. Please contact the billing administrator.');
        }

        $stripe = $this->client();
        $remotePrice = $stripe->prices->retrieve($mapping->stripe_price_id, []);
        $this->assertRemotePriceMatchesMapping($remotePrice, $mapping, $customer->package);

        $active = StripeSubscription::query()
            ->where('customer_unique_id', $customer->customer_unique_id)
            ->whereNotNull('stripe_subscription_id')
            ->whereIn('status', ['active', 'trialing', 'past_due', 'incomplete', 'paused', 'unpaid'])
            ->latest('id')
            ->first();

        if ($active) {
            throw new RuntimeException('You already have a Stripe subscription. Use Manage Subscription to update your billing details.');
        }

        $link = $this->getOrCreateStripeCustomer($customer, $stripe);

        $pending = StripeSubscription::query()
            ->where('customer_unique_id', $customer->customer_unique_id)
            ->whereIn('status', ['checkout_starting', 'checkout_pending'])
            ->where('checkout_expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($pending && $pending->checkout_session_id) {
            try {
                $existingSession = $stripe->checkout->sessions->retrieve($pending->checkout_session_id, []);
                $sessionStatus = (string) ($existingSession->status ?? '');

                if ($sessionStatus === 'open' && filled($existingSession->url ?? null)) {
                    return $existingSession->url;
                }

                if ($sessionStatus === 'complete') {
                    throw new RuntimeException('Your Stripe checkout has completed and is waiting for secure webhook confirmation. Please refresh your billing page shortly.');
                }

                if ($sessionStatus === 'expired') {
                    $pending->update(['status' => 'checkout_expired']);
                    $pending = null;
                } else {
                    throw new RuntimeException('An existing Stripe checkout could not be safely resumed. Please retry after its status is confirmed.');
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e);
                throw new RuntimeException('The existing Stripe checkout status could not be verified. Please retry shortly.', 0, $e);
            }
        } elseif ($pending && $pending->created_at?->gt(now()->subMinutes(3))) {
            throw new RuntimeException('Your subscription checkout is being prepared. Please retry in a moment.');
        }

        // Reuse the same local placeholder and Stripe idempotency key after a stale
        // in-flight request, rather than creating a second remote Checkout Session.
        $idempotencyKey = $pending?->checkout_idempotency_key ?: (string) Str::uuid();
        $local = $pending ?: StripeSubscription::create([
            'customer_unique_id' => $customer->customer_unique_id,
            'stripe_customer_id' => $link->stripe_customer_id,
            'checkout_idempotency_key' => $idempotencyKey,
            'package_id' => $customer->package_id,
            'stripe_price_id' => $mapping->stripe_price_id,
            'status' => 'checkout_starting',
            'currency' => $mapping->currency,
            'unit_amount' => $mapping->unit_amount,
            'checkout_expires_at' => now()->addMinutes(35),
        ]);

        try {
            $session = $stripe->checkout->sessions->create([
                'mode' => 'subscription',
                'customer' => $link->stripe_customer_id,
                'client_reference_id' => $customer->customer_unique_id,
                'line_items' => [[
                    'price' => $mapping->stripe_price_id,
                    'quantity' => 1,
                ]],
                'billing_address_collection' => 'auto',
                'phone_number_collection' => ['enabled' => true],
                'metadata' => [
                    'xlink_customer_unique_id' => $customer->customer_unique_id,
                    'xlink_package_id' => (string) $customer->package_id,
                    'xlink_local_subscription_id' => (string) $local->id,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'xlink_customer_unique_id' => $customer->customer_unique_id,
                        'xlink_package_id' => (string) $customer->package_id,
                        'xlink_local_subscription_id' => (string) $local->id,
                    ],
                ],
                'success_url' => route('payment.stripe.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('payment.stripe.cancel'),
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            $local->refresh();
            $checkoutUpdates = [
                'checkout_session_id' => $session->id,
                'checkout_expires_at' => isset($session->expires_at)
                    ? Carbon::createFromTimestamp((int) $session->expires_at)
                    : now()->addMinutes(30),
            ];
            // A fast Stripe webhook may already have promoted this row to active.
            if (! $local->stripe_subscription_id) {
                $checkoutUpdates['status'] = 'checkout_pending';
            }
            $local->update($checkoutUpdates);

            if (! filled($session->url ?? null)) {
                throw new RuntimeException('Stripe did not return a Checkout URL.');
            }

            return $session->url;
        } catch (\Throwable $e) {
            $local->refresh();
            if (! $local->stripe_subscription_id) {
                $local->update(['status' => 'checkout_failed']);
            }
            throw $e;
        }
    }

    public function createBillingPortalUrl(CustomersInfo $customer): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Stripe subscription management is not configured.');
        }

        $link = StripeCustomerLink::where('customer_unique_id', $customer->customer_unique_id)->first();
        if (! $link) {
            throw new RuntimeException('No Stripe Customer is linked to this X-Link account.');
        }

        $session = $this->client()->billingPortal->sessions->create([
            'customer' => $link->stripe_customer_id,
            'return_url' => route('filament.portal.pages.pay-bill'),
        ]);

        return $session->url;
    }

    public function syncSubscription(object $subscription): ?StripeSubscription
    {
        $subscriptionId = $subscription->id ?? null;
        $existing = $subscriptionId
            ? StripeSubscription::where('stripe_subscription_id', $subscriptionId)->first()
            : null;

        $metadata = $subscription->metadata ?? [];
        $localId = data_get($metadata, 'xlink_local_subscription_id');
        if (! $existing && is_numeric($localId)) {
            $existing = StripeSubscription::find((int) $localId);
        }
        $customerUniqueId = data_get($metadata, 'xlink_customer_unique_id')
            ?: $existing?->customer_unique_id;

        if (! filled($customerUniqueId) || ! filled($subscriptionId)) {
            return $existing;
        }

        $stripeCustomerId = $this->stripeObjectId($subscription->customer ?? null);
        if (! filled($stripeCustomerId)) {
            throw new RuntimeException('Stripe subscription is missing its Customer ID.');
        }

        $link = StripeCustomerLink::where('customer_unique_id', $customerUniqueId)->first();
        if (! $link || $link->stripe_customer_id !== $stripeCustomerId) {
            throw new RuntimeException('Stripe Customer is not linked to this X-Link customer through a verified Checkout session.');
        }

        $items = $subscription->items->data ?? [];
        $firstItem = $items[0] ?? null;
        $price = $firstItem?->price;
        $priceObject = is_object($price) ? $price : null;
        $priceId = is_string($price) ? $price : ($priceObject->id ?? null);
        $packageId = data_get($metadata, 'xlink_package_id') ?: $existing?->package_id;

        if ($existing && $existing->stripe_price_id && $priceId && $existing->stripe_price_id !== $priceId) {
            throw new RuntimeException('Stripe subscription Price changed without an approved X-Link Price mapping.');
        }

        if ($existing && $priceObject && $existing->currency
            && strtolower((string) $existing->currency) !== strtolower((string) $priceObject->currency)) {
            throw new RuntimeException('Stripe subscription currency changed after Checkout verification.');
        }

        if ($existing && $priceObject && $existing->unit_amount !== null
            && (int) $existing->unit_amount !== (int) ($priceObject->unit_amount ?? 0)) {
            throw new RuntimeException('Stripe subscription amount changed after Checkout verification.');
        }

        $periodStart = $firstItem->current_period_start ?? $subscription->current_period_start ?? null;
        $periodEnd = $firstItem->current_period_end ?? $subscription->current_period_end ?? null;

        $attributes = [
            'stripe_subscription_id' => $subscriptionId,
            'customer_unique_id' => $customerUniqueId,
            'stripe_customer_id' => $stripeCustomerId,
            'package_id' => $packageId,
            'stripe_price_id' => $priceId ?: $existing?->stripe_price_id,
            'status' => (string) ($subscription->status ?? 'unknown'),
            'currency' => $priceObject?->currency ?? $existing?->currency,
            'unit_amount' => isset($priceObject?->unit_amount) ? (int) $priceObject->unit_amount : $existing?->unit_amount,
            'current_period_start' => $periodStart ? Carbon::createFromTimestamp((int) $periodStart) : null,
            'current_period_end' => $periodEnd ? Carbon::createFromTimestamp((int) $periodEnd) : null,
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
            'canceled_at' => ! empty($subscription->canceled_at)
                ? Carbon::createFromTimestamp((int) $subscription->canceled_at)
                : null,
            'last_invoice_id' => $this->stripeObjectId($subscription->latest_invoice ?? null),
        ];

        if ($existing) {
            $existing->fill($attributes)->save();
            return $existing->fresh();
        }

        return StripeSubscription::create($attributes);
    }

    public function processInvoicePaid(object $invoice): void
    {
        $subscriptionId = $this->invoiceSubscriptionId($invoice);
        if (! filled($subscriptionId)) {
            return; // This endpoint only reconciles Stripe subscription invoices.
        }

        $stripe = $this->client();
        $subscription = $stripe->subscriptions->retrieve($subscriptionId, []);
        $localSubscription = $this->syncSubscription($subscription);

        $customerUniqueId = $localSubscription?->customer_unique_id
            ?: data_get($subscription->metadata ?? [], 'xlink_customer_unique_id');

        if (! filled($customerUniqueId)) {
            throw new RuntimeException('Stripe subscription is not mapped to an X-Link customer.');
        }

        $stripeCustomerId = $this->stripeObjectId($invoice->customer ?? null);
        if (! filled($stripeCustomerId) || $stripeCustomerId !== $localSubscription?->stripe_customer_id) {
            throw new RuntimeException('Stripe invoice Customer does not match the X-Link subscription mapping.');
        }

        $currency = strtolower((string) ($invoice->currency ?? ''));
        $expectedCurrency = strtolower((string) config('services.stripe.currency', 'bdt'));
        if ($currency !== $expectedCurrency || $currency !== strtolower((string) ($localSubscription?->currency ?? $expectedCurrency))) {
            throw new RuntimeException('Stripe invoice currency does not match the verified recurring Price.');
        }

        $amountMinor = (int) ($invoice->amount_paid ?? 0);
        if ($amountMinor <= 0 || ! ($invoice->paid ?? false)) {
            return;
        }

        $invoiceId = (string) ($invoice->id ?? '');
        if ($invoiceId === '') {
            throw new RuntimeException('Stripe paid invoice has no ID.');
        }

        $customer = CustomersInfo::query()
            ->with(['billing', 'pppUser'])
            ->where('customer_unique_id', $customerUniqueId)
            ->firstOrFail();

        $currentRentMinor = (int) round(((float) ($customer->billing?->monthly_rent ?? 0)) * 100);
        if ($currentRentMinor !== (int) ($localSubscription?->unit_amount ?? -1)) {
            throw new RuntimeException('The customer monthly rent no longer matches the verified Stripe subscription amount. Invoice reconciliation was blocked.');
        }

        // BDT uses two minor-unit decimal places. Only verified BDT invoices reach X-Link's BDT ledger.
        $amount = round($amountMinor / 100, 2);

        app(PaymentService::class)->processSuccessPayment(
            $customer,
            $amount,
            'stripe',
            $invoiceId,
            [
                'source' => 'stripe_subscription',
                'external_invoice_id' => $invoiceId,
                'notes' => 'Recurring Stripe subscription invoice',
            ]
        );

        $localSubscription?->update(['last_invoice_id' => $invoiceId]);
    }

    public function markInvoiceFailed(object $invoice): void
    {
        $subscriptionId = $this->invoiceSubscriptionId($invoice);
        if (! filled($subscriptionId)) {
            return;
        }

        $remoteSubscription = $this->client()->subscriptions->retrieve($subscriptionId, []);
        $local = $this->syncSubscription($remoteSubscription);
        if ($local) {
            $local->update([
                'status' => 'past_due',
                'last_invoice_id' => (string) ($invoice->id ?? $local->last_invoice_id),
            ]);
        }
    }

    public function processEvent(object $event): void
    {
        $object = $event->data->object ?? null;
        if (! $object) {
            return;
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $subscriptionId = $this->stripeObjectId($object->subscription ?? null);
                if ($subscriptionId) {
                    $subscription = $this->client()->subscriptions->retrieve($subscriptionId, []);
                    $this->syncSubscription($subscription);
                }
                break;

            case 'checkout.session.expired':
                StripeSubscription::where('checkout_session_id', $object->id ?? null)
                    ->whereNull('stripe_subscription_id')
                    ->update(['status' => 'checkout_expired']);
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
            case 'customer.subscription.paused':
            case 'customer.subscription.resumed':
                // Stripe may deliver events out of order. Re-fetch the current
                // Subscription state instead of trusting a possibly stale event snapshot.
                $subscriptionId = $object->id ?? null;
                if (filled($subscriptionId)) {
                    $currentSubscription = $this->client()->subscriptions->retrieve($subscriptionId, []);
                    $this->syncSubscription($currentSubscription);
                }
                break;

            case 'invoice.paid':
                $this->processInvoicePaid($object);
                break;

            case 'invoice.payment_failed':
            case 'invoice.payment_action_required':
                $this->markInvoiceFailed($object);
                break;
        }
    }

    private function getOrCreateStripeCustomer(CustomersInfo $customer, StripeClient $stripe): StripeCustomerLink
    {
        $link = StripeCustomerLink::where('customer_unique_id', $customer->customer_unique_id)->first();
        if ($link) {
            return $link;
        }

        $remote = $stripe->customers->create([
            'name' => $customer->customer_name ?: $customer->customer_unique_id,
            'email' => $customer->email ?: null,
            'phone' => $customer->mobile ?: null,
            'metadata' => [
                'xlink_customer_unique_id' => $customer->customer_unique_id,
            ],
        ], [
            'idempotency_key' => 'xlink_customer_'.hash('sha256', $customer->customer_unique_id),
        ]);

        return StripeCustomerLink::firstOrCreate(
            ['customer_unique_id' => $customer->customer_unique_id],
            ['stripe_customer_id' => $remote->id]
        );
    }

    private function assertRemotePriceMatchesMapping(object $price, StripePackagePrice $mapping, PackageList $package): void
    {
        if (! ($price->active ?? false)
            || ! ($price->recurring ?? null)
            || ($price->recurring->interval ?? null) !== 'month'
            || (int) ($price->recurring->interval_count ?? 1) !== 1) {
            throw new RuntimeException('The mapped Stripe Price must remain active with a monthly recurring interval.');
        }

        if ((string) $price->id !== $mapping->stripe_price_id
            || strtolower((string) $price->currency) !== strtolower($mapping->currency)
            || (int) $price->unit_amount !== (int) $mapping->unit_amount
            || (int) $price->unit_amount !== (int) round(((float) $package->price) * 100)) {
            throw new RuntimeException('The Stripe Price no longer matches the verified X-Link package mapping.');
        }
    }

    private function invoiceSubscriptionId(object $invoice): ?string
    {
        $subscription = $invoice->subscription ?? null;
        if (! $subscription) {
            $subscription = data_get($invoice, 'parent.subscription_details.subscription');
        }

        return $this->stripeObjectId($subscription);
    }

    private function stripeObjectId(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_object($value) && isset($value->id) ? (string) $value->id : null;
    }
}
