<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Fault;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Laravel\Contracts\InvoiceSink;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceAccepted;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceRejected;
use LuroWeb\KsefFoundry\Laravel\Jobs\FetchInvoicesJob;
use LuroWeb\KsefFoundry\Laravel\Jobs\PollInvoiceStatusJob;
use LuroWeb\KsefFoundry\Laravel\Jobs\SubmitInvoiceJob;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\KsefLikeKeys;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;
use LuroWeb\KsefFoundry\Query\InvoiceMetadata;

/**
 * fault-injection: the queue jobs against a REAL local HTTP server that misbehaves (reset, 429, 440).
 * Real container, real database queue (sqlite), real worker, real events. Not proof of KSeF API compatibility.
 */
final class JobsFaultTest extends TestCase
{
    private const SESSION = '20260101-SO-ABCDEF0123-ABCDEF0123-AB';
    private const INVOICE = '20260101-EE-ABCDEF0123-ABCDEF0123-CD';
    private const KSEF = '7980332920-20260101-ABCDEF012345-EF';

    private KsefLikeKeys $keys;
    private string $xml = '';
    private ?FaultServer $keep = null;
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = new KsefLikeKeys();
        $this->xml = Fa3InvoiceBuilder::sampleXml($this->nip, 'FV/' . bin2hex(random_bytes(4)));
        Event::listen(InvoiceAccepted::class, fn (InvoiceAccepted $e) => $this->events[] = $e);
        Event::listen(InvoiceRejected::class, fn (InvoiceRejected $e) => $this->events[] = $e);
    }

    private function hash(): string
    {
        return base64_encode(hash('sha256', $this->xml, true));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $extra
     */
    private function server(array $extra): FaultServer
    {
        $base = [
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $this->keys->listJson()]],
            'POST /v2/sessions/online' => [['status' => 201, 'body' => json_encode(['referenceNumber' => self::SESSION, 'validUntil' => gmdate('c', time() + 3600)])]],
            'POST /v2/sessions/online/' . self::SESSION . '/close' => [['status' => 204, 'body' => '']],
        ];
        $srv = $this->keep = new FaultServer($extra + $base);
        $this->useServer($srv);
        $this->seedTokens();

        return $srv;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function invoiceStatus(int $code, string $desc = 'x', array $extra = []): array
    {
        return ['status' => 200, 'body' => json_encode($this->statusBody($code, $desc, $extra))];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function statusBody(int $code, string $desc = 'x', array $extra = []): array
    {
        return [
            'ordinalNumber' => 1, 'referenceNumber' => self::INVOICE, 'invoiceHash' => $this->hash(), 'invoicingDate' => '2026-01-01T10:00:00+00:00',
            'ksefNumber' => $code === 200 ? self::KSEF : null, 'status' => ['code' => $code, 'description' => $desc] + $extra,
        ];
    }

    private function path(string $suffix = ''): string
    {
        return '/v2/sessions/online/' . self::SESSION . $suffix;
    }

    private function statusPath(): string
    {
        return 'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function listing(bool ...$present): array
    {
        $responses = [];
        foreach ($present as $p) {
            $responses[] = ['status' => 200, 'body' => json_encode(['invoices' => $p ? [$this->statusBody(100)] : []])];
        }

        return ['GET /v2/sessions/' . self::SESSION . '/invoices' => $responses];
    }

    public function testAcceptedPathSendsOnceAndDispatchesEvent(): void
    {
        $srv = $this->server([
            'POST ' . $this->path('/invoices') => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            $this->statusPath() => [$this->invoiceStatus(100), $this->invoiceStatus(200, 'ok')],
        ]);
        SubmitInvoiceJob::dispatch($this->xml);
        self::assertSame(0, $this->work());

        self::assertSame(1, $srv->count('POST', $this->path('/invoices')));
        self::assertSame(1, $srv->count('POST', $this->path('/close')));
        self::assertCount(1, $this->events);
        $e = $this->events[0];
        self::assertInstanceOf(InvoiceAccepted::class, $e);
        self::assertSame(self::KSEF, $e->ksefNumber);
        self::assertSame($this->hash(), $e->invoiceHash);
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function testAmbiguousSendReconcilesFindsInvoiceAndNeverResends(): void
    {
        $srv = $this->server([
            'POST ' . $this->path('/invoices') => [['action' => 'reset'], ['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
        ] + $this->listing(true) + [$this->statusPath() => [$this->invoiceStatus(200)]]);

        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();

        self::assertSame(1, $srv->count('POST', $this->path('/invoices')), 'reconcile found the invoice: NO second send');
        self::assertSame(1, $srv->count('GET', '/v2/sessions/' . self::SESSION . '/invoices'));
        self::assertCount(1, $this->events);
        self::assertInstanceOf(InvoiceAccepted::class, $this->events[0]);
    }

    public function testAmbiguousSendAbsentIsReleasedThenReconciledAgainBeforeAnyResend(): void
    {
        // attempt 1: send resets, reconcile empty -> released. attempt 2: reconcile now finds it -> still ONE send.
        $srv = $this->server([
            'POST ' . $this->path('/invoices') => [['action' => 'reset'], ['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
        ] + $this->listing(false, true) + [$this->statusPath() => [$this->invoiceStatus(200)]]);

        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();
        self::assertSame([], $this->events);
        self::assertSame(1, DB::table('jobs')->count(), 'released for a later attempt');
        $row = DB::table('jobs')->first();
        self::assertSame(1, (int) ($row->attempts ?? 0));
        self::assertGreaterThan(time() + 20, (int) ($row->available_at ?? 0), 'backoff applied');
        self::assertSame(1, $srv->count('POST', $this->path('/invoices')));

        $this->makeJobsAvailableNow();
        $this->work();
        self::assertSame(1, $srv->count('POST', $this->path('/invoices')), 'second attempt reconciled first: still no resend');
        self::assertSame(2, $srv->count('GET', '/v2/sessions/' . self::SESSION . '/invoices'));
        self::assertCount(1, $this->events);
        self::assertInstanceOf(InvoiceAccepted::class, $this->events[0]);
    }

    public function testResendHappensOnlyAfterReconcileProvedAbsence(): void
    {
        $srv = $this->server([
            'POST ' . $this->path('/invoices') => [['action' => 'reset'], ['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
        ] + $this->listing(false) + [$this->statusPath() => [$this->invoiceStatus(200)]]);

        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();
        self::assertSame(1, $srv->count('POST', $this->path('/invoices')));
        $this->makeJobsAvailableNow();
        $this->work();

        self::assertSame(2, $srv->count('POST', $this->path('/invoices')), 'resent after a reconcile that found nothing');
        self::assertSame(2, $srv->count('GET', '/v2/sessions/' . self::SESSION . '/invoices'), 'reconcile preceded the resend');
        self::assertCount(1, $this->events);
    }

    public function testRateLimitReleasesWithRetryAfterAndDoesNotFail(): void
    {
        $srv = $this->server([
            'POST ' . $this->path('/invoices') => [['status' => 429, 'headers' => ['Retry-After' => '120'], 'body' => '{"status":{"code":429}}']],
        ]);
        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();

        self::assertSame(0, DB::table('failed_jobs')->count());
        $row = DB::table('jobs')->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->attempts);
        self::assertEqualsWithDelta(time() + 120, (int) $row->available_at, 5);
        self::assertSame([], $this->events);
        self::assertSame(1, $srv->count('POST', $this->path('/invoices')));
    }

    public function testRejectedInvoiceFailsWithoutSecretsAnywhere(): void
    {
        $this->server([
            'POST ' . $this->path('/invoices') => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            $this->statusPath() => [$this->invoiceStatus(440, 'Duplikat faktury', ['details' => ['duplicate']])],
        ]);
        SubmitInvoiceJob::dispatch($this->xml);
        $this->work();

        self::assertSame(0, DB::table('jobs')->count(), 'no retry of a rejected invoice');
        $failed = DB::table('failed_jobs')->first();
        self::assertNotNull($failed);
        self::assertStringContainsString('status 440', (string) $failed->exception);
        self::assertCount(1, $this->events);
        $e = $this->events[0];
        self::assertInstanceOf(InvoiceRejected::class, $e);
        self::assertSame(440, $e->statusCode);

        $log = $this->logContents();
        self::assertStringContainsString('ksef.job.submit_failed', $log);
        foreach ([self::SECRET_TOKEN, self::ACCESS, self::REFRESH, 'SIGNATURE-VALUE', 'Bearer eyJ'] as $secret) {
            self::assertStringNotContainsString($secret, $log, 'log');
            self::assertStringNotContainsString($secret, (string) $failed->exception, 'failed_jobs.exception');
            self::assertStringNotContainsString($secret, (string) $failed->payload, 'failed_jobs.payload');
        }
        self::assertStringNotContainsString('<Faktura', $log, 'invoice content never logged');
    }

    public function testSameInvoiceIsNotQueuedTwiceWhileOneIsPending(): void
    {
        $this->server([]);
        SubmitInvoiceJob::dispatch($this->xml);
        SubmitInvoiceJob::dispatch($this->xml);
        self::assertSame(1, DB::table('jobs')->count(), 'ShouldBeUnique by invoice hash');
        SubmitInvoiceJob::dispatch($this->xml, 'other');
        self::assertSame(2, DB::table('jobs')->count(), 'another connection is another unique key');
    }

    public function testPollJobReleasesUntilTerminalThenAccepts(): void
    {
        $srv = $this->server([$this->statusPath() => [$this->invoiceStatus(150, 'processing'), $this->invoiceStatus(200)]]);
        PollInvoiceStatusJob::dispatch('default', self::SESSION, self::INVOICE, $this->hash());
        $this->work();
        self::assertSame([], $this->events);
        $row = DB::table('jobs')->first();
        self::assertNotNull($row);
        self::assertGreaterThan(time(), (int) $row->available_at);

        $this->makeJobsAvailableNow();
        $this->work();
        self::assertSame(2, $srv->count('GET', '/v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE));
        self::assertCount(1, $this->events);
        self::assertInstanceOf(InvoiceAccepted::class, $this->events[0]);
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function testPollJobFailsOnRejection(): void
    {
        $this->server([$this->statusPath() => [$this->invoiceStatus(445, 'Weryfikacja nieudana')]]);
        PollInvoiceStatusJob::dispatch('default', self::SESSION, self::INVOICE, $this->hash());
        $this->work();
        self::assertSame(1, DB::table('failed_jobs')->count());
        self::assertInstanceOf(InvoiceRejected::class, $this->events[0]);
    }

    public function testFetchJobDeliversToSinkAndStoresCursor(): void
    {
        $sink = new class implements InvoiceSink {
            /** @var list<string> */
            public array $accepted = [];
            public ?\DateTimeImmutable $cursor = null;

            public function cursor(string $connection): ?\DateTimeImmutable
            {
                return $this->cursor;
            }

            public function accept(string $connection, InvoiceMetadata $invoice): void
            {
                $this->accepted[] = $invoice->ksefNumber;
            }

            public function saveCursor(string $connection, \DateTimeImmutable $cursor): void
            {
                $this->cursor = $cursor;
            }
        };
        $this->app?->instance(InvoiceSink::class, $sink);
        $stored = gmdate('Y-m-d\TH:i:sP', time() - 86400);
        $meta = [
            'ksefNumber' => self::KSEF, 'invoiceNumber' => 'FV/1', 'issueDate' => '2026-01-01',
            'invoicingDate' => '2026-01-01T10:00:00+00:00', 'acquisitionDate' => '2026-01-01T10:00:01+00:00',
            'permanentStorageDate' => $stored,
            'seller' => ['nip' => '7980332920', 'name' => 'S'], 'buyer' => ['identifier' => ['type' => 'None'], 'name' => 'B'],
            'netAmount' => 100.0, 'grossAmount' => 123.0, 'vatAmount' => 23.0, 'currency' => 'PLN', 'invoicingMode' => 'Online',
            'invoiceType' => 'Vat', 'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
            'isSelfInvoicing' => false, 'hasAttachment' => false, 'invoiceHash' => $this->hash(),
        ];
        $srv = $this->server(['POST /v2/invoices/query/metadata' => [['status' => 200, 'body' => json_encode(['invoices' => [$meta], 'hasMore' => false, 'isTruncated' => false])]]]);

        FetchInvoicesJob::dispatch('default', 'Subject2', gmdate('c', time() - 10 * 86400));
        $this->work();
        self::assertSame([self::KSEF], $sink->accepted);
        self::assertSame($stored, $sink->cursor?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'));
        $body = json_decode((string) ($srv->requests()[array_key_last($srv->requests())]['body'] ?? ''), true);
        self::assertIsArray($body);
        self::assertSame('PermanentStorage', $body['dateRange']['dateType']);
        self::assertTrue($body['dateRange']['restrictToPermanentStorageHwmDate']);
        self::assertSame('Subject2', $body['subjectType']);

        // next run (no explicit from) resumes at the stored cursor
        FetchInvoicesJob::dispatch('default', 'Subject2');
        $this->makeJobsAvailableNow();
        $this->work();
        $last = $srv->requests()[array_key_last($srv->requests())];
        $body = json_decode((string) $last['body'], true);
        self::assertIsArray($body);
        self::assertSame($stored, $body['dateRange']['from']);
    }
}
