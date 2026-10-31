<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use App\Models\User;
use App\Http\Middleware\RestrictToProfileIfNoPermissions;
use App\Services\AiEngineerDiagnosticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AiEngineerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RestrictToProfileIfNoPermissions::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_ai_engineer_map_onu_endpoint_calls_the_guarded_mapping_service(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'AI-MAP-HTTP-TEST',
            'customer_name' => 'AI Mapping Test Customer',
            'status' => 'active',
        ]);
        $service = Mockery::mock(AiEngineerDiagnosticService::class);
        $service->shouldReceive('mapExactCandidate')->once()
            ->with(77, $customer->id, auth()->id())
            ->andReturn(['ok' => true, 'message' => 'Exact mapping saved.']);
        $this->app->instance(AiEngineerDiagnosticService::class, $service);

        $this->from('/ai-engineer')
            ->post('/ai-engineer/map-onu', ['mapping_id' => 77, 'customer_id' => $customer->id])
            ->assertRedirect('/ai-engineer')
            ->assertSessionHas('ai_mapping_message', 'Exact mapping saved.');
    }

    public function test_customer_search_endpoint_returns_selectable_customer_results_as_json(): void
    {
        $service = Mockery::mock(AiEngineerDiagnosticService::class);
        $service->shouldReceive('searchCustomers')->once()->with('BT0001')->andReturn([
            ['id' => 7, 'customer_unique_id' => 'BT0001', 'customer_name' => 'Test Customer', 'mobile' => '01700000000', 'status' => 'active', 'ppp_username' => 'bt0001'],
        ]);
        $this->app->instance(AiEngineerDiagnosticService::class, $service);

        $response = $this->getJson('/ai-engineer/search?q=BT0001');

        $response->assertOk()->assertJsonPath('0.customer_unique_id', 'BT0001')
            ->assertJsonPath('0.customer_name', 'Test Customer')
            ->assertJsonPath('0.ppp_username', 'bt0001');
    }

    public function test_diagnose_endpoint_returns_read_only_customer_diagnosis(): void
    {
        $diagnosis = [
            'ok' => true,
            'customer' => ['id' => 'BT0001', 'name' => 'Test Customer'],
            'severity' => 'warning',
            'service_path' => ['pppoe' => ['live_session' => ['state' => 'offline']]],
            'read_only' => true,
        ];
        $service = Mockery::mock(AiEngineerDiagnosticService::class);
        $service->shouldReceive('diagnoseCustomer')->once()->with('BT0001')->andReturn($diagnosis);
        $this->app->instance(AiEngineerDiagnosticService::class, $service);

        $this->postJson('/ai-engineer/diagnose', ['customer_id' => 'BT0001'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('severity', 'warning')
            ->assertJsonPath('service_path.pppoe.live_session.state', 'offline')
            ->assertJsonPath('read_only', true);
    }

    public function test_diagnose_endpoint_rejects_missing_customer_id(): void
    {
        $this->postJson('/ai-engineer/diagnose', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_ai_engineer_page_renders_for_authenticated_user(): void
    {
        $service = Mockery::mock(AiEngineerDiagnosticService::class);
        $service->shouldReceive('overview')->once()->andReturn([
            'customers' => ['total' => 0, 'active' => 0, 'pending' => 0, 'disabled' => 0],
            'routers' => ['total' => 0, 'connected' => 0, 'disconnected' => 0],
            'olt_onu' => ['mapped' => 0], 'support' => ['tickets' => 0],
            'openai_configured' => false, 'read_only' => true, 'generated_at' => now()->toIso8601String(),
        ]);
        $service->shouldReceive('dailySummary')->once()->andReturn(['generated_at'=>now()->toIso8601String(),'trend'=>[]]);
        $service->shouldReceive('unmappedOnus')->once()->with(12)->andReturn([]);
        $service->shouldReceive('upstreamCorrelation')->once()->with(100)->andReturn(['groups'=>[],'read_only'=>true]);
        $service->shouldReceive('matchingCandidates')->once()->with(100)->andReturn(['matches'=>[],'read_only'=>true]);
        $service->shouldReceive('billingAudit')->once()->with(25)->andReturn([
            'available'=>true,'read_only'=>true,'month'=>now()->format('F Y'),
            'issues_total'=>0,'summary'=>[],'findings'=>[],'findings_total'=>0,
            'note'=>'Read-only test audit.',
        ]);
        $service->shouldReceive('onuMappingAudit')->once()->with(30)->andReturn([
            'available'=>true,'read_only'=>true,'summary'=>[],'findings'=>[],'findings_total'=>0,
            'note'=>'Read-only test ONU audit.',
        ]);
        $this->app->instance(AiEngineerDiagnosticService::class, $service);

        $this->get('/ai-engineer')->assertOk()->assertSee('AI Engineer');
    }
}
