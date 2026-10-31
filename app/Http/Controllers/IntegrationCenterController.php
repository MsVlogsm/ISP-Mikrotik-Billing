<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverIntegrationWebhook;
use App\Models\IntegrationApiClient;
use App\Models\IntegrationWebhookEndpoint;
use App\Models\IntegrationWebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class IntegrationCenterController extends Controller
{
    private const API_SCOPES = [
        'customers:read', 'customers:contact:read', 'billing:read', 'packages:read',
        'reports:read', 'network:read',
    ];
    private const WEBHOOK_EVENTS = [
        'customer.created', 'customer.updated', 'customer.deleted',
        'customer.suspended', 'payment.received',
    ];
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin', 'Manager']), 403);
    }

    public function index()
    {
        $this->authorizeAdmin();

        $clients = IntegrationApiClient::with('owner')->latest()->paginate(15, ['*'], 'clients_page');
        $endpoints = IntegrationWebhookEndpoint::withCount('deliveries')->latest()->paginate(15, ['*'], 'webhooks_page');
        $deliveries = IntegrationWebhookDelivery::with('endpoint')->latest()->limit(25)->get();

        return view('xlink.integration-center', [
            'clients' => $clients,
            'endpoints' => $endpoints,
            'deliveries' => $deliveries,
            'apiScopes' => self::API_SCOPES,
            'webhookEvents' => self::WEBHOOK_EVENTS,
        ]);
    }

    public function storeClient(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::API_SCOPES)],
            'rate_limit_per_minute' => ['required', 'integer', 'min:10', 'max:600'],
        ]);

        $clientId = 'xlc_'.Str::random(32);
        $secret = 'xsec_'.Str::random(64);

        IntegrationApiClient::create([
            'user_id' => $request->user()->id,
            'name' => trim($data['name']),
            'client_id' => $clientId,
            'client_secret_hash' => hash('sha256', $secret),
            'scopes' => array_values(array_unique($data['scopes'])),
            'rate_limit_per_minute' => $data['rate_limit_per_minute'],
            'is_active' => true,
        ]);

        return redirect()->route('ipilot.integrations')
            ->with('integration_message', 'API client created. Copy the secret now; it will not be shown again.')
            ->with('new_api_client', ['client_id' => $clientId, 'client_secret' => $secret]);
    }

    public function updateClient(Request $request, IntegrationApiClient $client): RedirectResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::API_SCOPES)],
            'rate_limit_per_minute' => ['required', 'integer', 'min:10', 'max:600'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $client->update([
            'scopes' => array_values(array_unique($data['scopes'])),
            'rate_limit_per_minute' => $data['rate_limit_per_minute'],
            'is_active' => $request->boolean('is_active'),
        ]);

        // Sanctum tokens keep the abilities they were issued with. Revoke every
        // existing token whenever client settings change so old scopes/rates
        // cannot remain valid until their natural expiry.
        PersonalAccessToken::query()
            ->where('tokenable_type', \App\Models\User::class)
            ->where('tokenable_id', $client->user_id)
            ->where('name', 'api-client:'.$client->client_id)
            ->delete();

        return back()->with('integration_message', 'API client permissions and status updated. Existing access tokens were revoked; the client must authenticate again.');
    }

    public function deleteClient(IntegrationApiClient $client): RedirectResponse
    {
        $this->authorizeAdmin();
        PersonalAccessToken::query()
            ->where('tokenable_type', \App\Models\User::class)
            ->where('tokenable_id', $client->user_id)
            ->where('name', 'api-client:'.$client->client_id)
            ->delete();
        $client->delete();

        return back()->with('integration_message', 'API client and its active access tokens have been revoked.');
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::WEBHOOK_EVENTS)],
        ]);

        $parts = parse_url($data['url']);
        abort_unless(
            is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'], $parts['pass']),
            422,
            'Webhook destinations must use HTTPS and cannot contain embedded credentials.'
        );

        $host = trim((string) $parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            abort_unless(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 422, 'Private or reserved webhook IP addresses are not allowed.');
        }

        $secret = 'whsec_'.Str::random(64);
        $endpoint = new IntegrationWebhookEndpoint([
            'user_id' => $request->user()->id,
            'name' => trim($data['name']),
            'url' => $data['url'],
            'events' => array_values(array_unique($data['events'])),
            'is_active' => true,
        ]);
        $endpoint->secret = $secret;
        $endpoint->save();

        return redirect()->route('ipilot.integrations')
            ->with('integration_message', 'Webhook endpoint created. Copy its signing secret now; it will not be shown again.')
            ->with('new_webhook_secret', $secret);
    }

    public function testWebhook(IntegrationWebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorizeAdmin();
        abort_unless($endpoint->is_active, 422, 'Enable this webhook endpoint before sending a test.');
        $delivery = $endpoint->deliveries()->create([
            'event' => 'test.ping',
            'payload' => [
                'message' => 'X-Link Billing webhook test',
                'sent_at' => now()->toIso8601String(),
                'endpoint_id' => $endpoint->id,
            ],
            'status' => 'queued',
        ]);

        DeliverIntegrationWebhook::dispatch($delivery->id)->afterCommit();

        return back()->with('integration_message', 'Test delivery queued. Check delivery history for its result.');
    }

    public function toggleWebhook(IntegrationWebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorizeAdmin();
        $endpoint->update(['is_active' => ! $endpoint->is_active]);
        return back()->with('integration_message', 'Webhook endpoint status updated.');
    }

    public function deleteWebhook(IntegrationWebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorizeAdmin();
        $endpoint->delete();
        return back()->with('integration_message', 'Webhook endpoint and delivery history removed.');
    }

    public function documentation()
    {
        $this->authorizeAdmin();
        return view('xlink.api-v2-docs', [
            'apiScopes' => self::API_SCOPES,
            'webhookEvents' => self::WEBHOOK_EVENTS,
        ]);
    }
}
