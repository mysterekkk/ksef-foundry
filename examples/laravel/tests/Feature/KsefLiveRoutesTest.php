<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceAccepted;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Service\TokenPermission;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;
use Tests\TestCase;

/** LIVE TEST (opt-in KSEF_LIVE_TEST=1): the example routes through the HTTP kernel against api-test.ksef.mf.gov.pl. */
final class KsefLiveRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'example-test-token-0123456789abcdef';

    public function testRoutesAgainstKsefTest(): void
    {
        if (getenv('KSEF_LIVE_TEST') !== '1') {
            self::markTestSkipped('SKIPPED (live TEST disabled; run composer test:live)');
        }
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('G');
        if ($hour >= 16 && $hour < 18) {
            self::markTestSkipped('SKIPPED (KSeF TEST maintenance window 16:00-18:00 Europe/Warsaw)');
        }
        self::assertSame('test', config('ksef.environment'));

        $nip = SyntheticIdentity::nip();
        $pesel = SyntheticIdentity::pesel();
        $cert = (new TestCertificateFactory())->personWithPesel($pesel, 'Jan', 'Syntetyczny');
        $certClient = KsefClient::create(new CertificateCredentials($cert->certificatePem, $cert->privateKeyPem, ContextIdentifier::nip($nip)), Environment::test());
        $certClient->testData()->createPerson($nip, $pesel, 'ksef-foundry laravel example live test (synthetic)');
        $deadline = time() + 90;
        while (true) {
            try {
                $certClient->auth()->getAccessToken($certClient->credentials());
                break;
            } catch (ApiException $e) {
                if ($e->authStatusCode() !== 415 || time() >= $deadline) {
                    throw $e;
                }
                sleep(3);
            }
        }
        $created = $certClient->tokens()->create([TokenPermission::InvoiceRead, TokenPermission::InvoiceWrite], 'example live test');
        $certClient->tokens()->awaitActive($created->referenceNumber);

        config(['ksef.connections.default' => ['auth' => 'token', 'context_type' => 'nip', 'context_value' => $nip, 'token' => $created->token->reveal()]]);
        app(KsefManager::class)->purge();
        $this->app->forgetInstance(KsefClient::class);

        $this->withToken(self::TOKEN);
        try {
            $this->getJson('/ksef/limits')->assertOk()->assertJsonPath('environment', 'TEST')->assertJsonStructure(['onlineSession' => ['maxInvoices']]);
            fwrite(STDERR, "[live] GET /ksef/limits 200\n");
            $this->getJson('/ksef/invoices?subject=Subject1&per_page=10')->assertOk()->assertJsonStructure(['page', 'hasMore', 'data']);
            fwrite(STDERR, "[live] GET /ksef/invoices 200\n");

            $accepted = [];
            Event::listen(InvoiceAccepted::class, function (InvoiceAccepted $e) use (&$accepted): void {
                $accepted[] = $e;
            });
            $xml = Fa3InvoiceBuilder::sampleXml($nip, 'FV/EXAMPLE/' . bin2hex(random_bytes(4)));
            $this->call('POST', '/ksef/invoices', [], [], [], ['CONTENT_TYPE' => 'application/xml', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN], $xml)->assertStatus(202);
            $deadline = time() + 120;
            while (DB::table('jobs')->count() > 0 && time() < $deadline) {
                DB::table('jobs')->update(['available_at' => time() - 1, 'reserved_at' => null]);
                Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--quiet' => true]);
                if (DB::table('jobs')->count() > 0) {
                    sleep(3);
                }
            }
            self::assertSame(0, DB::table('failed_jobs')->count());
            self::assertCount(1, $accepted);
            $e = $accepted[0];
            fwrite(STDERR, '[live] POST /ksef/invoices -> job accepted ' . $e->ksefNumber . "\n");

            $this->get('/ksef/invoices/' . $e->ksefNumber)->assertOk()->assertHeader('X-Hash-Verified', '1');
            $this->getJson('/ksef/status/' . $e->sessionReferenceNumber . '/' . $e->invoiceReferenceNumber)
                ->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('statusCode', 200);
            $this->get('/ksef/upo/' . $e->sessionReferenceNumber . '/' . $e->invoiceReferenceNumber)->assertOk();
            fwrite(STDERR, "[live] GET show/status/upo 200\n");
        } finally {
            try {
                $certClient->tokens()->revoke($created->referenceNumber);
                $certClient->testData()->removePerson($nip);
            } catch (\Throwable) {
            }
        }
    }
}
