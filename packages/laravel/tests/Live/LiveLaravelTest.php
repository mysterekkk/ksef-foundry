<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Live;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Contracts\InvoiceSink;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceAccepted;
use LuroWeb\KsefFoundry\Laravel\Jobs\FetchInvoicesJob;
use LuroWeb\KsefFoundry\Laravel\Jobs\SubmitInvoiceJob;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;
use LuroWeb\KsefFoundry\Query\InvoiceMetadata;
use LuroWeb\KsefFoundry\Service\TokenPermission;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;

/**
 * LIVE TEST (opt-in: KSEF_LIVE_TEST=1). Talks to https://api-test.ksef.mf.gov.pl/v2 ONLY. Synthetic taxpayer, no secrets needed.
 * When disabled it is reported as SKIPPED, never as passed.
 */
final class LiveLaravelTest extends TestCase
{
    public function testLaravelIntegrationAgainstKsefTest(): void
    {
        if (getenv('KSEF_LIVE_TEST') !== '1') {
            self::markTestSkipped('SKIPPED (live TEST disabled; run composer test:live)');
        }
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('G');
        if ($hour >= 16 && $hour < 18) {
            self::markTestSkipped('SKIPPED (KSeF TEST maintenance window 16:00-18:00 Europe/Warsaw)');
        }
        self::assertSame('test', config('ksef.environment'), 'live tests run against TEST only');
        $env = Environment::test();
        self::assertSame('https://api-test.ksef.mf.gov.pl/v2', $env->baseUrl);

        // 1. synthetic taxpayer + certificate login with the plain SDK (provisioning)
        $nip = SyntheticIdentity::nip();
        $pesel = SyntheticIdentity::pesel();
        $cert = (new TestCertificateFactory())->personWithPesel($pesel, 'Jan', 'Syntetyczny');
        $context = ContextIdentifier::nip($nip);
        $certClient = KsefClient::create(new CertificateCredentials($cert->certificatePem, $cert->privateKeyPem, $context), $env);
        $certClient->testData()->createPerson($nip, $pesel, 'ksef-foundry laravel live test (synthetic)');
        $this->loginEventually($certClient);
        self::log('synthetic taxpayer created, XAdES login OK');

        $created = $certClient->tokens()->create([TokenPermission::InvoiceRead, TokenPermission::InvoiceWrite], 'laravel live test');
        $certClient->tokens()->awaitActive($created->referenceNumber);

        // 2. Laravel connections: KSeF token (default) and certificate (named) - both from the Laravel config
        config([
            'ksef.connections.default' => ['auth' => 'token', 'context_type' => 'nip', 'context_value' => $nip, 'token' => $created->token->reveal()],
            'ksef.connections.cert' => [
                'auth' => 'certificate', 'context_type' => 'nip', 'context_value' => $nip,
                'certificate_pem' => $cert->certificatePem, 'private_key_pem' => $cert->privateKeyPem->reveal(),
            ],
            'ksef.cache.store' => 'array',
            'ksef.jobs.await_seconds' => 30,
        ]);
        app(KsefManager::class)->purge();

        try {
            $code = Artisan::call('ksef:check');
            $out = Artisan::output();
            self::assertSame(0, $code, $out);
            self::assertStringContainsString('Environment: TEST', $out);
            self::assertStringNotContainsString($created->token->reveal(), $out);
            $code = Artisan::call('ksef:check', ['connection' => 'cert']);
            $out = Artisan::output();
            self::assertSame(0, $code, $out);
            self::assertStringContainsString('Authentication: OK', $out);
            self::log('ksef:check OK for token and certificate connections');

            // 3. incremental fetch job (a fresh taxpayer has no invoices: proves the query path + cursor handling)
            $sink = new class implements InvoiceSink {
                public int $count = 0;

                public function cursor(string $connection): ?\DateTimeImmutable
                {
                    return null;
                }

                public function accept(string $connection, InvoiceMetadata $invoice): void
                {
                    ++$this->count;
                }

                public function saveCursor(string $connection, \DateTimeImmutable $cursor): void
                {
                }
            };
            $this->app?->instance(InvoiceSink::class, $sink);
            FetchInvoicesJob::dispatch('default', 'Subject1');
            self::assertSame(0, $this->work());
            self::assertSame(0, DB::table('failed_jobs')->count());
            self::assertSame(0, DB::table('jobs')->count());
            self::log('FetchInvoicesJob OK (' . $sink->count . ' invoices)');

            // 4. full SubmitInvoiceJob to acceptance
            $events = [];
            Event::listen(InvoiceAccepted::class, static function (InvoiceAccepted $e) use (&$events): void {
                $events[] = $e;
            });
            $xml = Fa3InvoiceBuilder::sampleXml($nip, 'FV/LARAVEL/' . bin2hex(random_bytes(5)));
            SubmitInvoiceJob::dispatch($xml);
            $deadline = time() + 120;
            while (DB::table('jobs')->count() > 0 && time() < $deadline) {
                $this->makeJobsAvailableNow();
                $this->work();
                if (DB::table('jobs')->count() > 0) {
                    sleep(3);
                }
            }
            self::assertSame(0, DB::table('failed_jobs')->count(), 'job failed: ' . (string) json_encode(DB::table('failed_jobs')->pluck('exception')));
            self::assertCount(1, $events, 'InvoiceAccepted event');
            $ksefNumber = $events[0]->ksefNumber;
            self::assertMatchesRegularExpression(\LuroWeb\KsefFoundry\Service\InvoiceService::KSEF_NUMBER_PATTERN, $ksefNumber);
            self::log('SubmitInvoiceJob accepted, KSeF number ' . $ksefNumber);

            $doc = app(KsefManager::class)->connection()->invoices()->download($ksefNumber);
            self::assertTrue($doc->hashVerified);
            self::assertStringContainsString($nip, $doc->xml);
            self::log('download by KSeF number OK, hash verified');
        } finally {
            try {
                $certClient->tokens()->revoke($created->referenceNumber);
                $certClient->testData()->removePerson($nip);
            } catch (\Throwable $e) {
                self::log('cleanup failed: ' . $e::class);
            }
        }
    }

    private function loginEventually(KsefClient $client): void
    {
        $deadline = time() + 90;
        while (true) {
            try {
                $client->auth()->getAccessToken($client->credentials());

                return;
            } catch (AuthenticationException | AuthorizationException $e) {
                if ($e->authStatusCode() !== 415 || time() >= $deadline) {
                    throw $e;
                }
                sleep(3);
            }
        }
    }

    private static function log(string $line): void
    {
        fwrite(STDERR, '[live] ' . $line . "\n");
    }
}
