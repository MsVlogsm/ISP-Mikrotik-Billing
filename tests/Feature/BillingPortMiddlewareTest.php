<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureBillingPort;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BillingPortMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'staging';
        config(['app.billing_staging_port' => 8085]);
    }

    public function test_configured_staging_billing_port_accepts_only_https_on_the_billing_host(): void
    {
        $request = Request::create('https://bill.xlinkbd.net:8085/dashboard', 'GET');

        $response = (new EnsureBillingPort())->handle(
            $request,
            fn (Request $request) => response('allowed')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('allowed', $response->getContent());
    }

    public function test_staging_billing_port_rejects_plain_http(): void
    {
        $request = Request::create('http://bill.xlinkbd.net:8085/dashboard', 'GET');

        $this->expectException(HttpException::class);

        (new EnsureBillingPort())->handle(
            $request,
            fn (Request $request) => response('allowed')
        );
    }

    public function test_staging_billing_port_rejects_other_hosts(): void
    {
        $request = Request::create('https://example.com:8085/dashboard', 'GET');

        $this->expectException(HttpException::class);

        (new EnsureBillingPort())->handle(
            $request,
            fn (Request $request) => response('allowed')
        );
    }

    public function test_production_does_not_accept_the_staging_billing_port(): void
    {
        $this->app['env'] = 'production';
        $request = Request::create('https://bill.xlinkbd.net:8085/dashboard', 'GET');

        $this->expectException(HttpException::class);

        (new EnsureBillingPort())->handle(
            $request,
            fn (Request $request) => response('allowed')
        );
    }
}
