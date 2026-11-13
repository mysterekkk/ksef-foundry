<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Fault;

use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Query\DateType;
use LuroWeb\KsefFoundry\Query\InvoiceQueryFilters;
use LuroWeb\KsefFoundry\Query\SortOrder;
use LuroWeb\KsefFoundry\Query\SubjectType;
use LuroWeb\KsefFoundry\Service\TokenPermission;
use LuroWeb\KsefFoundry\Service\TokenStatus;
use LuroWeb\KsefFoundry\Tests\Support\FaultClient;
use LuroWeb\KsefFoundry\Tests\Support\FaultServer;
use PHPUnit\Framework\TestCase;

/** fault-injection / protocol-shape tests of the data services against a REAL local server. */
final class ServicesFaultTest extends TestCase
{
    private const KSEF = '7980332920-20260101-ABCDEF012345-EF';

    /** @return array<string, mixed> */
    private static function meta(string $n, string $storage, string $issue = '2026-01-02'): array
    {
        return [
            'ksefNumber' => $n, 'invoiceNumber' => 'FV/' . $n, 'issueDate' => $issue, 'invoicingDate' => $storage, 'acquisitionDate' => $storage,
            'permanentStorageDate' => $storage, 'seller' => ['nip' => '7980332920'], 'buyer' => ['identifier' => ['type' => 'None']],
            'netAmount' => 100.1, 'grossAmount' => 123.123, 'vatAmount' => 23, 'currency' => 'PLN', 'invoicingMode' => 'Online', 'invoiceType' => 'Vat',
            'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'], 'isSelfInvoicing' => false, 'hasAttachment' => false,
            'invoiceHash' => base64_encode(hash('sha256', $n, true)),
        ];
    }

    private function filters(string $from = '2026-01-01T00:00:00+00:00', ?string $to = '2026-01-31T00:00:00+00:00'): InvoiceQueryFilters
    {
        return new InvoiceQueryFilters(SubjectType::Subject1, DateType::PermanentStorage, new \DateTimeImmutable($from), $to === null ? null : new \DateTimeImmutable($to));
    }

    public function testMetadataPaginationFollowsHasMoreAndPageOffset(): void
    {
        $page = static fn (array $inv, bool $more): array => ['status' => 200, 'body' => json_encode(['hasMore' => $more, 'isTruncated' => false, 'invoices' => $inv])];
        $srv = new FaultServer(['POST /v2/invoices/query/metadata' => [
            $page([self::meta('A1', '2026-01-03T00:00:00+00:00'), self::meta('A2', '2026-01-04T00:00:00+00:00')], true),
            $page([self::meta('A3', '2026-01-05T00:00:00+00:00')], false),
        ]]);
        $c = new FaultClient($srv);
        $got = [];
        foreach ($c->client->query()->iterate($this->filters(), 10) as $m) {
            $got[] = $m->ksefNumber;
        }
        self::assertSame(['A1', 'A2', 'A3'], $got);
        $reqs = $srv->requests();
        self::assertSame('sortOrder=Asc&pageOffset=0&pageSize=10', $reqs[0]['query']);
        self::assertSame('sortOrder=Asc&pageOffset=1&pageSize=10', $reqs[1]['query']);
        $body = json_decode((string) $reqs[0]['body'], true);
        self::assertSame('Subject1', $body['subjectType']);
        self::assertSame(['dateType' => 'PermanentStorage', 'from' => '2026-01-01T00:00:00+00:00', 'to' => '2026-01-31T00:00:00+00:00'], $body['dateRange']);
        self::assertTrue($reqs[0]['auth'] !== null);
    }

    public function testAmountsAreExactStringsNotFloats(): void
    {
        $srv = new FaultServer(['POST /v2/invoices/query/metadata' => [['status' => 200, 'body' => json_encode(['hasMore' => false, 'isTruncated' => false, 'invoices' => [self::meta('A1', '2026-01-03T00:00:00+00:00')]])]]]);
        $c = new FaultClient($srv);
        $m = $c->client->query()->queryMetadata($this->filters())->invoices[0];
        self::assertSame('100.10', $m->netAmount);
        self::assertSame('123.12', $m->grossAmount);
        self::assertSame('23.00', $m->vatAmount);
        self::assertSame('1', bcsub($m->grossAmount, '122.12', 0) === '1' ? '1' : '0');
    }

    public function testTruncatedResultNarrowsDateRangeAndDropsBoundaryDuplicates(): void
    {
        $srv = new FaultServer(['POST /v2/invoices/query/metadata' => [
            ['status' => 200, 'body' => json_encode(['hasMore' => true, 'isTruncated' => true, 'invoices' => [self::meta('A1', '2026-01-03T00:00:00+00:00'), self::meta('A2', '2026-01-09T00:00:00+00:00')]])],
            ['status' => 200, 'body' => json_encode(['hasMore' => false, 'isTruncated' => false, 'invoices' => [self::meta('A2', '2026-01-09T00:00:00+00:00'), self::meta('A3', '2026-01-10T00:00:00+00:00')]])],
        ]]);
        $c = new FaultClient($srv);
        $got = array_map(static fn ($m): string => $m->ksefNumber, iterator_to_array($c->client->query()->iterate($this->filters(), 10), false));
        self::assertSame(['A1', 'A2', 'A3'], $got, 'A2 appears in both pages but is yielded once');
        $reqs = $srv->requests();
        $second = json_decode((string) $reqs[1]['body'], true);
        self::assertSame('2026-01-09T00:00:00+00:00', $second['dateRange']['from'], 'from = date of the last record');
        self::assertStringContainsString('pageOffset=0', (string) $reqs[1]['query'], 'offset is reset');
    }

    public function testTruncationWithoutProgressFailsInsteadOfLooping(): void
    {
        $same = ['status' => 200, 'body' => json_encode(['hasMore' => true, 'isTruncated' => true, 'invoices' => [self::meta('A1', '2026-01-03T00:00:00+00:00')]])];
        $srv = new FaultServer(['POST /v2/invoices/query/metadata' => [$same]]);
        $c = new FaultClient($srv);
        $this->expectException(\LuroWeb\KsefFoundry\Exception\KsefException::class);
        iterator_to_array($c->client->query()->iterate($this->filters(), 10), false);
    }

    public function testRangesLongerThan100DaysAreSplitAndSingleQueriesAreRefused(): void
    {
        $srv = new FaultServer(['POST /v2/invoices/query/metadata' => [['status' => 200, 'body' => '{"hasMore":false,"isTruncated":false,"invoices":[]}']]]);
        $c = new FaultClient($srv);
        $from = '2025-01-01T00:00:00+00:00';
        $to = '2025-10-01T00:00:00+00:00'; // 273 days
        iterator_to_array($c->client->query()->iterate($this->filters($from, $to), 10), false);
        $bodies = array_map(static fn (array $r): array => json_decode((string) $r['body'], true)['dateRange'], $srv->requests());
        self::assertGreaterThanOrEqual(3, count($bodies));
        self::assertSame($from, $bodies[0]['from']);
        self::assertSame($to, end($bodies)['to']);
        foreach ($bodies as $i => $b) {
            $days = (strtotime($b['to']) - strtotime($b['from'])) / 86400;
            self::assertLessThanOrEqual(100, $days, "window $i");
            if ($i > 0) {
                self::assertSame($bodies[$i - 1]['to'], $b['from'], 'windows are contiguous');
            }
        }
        $this->expectException(ConfigurationException::class);
        $c->client->query()->queryMetadata($this->filters($from, $to));
    }

    public function testDescendingIterationWalksWindowsNewestFirst(): void
    {
        $srv = new FaultServer(['POST /v2/invoices/query/metadata' => [['status' => 200, 'body' => '{"hasMore":false,"isTruncated":false,"invoices":[]}']]]);
        $c = new FaultClient($srv);
        iterator_to_array($c->client->query()->iterate($this->filters('2025-01-01T00:00:00+00:00', '2025-10-01T00:00:00+00:00'), 10, SortOrder::Desc), false);
        $first = json_decode((string) $srv->requests()[0]['body'], true)['dateRange'];
        self::assertSame('2025-10-01T00:00:00+00:00', $first['to']);
        self::assertStringContainsString('sortOrder=Desc', (string) $srv->requests()[0]['query']);
    }

    public function testDatesAreConvertedToUtc(): void
    {
        $f = new InvoiceQueryFilters(SubjectType::Subject2, DateType::Issue, new \DateTimeImmutable('2026-03-29T03:30:00+02:00'), new \DateTimeImmutable('2026-03-30T00:00:00+02:00'));
        $r = $f->toArray()['dateRange'];
        self::assertSame('2026-03-29T01:30:00+00:00', $r['from']);
        self::assertSame('2026-03-29T22:00:00+00:00', $r['to']);
    }

    public function testSessionInvoicePaginationUsesContinuationHeader(): void
    {
        $inv = static fn (int $n): array => ['ordinalNumber' => $n, 'referenceNumber' => '20260101-EE-ABCDEF0123-ABCDEF0123-C' . $n, 'invoiceHash' => 'h', 'invoicingDate' => '2026-01-01T00:00:00+00:00', 'status' => ['code' => 200, 'description' => 'ok']];
        $srv = new FaultServer(['GET /v2/sessions/S/invoices' => [
            ['status' => 200, 'body' => json_encode(['continuationToken' => 'CT-1', 'invoices' => [$inv(1), $inv(2)]])],
            ['status' => 200, 'body' => json_encode(['continuationToken' => null, 'invoices' => [$inv(3)]])],
        ]]);
        $c = new FaultClient($srv);
        $all = iterator_to_array($c->client->sessions()->iterateSessionInvoices('S', 10), false);
        self::assertCount(3, $all);
        $reqs = $srv->requests();
        self::assertNull($reqs[0]['continuation']);
        self::assertSame('CT-1', $reqs[1]['continuation']);
        self::assertSame('pageSize=10', $reqs[0]['query']);
    }

    public function testTokenServiceRequestShapes(): void
    {
        $srv = new FaultServer([
            'POST /v2/tokens' => [['status' => 202, 'body' => '{"referenceNumber":"20260101-TK-ABCDEF0123-ABCDEF0123-AB","token":"TOKEN-VALUE-XYZ"}']],
            'GET /v2/tokens' => [['status' => 200, 'body' => '{"continuationToken":null,"tokens":[]}']],
            'DELETE /v2/tokens/R1' => [['status' => 204, 'body' => '']],
        ]);
        $c = new FaultClient($srv);
        $t = $c->client->tokens();
        $created = $t->create([TokenPermission::InvoiceRead, TokenPermission::InvoiceWrite], 'my integration');
        self::assertSame('TOKEN-VALUE-XYZ', $created->token->reveal());
        self::assertStringNotContainsString('TOKEN-VALUE-XYZ', print_r($created, true));
        self::assertSame(['permissions' => ['InvoiceRead', 'InvoiceWrite'], 'description' => 'my integration'], json_decode((string) $srv->requests()[0]['body'], true));
        $t->list([TokenStatus::Active, TokenStatus::Pending], 'abc', pageSize: 20);
        self::assertSame('status=Active&status=Pending&description=abc&pageSize=20', $srv->requests()[1]['query']);
        $t->revoke('R1');
        self::assertSame(1, $srv->count('DELETE', '/v2/tokens/R1'));
        foreach ([[[], 'valid description'], [[TokenPermission::InvoiceRead], 'abc']] as [$p, $d]) {
            try {
                $t->create($p, $d);
                self::fail();
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvoiceDownloadVerifiesHashAndRetriesNotYetAvailable(): void
    {
        $xml = '<Faktura>zażółć</Faktura>';
        $hash = base64_encode(hash('sha256', $xml, true));
        $srv = new FaultServer(['GET /v2/invoices/ksef/' . self::KSEF => [
            ['status' => 406, 'body' => ''],
            ['status' => 400, 'body' => json_encode(['errors' => [['code' => 21165, 'description' => 'nie jest jeszcze dostępna']]])],
            ['status' => 200, 'headers' => ['Content-Type' => 'application/xml', 'x-ms-meta-hash' => $hash], 'body' => $xml],
        ]]);
        $c = new FaultClient($srv);
        $doc = $c->client->invoices()->download(self::KSEF);
        self::assertSame($xml, $doc->xml);
        self::assertTrue($doc->hashVerified);
        self::assertSame($hash, $doc->sha256Base64);
        self::assertSame(3, $srv->count('GET', '/v2/invoices/ksef/' . self::KSEF));
        self::assertSame('application/xml', $srv->requests()[0]['accept']);
        ob_start();
        var_dump($doc);
        self::assertStringNotContainsString('zażółć', (string) ob_get_clean(), 'invoice content never leaks through var_dump');
    }

    public function testHashMismatchIsReportedNotHidden(): void
    {
        $srv = new FaultServer(['GET /v2/invoices/ksef/' . self::KSEF => [['status' => 200, 'headers' => ['x-ms-meta-hash' => base64_encode(hash('sha256', 'other', true))], 'body' => '<a/>']]]);
        $c = new FaultClient($srv);
        self::assertFalse($c->client->invoices()->download(self::KSEF)->hashVerified);
    }

    public function testDownloadRejectsMalformedKsefNumberLocally(): void
    {
        $srv = new FaultServer([]);
        $c = new FaultClient($srv);
        $this->expectException(ConfigurationException::class);
        try {
            $c->client->invoices()->download('../../etc/passwd');
        } finally {
            self::assertSame([], $srv->requests());
        }
    }

    public function testUpoDownloadsUseApiEndpointsNotDownloadUrls(): void
    {
        $srv = new FaultServer([
            'GET /v2/sessions/S/invoices/I/upo' => [['status' => 200, 'headers' => ['Content-Type' => 'application/xml'], 'body' => '<Upo/>']],
            'GET /v2/sessions/S/upo/U' => [['status' => 200, 'headers' => ['Content-Type' => 'application/xml'], 'body' => '<Upo2/>']],
        ]);
        $c = new FaultClient($srv);
        self::assertSame('<Upo/>', $c->client->upo()->invoiceUpo('S', 'I')->xml);
        self::assertSame('<Upo2/>', $c->client->upo()->sessionUpo('S', 'U')->xml);
    }

    public function testLimitsAndAuthSessionsDecode(): void
    {
        $srv = new FaultServer([
            'GET /v2/rate-limits' => [['status' => 200, 'body' => '{"invoiceSend":{"perSecond":10,"perMinute":30,"perHour":200},"brandNewGroup":{"perSecond":1,"perMinute":2,"perHour":3}}']],
            'DELETE /v2/auth/sessions/current' => [['status' => 204, 'body' => '']],
        ]);
        $c = new FaultClient($srv);
        $r = $c->client->limits()->rateLimits();
        self::assertSame(['perSecond' => 10, 'perMinute' => 30, 'perHour' => 200], $r->group('invoiceSend'));
        self::assertNotNull($r->group('brandNewGroup'), 'additive unknown groups are tolerated');
        $c->client->authSessions()->revokeCurrent();
        self::assertSame(1, $srv->count('DELETE', '/v2/auth/sessions/current'));
    }
}
