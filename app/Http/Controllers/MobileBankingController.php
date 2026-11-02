<?php

namespace App\Http\Controllers;

use App\Models\CollectionSummary;
use App\Models\MainSiteData;
use Illuminate\Http\Request;

class MobileBankingController extends Controller
{
    public function index()
    {
        $this->authorizeModule('payment-collection');
        $mfs = CollectionSummary::query()
            ->whereIn('payment_method', ['bkash', 'nagad', 'rocket', 'upay', 'sslcommerz']);
        $stripe = CollectionSummary::query()
            ->where('payment_method', 'stripe')
            ->where('payment_type', 'online');

        return view('mobile-banking.index', [
            'tab' => 'overview',
            'stats' => [
                ['label' => 'MFS Collections (30d)', 'value' => number_format((clone $mfs)->where('collection_date', '>=', now()->subDays(30))->sum('collection_amount'), 2)],
                ['label' => 'MFS Transactions (30d)', 'value' => (clone $mfs)->where('collection_date', '>=', now()->subDays(30))->count()],
                ['label' => 'Stripe Subscription Collections (30d)', 'value' => number_format((clone $stripe)->where('collection_date', '>=', now()->subDays(30))->sum('collection_amount'), 2)],
                ['label' => 'bKash', 'value' => MainSiteData::getValue('payment_bkash_enabled', 0) ? 'Enabled' : 'Disabled'],
                ['label' => 'Nagad', 'value' => MainSiteData::getValue('payment_nagad_enabled', 0) ? 'Enabled' : 'Disabled'],
            ],
        ]);
    }

    public function logs(Request $request)
    {
        $this->authorizeModule('payment-history');
        $query = CollectionSummary::query()->with('customer')->where(function ($q) {
            $q->where('payment_type', 'online')
                ->orWhereIn('payment_method', ['bkash', 'nagad', 'rocket', 'upay', 'sslcommerz']);
        });
        $query->when($request->filled('status'), fn ($q) => $q->where('payment_status', $request->string('status')));
        $query->when($request->filled('method'), fn ($q) => $q->where('payment_method', $request->string('method')));
        $query->when($request->filled('q'), function ($q) use ($request) {
            $term = '%'.$request->string('q')->toString().'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('customer_collection_unique_id', 'like', $term)
                    ->orWhere('transaction_id', 'like', $term)
                    ->orWhere('invoice_no', 'like', $term);
            });
        });
        return view('mobile-banking.index', [
            'tab' => 'logs',
            'logs' => $query->latest('collection_date')->paginate(25)->withQueryString(),
        ]);
    }

    public function gateways()
    {
        $this->authorizeModule('payment-setup');
        $gateways = [
            ['code' => 'bkash', 'name' => 'bKash', 'enabled' => (bool) MainSiteData::getValue('payment_bkash_enabled', 0), 'type' => MainSiteData::getValue('payment_bkash_api_type', 'tokenized'), 'endpoint' => MainSiteData::getValue('payment_bkash_base_url', config('services.bkash.base_url'))],
            ['code' => 'nagad', 'name' => 'Nagad', 'enabled' => (bool) MainSiteData::getValue('payment_nagad_enabled', 0), 'type' => 'DFS Checkout', 'endpoint' => MainSiteData::getValue('payment_nagad_base_url', config('services.nagad.base_url'))],
            ['code' => 'sslcommerz', 'name' => 'SSLCommerz', 'enabled' => (bool) MainSiteData::getValue('payment_sslcommerz_enabled', 0), 'type' => MainSiteData::getValue('payment_sslcommerz_sandbox', true) ? 'Sandbox' : 'Live', 'endpoint' => null],
            ['code' => 'stripe', 'name' => 'Stripe Subscriptions', 'enabled' => app(\App\Services\StripeSubscriptionService::class)->enabled() && app(\App\Services\StripeSubscriptionService::class)->configured(), 'type' => 'Recurring Checkout', 'endpoint' => null],
        ];
        return view('mobile-banking.index', ['tab' => 'gateways', 'gateways' => $gateways]);
    }

    public function settings()
    {
        $this->authorizeModule('site-settings');
        return view('mobile-banking.index', [
            'tab' => 'settings',
            'settings' => [
                'default_provider' => MainSiteData::getValue('payment_default_provider', 'bkash'),
                'default_number' => MainSiteData::getValue('payment_default_number', ''),
                'bkash_enabled' => (bool) MainSiteData::getValue('payment_bkash_enabled', 0),
                'nagad_enabled' => (bool) MainSiteData::getValue('payment_nagad_enabled', 0),
                'ssl_enabled' => (bool) MainSiteData::getValue('payment_sslcommerz_enabled', 0),
                'sms_enabled' => (bool) MainSiteData::getValue('sms_enabled', 0),
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->authorizeModule('site-settings');
        $data = $request->validate([
            'default_provider' => ['required', 'in:bkash,nagad,rocket,upay'],
            'default_number' => ['nullable', 'string', 'max:64'],
        ]);
        MainSiteData::setValue('payment_default_provider', $data['default_provider']);
        MainSiteData::setValue('payment_default_number', $data['default_number'] ?? '');
        return back()->with('success', 'Mobile Banking sync settings updated.');
    }

    public function subscriptions(\Illuminate\Http\Request $request, \App\Services\StripeSubscriptionService $stripe)
    {
        $this->authorizeModule('payment-setup');

        return view('mobile-banking.index', [
            'tab' => 'subscriptions',
            'stripeEnabled' => $stripe->enabled(),
            'stripeConfigured' => $stripe->configured(),
            'stripeMode' => config('services.stripe.mode', 'test'),
            'stripeCurrency' => strtoupper(config('services.stripe.currency', 'BDT')),
            'packages' => \App\Models\PackageList::query()->orderBy('package')->get(),
            'priceMappings' => \App\Models\StripePackagePrice::with('package')->orderBy('package_id')->get(),
            'activeSubscriptions' => \App\Models\StripeSubscription::whereIn('status', ['active', 'trialing'])->count(),
            'pastDueSubscriptions' => \App\Models\StripeSubscription::where('status', 'past_due')->count(),
            'webhookFailures' => \App\Models\StripeWebhookEvent::where('processing_status', 'failed')->count(),
            'recentSubscriptions' => \App\Models\StripeSubscription::with('package')->latest()->limit(25)->get(),
        ]);
    }

    public function methods()
    {
        $this->authorizeModule('payment-setup');
        $methods = MainSiteData::getValue('payment_methods', [
            ['name' => 'Cash', 'code' => 'cash', 'active' => true, 'default' => true],
            ['name' => 'Bank', 'code' => 'bank', 'active' => true, 'default' => false],
            ['name' => 'bKash', 'code' => 'bkash', 'active' => true, 'default' => false],
            ['name' => 'Nagad', 'code' => 'nagad', 'active' => true, 'default' => false],
        ]);
        return view('mobile-banking.index', ['tab' => 'methods', 'methods' => $methods]);
    }

    public function saveMethod(Request $request)
    {
        $this->authorizeModule('payment-setup');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'alpha_dash', 'max:40'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        $methods = MainSiteData::getValue('payment_methods', []);
        $methods = array_values(array_filter($methods, fn ($m) => ($m['code'] ?? '') !== $data['code']));
        if ($request->boolean('is_default')) {
            $methods = array_map(fn ($m) => array_merge($m, ['default' => false]), $methods);
        }
        $methods[] = ['name' => $data['name'], 'code' => strtolower($data['code']), 'active' => true, 'default' => $request->boolean('is_default')];
        MainSiteData::setValue('payment_methods', $methods);
        return redirect()->route('mobile-banking.methods')->with('success', 'Payment method saved.');
    }

    private function authorizeModule(string $permission): void
    {
        abort_unless(hasAccess(['Super Admin'], [$permission]), 403);
    }
}
