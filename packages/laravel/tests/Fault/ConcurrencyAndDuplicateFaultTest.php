<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Fault;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceAccepted;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceRejected;
use LuroWeb\KsefFoundry\Laravel\Jobs\FetchInvoicesJob;
use LuroWeb\KsefFoundry\Laravel\Jobs\SubmitInvoiceJob;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\KsefLikeKeys;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;

/** fault-injection: concurrent workers (real processes), 440 duplicates of our own invoice, Retry-After cap. */
final class ConcurrencyAndDuplicateFaultTest extends TestCase
{
    private const SESSION = '20260101-SO-ABCDEF0123-ABCDEF0123-AB';
    private const INVOICE = '20260101-EE-ABCDEF0123-ABCDEF0123-CD';
    private const KSEF = '7980332920-20260101-ABCDEF012345-EF';

    private ?FaultServer $keep = null;
    private string $xml = '';
    private string $cacheDir = '';
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->xml = Fa3InvoiceBuilder::sampleXml($this->nip, 'FV/' . bin2hex(random_bytes(4)));
        Event::listen(InvoiceAccepted::class, fn (InvoiceAccepted $e) => $this->events[] = $e);
        Event::listen(InvoiceRejected::class, fn (InvoiceRejected $e) => $this->events[] = $e);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*/*/*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function hash(): string
    {
        return base64_encode(hash('sha256', $this->xml, true));
    }

    /** @param array<string, list<array<string, mixed>>> $extra */
    private function server(array $extra): FaultServer
    {
        $keys = new KsefLikeKeys();
        $base = [
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]],
            'POST /v2/sessions/online' => [['status' => 201, 'body' => json_encode(['referenceNumber' => self::SESSION, 'validUntil' => gmdate('c', time() + 3600)])]],
            'POST /v2/sessions/online/' . self::SESSION . '/close' => [['status' => 204, 'body' => '']],
        ];
        $this->keep = new FaultServer($extra + $base);
        $this->useServer($this->keep);
        $this->seedTokens();

        return $this->keep;
    }

    /** @return array<string, mixed> */
    private function statusReply(int $code, array $ext = []): array
    {
        return ['status' => 200, 'body' => json_encode([
            'ordinalNumber' => 1, 'referenceNumber' => self::INVOICE, 'invoiceHash' => $this->hash(), 'invoicingDate' => '2026-01-01T10:00:00+00:00',
            'ksefNumber' => $code === 200 ? self::KSEF : null, 'status' => ['code' => $code, 'description' => 'x', 'extensions' => $ext],
        ])];
    }

    public function testTwoRealWorkerProcessesSendAtMostOnce(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ksef-conc-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $this->cacheDir]);
        app('cache')->forgetDriver('file');
        app('cache')->setDefaultDriver('file');

        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 202, 'delay' => 3, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices' => [['status' => 200, 'body' => '{"invoices":[]}']],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->statusReply(200)],
        ]);
        $cfg = $this->cacheDir . '/cfg.json';
        file_put_contents($cfg, json_encode([
            'appKey' => config('app.key'), 'cachePath' => $this->cacheDir, 'nip' => $this->nip, 'token' => self::SECRET_TOKEN,
            'baseUrl' => $srv->baseUrl() . '/v2', 'xml' => $this->xml,
        ], JSON_THROW_ON_ERROR));

        $procs = [];
        foreach ([1, 2] as $n) {
            $pipes = [];
            $p = proc_open(
                [PHP_BINARY, __DIR__ . '/submit_process.php', $cfg],
                [0 => ['pipe', 'r'], 1 => ['file', $this->cacheDir . "/out$n", 'w'], 2 => ['file', $this->cacheDir . "/err$n", 'w']],
                $pipes,
            );
            self::assertIsResource($p);
            $procs[$n] = $p;
            usleep(300_000);
        }
        $deadline = microtime(true) + 90;
        foreach ($procs as $n => $p) {
            while (proc_get_status($p)['running']) {
                self::assertLessThan($deadline, microtime(true), "worker $n hung");
                usleep(100_000);
            }
            proc_close($p);
            self::assertStringContainsString('"ok":true', (string) file_get_contents($this->cacheDir . "/out$n"), "worker $n: " . (string) file_get_contents($this->cacheDir . "/err$n"));
        }

        self::assertSame(1, $srv->count('POST', '/v2/sessions/online'), 'only one worker opened a session');
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'), 'at most ONE send');
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function testDuplicateOfOurOwnInvoiceIsAcceptedWithTheExistingKsefNumberNotRejected(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->statusReply(440, ['originalKsefNumber' => self::KSEF, 'originalSessionReferenceNumber' => '20260101-SO-0000000000-0000000000-00'])],
            'GET /v2/invoices/ksef/' . self::KSEF => [['status' => 200, 'headers' => ['Content-Type' => 'application/xml'], 'body' => $this->xml]],
        ]);
        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();

        self::assertSame(1, $srv->count('GET', '/v2/invoices/ksef/' . self::KSEF), 'proof: stored bytes compared with ours');
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertCount(1, $this->events);
        $e = $this->events[0];
        self::assertInstanceOf(InvoiceAccepted::class, $e);
        self::assertSame(self::KSEF, $e->ksefNumber);
        self::assertSame('20260101-SO-0000000000-0000000000-00', $e->sessionReferenceNumber);
    }

    public function testDuplicateOfSomeoneElsesInvoiceStaysRejected(): void
    {
        $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->statusReply(440, ['originalKsefNumber' => self::KSEF])],
            'GET /v2/invoices/ksef/' . self::KSEF => [['status' => 200, 'headers' => ['Content-Type' => 'application/xml'], 'body' => $this->xml . '<!-- different bytes -->']],
        ]);
        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();

        self::assertSame(1, DB::table('failed_jobs')->count());
        self::assertCount(1, $this->events);
        self::assertInstanceOf(InvoiceRejected::class, $this->events[0]);
    }

    public function testRetryAfterIsCappedWhenReleasing(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 429, 'headers' => ['Retry-After' => '86400'], 'body' => '{}']],
            'POST /v2/invoices/query/metadata' => [['status' => 429, 'headers' => ['Retry-After' => '86400'], 'body' => '{}']],
        ]);
        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();
        $row = DB::table('jobs')->first();
        self::assertNotNull($row);
        self::assertEqualsWithDelta(time() + 300, (int) $row->available_at, 5, 'default cap 300 s');
        DB::table('jobs')->delete();

        config(['ksef.jobs.max_release_delay' => 45]);
        FetchInvoicesJob::dispatch('default', 'Subject2', gmdate('c', time() - 86400));
        $this->work();
        $row = DB::table('jobs')->first();
        self::assertNotNull($row);
        self::assertEqualsWithDelta(time() + 45, (int) $row->available_at, 5, 'configurable cap');
        self::assertGreaterThanOrEqual(1, $srv->count('POST', '/v2/invoices/query/metadata'));
    }

    public function testLockHeldByAnotherWorkerMeansNoSendAndARelease(): void
    {
        $srv = $this->server([]);
        $job = new SubmitInvoiceJob($this->xml);
        $lock = app('cache')->store()->lock('ksef:submit-run:' . $job->uniqueId(), 60);
        self::assertTrue($lock->get());
        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();
        $lock->release();

        self::assertSame(0, $srv->count('POST', '/v2/sessions/online'));
        $row = DB::table('jobs')->first();
        self::assertNotNull($row);
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertEqualsWithDelta(time() + 15, (int) $row->available_at, 5);
    }
}
