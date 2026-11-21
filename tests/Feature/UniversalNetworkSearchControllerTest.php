<?php

namespace Tests\Feature;

use App\Http\Controllers\UniversalNetworkSearchController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class UniversalNetworkSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_universal_search_page_renders_without_triggering_live_router_queries(): void
    {
        $this->actingAs(User::factory()->create());
        $view = app(UniversalNetworkSearchController::class)->index(
            Request::create('/network-inventory/universal-search')
        );

        $html = $view->render();

        $this->assertSame('xlink.universal-search', $view->name());
        $this->assertStringContainsString('Universal Customer / Network Search', $html);
        $this->assertStringContainsString('Search value', $html);
        $this->assertStringContainsString('Search runs only after you submit a query', $html);
    }
}
