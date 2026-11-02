<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\PackageList;
use App\Models\StripePackagePrice;
use App\Services\StripeSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class StripeSubscriptionController extends Controller
{
    public function checkout(Request $request, StripeSubscriptionService $subscriptions): RedirectResponse
    {
        $customer = $request->user('ppp')?->customer;
        abort_unless($customer, 403, 'Customer profile is not linked to this portal account.');

        try {
            return redirect()->away($subscriptions->startCheckout($customer));
        } catch (Throwable $e) {
            Log::warning('Stripe subscription checkout could not be started.', [
                'customer_unique_id' => $customer->customer_unique_id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', $e->getMessage());
        }
    }

    public function portal(Request $request, StripeSubscriptionService $subscriptions): RedirectResponse
    {
        $customer = $request->user('ppp')?->customer;
        abort_unless($customer, 403, 'Customer profile is not linked to this portal account.');

        try {
            return redirect()->away($subscriptions->createBillingPortalUrl($customer));
        } catch (Throwable $e) {
            Log::warning('Stripe Billing Portal could not be opened.', [
                'customer_unique_id' => $customer->customer_unique_id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Subscription management is temporarily unavailable.');
        }
    }

    public function success(Request $request): RedirectResponse
    {
        // Checkout redirect is not proof of a paid invoice. The signed invoice webhook updates billing.
        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('success', 'Checkout completed. Your subscription and payment will appear after Stripe webhook verification.');
    }

    public function cancel(): RedirectResponse
    {
        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('error', 'Subscription checkout was cancelled. Your existing billing was not changed.');
    }

    public function savePriceMapping(Request $request, StripeSubscriptionService $subscriptions): RedirectResponse
    {
        $this->authorizeModule('payment-setup');

        $data = $request->validate([
            'package_id' => ['required', 'integer', 'exists:package_lists,id'],
            'stripe_price_id' => ['required', 'string', 'max:120', 'regex:/^price_[A-Za-z0-9]+$/'],
        ]);

        try {
            $package = PackageList::findOrFail($data['package_id']);
            $verified = $subscriptions->inspectPriceForPackage($package, $data['stripe_price_id']);

            StripePackagePrice::updateOrCreate(
                ['package_id' => $package->id],
                $verified + [
                    'is_active' => true,
                ]
            );

            return back()->with('success', 'Stripe recurring Price verified and mapped to the X-Link package.');
        } catch (Throwable $e) {
            Log::warning('Stripe package Price mapping failed.', [
                'package_id' => $data['package_id'],
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['stripe_price_id' => $e->getMessage()])
                ->withInput();
        }
    }

    public function togglePriceMapping(StripePackagePrice $mapping, StripeSubscriptionService $subscriptions): RedirectResponse
    {
        $this->authorizeModule('payment-setup');

        if ($mapping->is_active) {
            $mapping->update(['is_active' => false]);
            return back()->with('success', 'Stripe Price mapping disabled. Existing subscriptions are not cancelled.');
        }

        try {
            $package = $mapping->package;
            abort_unless($package, 404);
            $verified = $subscriptions->inspectPriceForPackage($package, $mapping->stripe_price_id);
            $mapping->update($verified + ['is_active' => true]);

            return back()->with('success', 'Stripe Price mapping re-verified and enabled.');
        } catch (Throwable $e) {
            Log::warning('Stripe Price mapping could not be enabled.', [
                'mapping_id' => $mapping->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['stripe_price_id' => $e->getMessage()]);
        }
    }

    public function deletePriceMapping(StripePackagePrice $mapping): RedirectResponse
    {
        $this->authorizeModule('payment-setup');
        $mapping->delete();

        return back()->with('success', 'Stripe Price mapping removed. Existing subscriptions remain in their current state.');
    }

    private function authorizeModule(string $permission): void
    {
        abort_unless(hasAccess(['Super Admin'], [$permission]), 403);
    }
}
