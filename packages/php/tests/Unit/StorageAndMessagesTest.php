<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Unit;

use LuroWeb\KsefFoundry\Auth\InMemoryTokenStorage;
use LuroWeb\KsefFoundry\Auth\TokenPair;
use LuroWeb\KsefFoundry\Http\Psr7\Factory;
use LuroWeb\KsefFoundry\Http\Psr7\Response;
use LuroWeb\KsefFoundry\Session\InvoiceStatus;
use LuroWeb\KsefFoundry\Session\SessionStatus;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use PHPUnit\Framework\TestCase;

final class StorageAndMessagesTest extends TestCase
{
    private function pair(int $now): TokenPair
    {
        return new TokenPair(
            new SensitiveString('a'),
            (new \DateTimeImmutable())->setTimestamp($now + 100),
            new SensitiveString('r'),
            (new \DateTimeImmutable())->setTimestamp($now + 1000),
        );
    }

    public function testInMemoryStorageHonoursTtlAndDelete(): void
    {
        $now = 1_000_000;
        $s = new InMemoryTokenStorage(static function () use (&$now): int {
            return $now;
        });
        self::assertNull($s->get('k'));
        $s->put('k', $this->pair($now), 50);
        self::assertNotNull($s->get('k'));
        $now += 49;
        self::assertNotNull($s->get('k'));
        $now += 2;
        self::assertNull($s->get('k'), 'expired');
        $s->put('k', $this->pair($now), 50);
        $s->delete('k');
        self::assertNull($s->get('k'));
    }

    public function testTokenPairValidityWindowsAndStorageRoundTrip(): void
    {
        $now = 2_000_000;
        $p = $this->pair($now);
        self::assertTrue($p->accessValidFor($now, 60));
        self::assertFalse($p->accessValidFor($now + 40, 60));
        self::assertTrue($p->refreshValidFor($now, 60));
        $copy = TokenPair::fromStorageArray($p->toStorageArray());
        self::assertSame('a', $copy->accessToken->reveal());
        self::assertSame($p->refreshValidUntil->getTimestamp(), $copy->refreshValidUntil->getTimestamp());
    }

    public function testPsr7MessagesBehave(): void
    {
        $f = new Factory();
        $req = $f->createRequest('POST', 'https://example.org:8443/v2/a b?x=1')->withHeader('X-A', ['1', '2'])->withAddedHeader('x-a', '3');
        self::assertSame('example.org:8443', $req->getHeaderLine('Host'));
        self::assertSame('1, 2, 3', $req->getHeaderLine('X-A'));
        self::assertTrue($req->hasHeader('x-A'));
        self::assertSame('/v2/a b?x=1', $req->getRequestTarget());
        $req = $req->withBody($f->createStream('payload'));
        self::assertSame('payload', (string) $req->getBody());
        self::assertSame(7, $req->getBody()->getSize());
        self::assertFalse($req->withoutHeader('X-A')->hasHeader('X-A'));
        self::assertTrue($req->hasHeader('X-A'), 'messages are immutable');
        $res = new Response(204, ['Content-Type' => 'text/plain'], 'x');
        self::assertSame(200, $res->withStatus(200)->getStatusCode());
        self::assertSame(204, $res->getStatusCode());
        self::assertSame('https://example.org/p?q#f', (string) $f->createUri('https://EXAMPLE.org:443/p?q#f'));
    }

    public function testDtosIgnoreUnknownAdditiveFields(): void
    {
        $inv = InvoiceStatus::fromArray([
            'ordinalNumber' => 1, 'referenceNumber' => 'R', 'invoiceHash' => 'H', 'invoicingDate' => '2026-01-01T00:00:00+00:00',
            'status' => ['code' => 200, 'description' => 'ok', 'brandNew' => 1], 'futureField' => ['x' => 1],
        ]);
        self::assertTrue($inv->isAccepted());
        $s = SessionStatus::fromArray([
            'status' => ['code' => 100, 'description' => 'open'], 'dateCreated' => '2026-01-01T00:00:00+00:00',
            'dateUpdated' => '2026-01-01T00:00:00+00:00', 'somethingNew' => true,
        ]);
        self::assertSame(100, $s->status->code);
        self::assertNull($s->invoiceCount);
    }
}
