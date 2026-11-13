<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use Tests\TestCase;

/** Through the real HTTP kernel, real routing, real container, real database queue (sqlite in memory). No KSeF network access. */
final class KsefRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'example-test-token-0123456789abcdef';

    private function sample(): string
    {
        return Fa3InvoiceBuilder::sampleXml(SyntheticIdentity::nip(), 'FV/EX/' . bin2hex(random_bytes(3)));
    }

    /** @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function postXml(string $uri, string $xml)
    {
        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'application/xml', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN], $xml);
    }

    public function testHomeBoots(): void
    {
        $this->getJson('/')->assertOk()->assertJsonPath('app', 'ksef-foundry laravel example');
    }

    public function testValidateAcceptsAValidFa3AndReportsIssuesForABrokenOne(): void
    {
        $this->postXml('/ksef/validate', $this->sample())->assertOk()->assertJsonPath('valid', true);

        $broken = str_replace('<P_2>', '<P_2X>', $this->sample());
        $this->postXml('/ksef/validate', $broken)->assertStatus(422)->assertJsonPath('valid', false);
    }

    public function testSubmitValidatesThenQueuesTheJob(): void
    {
        $this->postXml('/ksef/invoices', '<nope/>')->assertStatus(422);
        self::assertSame(0, DB::table('jobs')->count());

        $xml = $this->sample();
        $this->postXml('/ksef/invoices', $xml)
            ->assertStatus(202)->assertJsonPath('queued', true)
            ->assertJsonPath('invoiceHash', base64_encode(hash('sha256', $xml, true)));
        self::assertSame(1, DB::table('jobs')->count());
        // the same invoice is not queued twice (ShouldBeUnique)
        $this->postXml('/ksef/invoices', $xml)->assertStatus(202);
        self::assertSame(1, DB::table('jobs')->count());
    }

    public function testMissingCredentialsGiveASafeJsonErrorNotAStackTrace(): void
    {
        config(['ksef.connections.default.token' => null]);
        app(KsefManager::class)->purge();
        $this->app->forgetInstance(KsefClient::class);

        $this->withToken(self::TOKEN)->getJson('/ksef/limits')
            ->assertStatus(500)
            ->assertJsonPath('error.type', 'ConfigurationException')
            ->assertJsonPath('error.message', 'KSeF integration is not configured correctly.');
    }

    public function testEveryKsefRouteRequiresTheBearerToken(): void
    {
        foreach (['GET /ksef/limits', 'GET /ksef/invoices', 'POST /ksef/validate', 'POST /ksef/invoices'] as $route) {
            [$method, $uri] = explode(' ', $route);
            $this->json($method, $uri)->assertStatus(401);
            $this->withToken('wrong-token-wrong-token-wrong')->json($method, $uri)->assertStatus(401);
        }
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function testRoutesFailClosedWhenNoTokenIsConfigured(): void
    {
        config(['example.api_token' => null]);
        $this->withToken(self::TOKEN)->getJson('/ksef/limits')->assertStatus(503);
        config(['example.api_token' => 'short']);
        $this->withToken('short')->getJson('/ksef/limits')->assertStatus(503);
    }

    public function testCsrfProtectionStaysOnForTheWebGroupAndTheKsefApiIsStateless(): void
    {
        // (the framework skips the CSRF check itself while running tests, so the wiring is asserted instead)
        $groups = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups();
        self::assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $groups['web']);
        self::assertNotContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $groups['api']);
    }

    public function testRoutesAreRegistered(): void
    {
        $this->artisan('route:list', ['--path' => 'ksef'])->expectsOutputToContain('ksef/limits')->assertExitCode(0);
    }
}
