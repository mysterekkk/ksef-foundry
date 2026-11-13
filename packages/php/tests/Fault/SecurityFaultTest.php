<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Fault;

use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Exception\NetworkException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;
use LuroWeb\KsefFoundry\Exception\XmlValidationException;
use LuroWeb\KsefFoundry\Http\CurlHttpClient;
use LuroWeb\KsefFoundry\Http\HttpClientError;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\Psr7\Factory;
use LuroWeb\KsefFoundry\Http\RetryPolicy;
use LuroWeb\KsefFoundry\Http\TransportOptions;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Session\SendOptions;
use LuroWeb\KsefFoundry\Session\SubmitOptions;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Tests\Support\FaultClient;
use LuroWeb\KsefFoundry\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Tests\Support\KsefLikeKeys;
use PHPUnit\Framework\TestCase;

/** fault-injection: security hardening checked against REAL local servers. */
final class SecurityFaultTest extends TestCase
{
    private const SESSION = '20260101-SO-ABCDEF0123-ABCDEF0123-AB';
    private const SECRET_BODY = '{"encryptedToken":"ENCRYPTED-PAYLOAD-SECRET-BYTES"}';

    private string|false $previousIgnoreArgs = '';

    protected function setUp(): void
    {
        $this->previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->previousIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
        }
    }

    /** @param list<string> $secrets */
    private function assertNoLeak(\Throwable $e, array $secrets): void
    {
        $dumps = [print_r($e, true), $e->getTraceAsString(), var_export($e->getTrace(), true)];
        ob_start();
        var_dump($e);
        $dumps[] = (string) ob_get_clean();
        if ($e instanceof KsefException) {
            $dumps[] = (string) json_encode($e->diagnostics());
        }
        try {
            $dumps[] = serialize($e);
        } catch (\Throwable) {
            // not serialisable (SensitiveParameterValue / closures): nothing can leak that way
        }
        for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
            $dumps[] = print_r($p, true);
        }
        foreach ($dumps as $i => $d) {
            foreach ($secrets as $s) {
                self::assertFalse(str_contains($d, $s), sprintf('secret "%s" found in dump #%d', $s, $i));
            }
            self::assertSame(0, preg_match('/\[Authorization\]|Authorization:|Bearer /i', $d), sprintf('Authorization header in dump #%d', $i));
        }
    }

    public function testNetworkTimeoutAndUnknownOutcomeExceptionsNeverRevealTokenOrBody(): void
    {
        $keys = new KsefLikeKeys();
        $srv = new FaultServer([
            'POST /v2/reset' => [['action' => 'reset']],
            'POST /v2/slow' => [['status' => 200, 'body' => '{}', 'delay' => 2.5]],
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]],
            'POST /v2/sessions/online' => [['status' => 201, 'body' => json_encode(['referenceNumber' => self::SESSION, 'validUntil' => gmdate('c', time() + 3600)])]],
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['action' => 'reset']],
        ]);
        $c = new FaultClient($srv, true, new TransportOptions(1.0, 30.0, RetryPolicy::none()));
        $bearer = new SensitiveString(FaultClient::ACCESS);
        $secrets = [FaultClient::ACCESS, 'ACCESS-SIGNATURE-VALUE', 'ENCRYPTED-PAYLOAD-SECRET-BYTES', base64_encode('ENCRYPTED-PAYLOAD-SECRET-BYTES')];

        try {
            $c->client->transport()->send(new HttpRequest('POST', '/reset', body: self::SECRET_BODY, bearer: $bearer));
            self::fail();
        } catch (NetworkException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertNull($e->getPrevious(), 'the raw client exception is never chained');
            $this->assertNoLeak($e, $secrets);
        }
        try {
            $c->client->transport()->send(new HttpRequest('POST', '/slow', body: self::SECRET_BODY, bearer: $bearer));
            self::fail();
        } catch (TimeoutException $e) {
            self::assertNull($e->getPrevious());
            $this->assertNoLeak($e, $secrets);
        }
        usleep(2_800_000);

        $session = $c->client->sessions()->openOnline();
        $xml = '<Faktura>CONFIDENTIAL-INVOICE</Faktura>';
        try {
            $c->client->sessions()->sendInvoice($session, $xml, new SendOptions(validate: false));
            self::fail();
        } catch (UnknownOutcomeException $e) {
            $this->assertNoLeak($e, [...$secrets, 'CONFIDENTIAL-INVOICE', base64_encode($session->key()->key()), bin2hex($session->key()->key())]);
            $this->assertNoLeak($e->getPrevious() ?? $e, $secrets);
        }
    }

    public function testRawClientErrorAndPsr7MessagesAreSanitised(): void
    {
        $f = new Factory();
        $request = $f->createRequest('POST', 'http://127.0.0.1:1/v2/x')
            ->withHeader('Authorization', 'Bearer RAW-SECRET-JWT')
            ->withBody($f->createStream('RAW-SECRET-BODY'));
        $dump = print_r($request, true);
        self::assertStringNotContainsString('RAW-SECRET-JWT', $dump);
        self::assertStringNotContainsString('RAW-SECRET-BODY', $dump);
        ob_start();
        var_dump($request->getBody());
        self::assertStringNotContainsString('RAW-SECRET-BODY', (string) ob_get_clean());

        try {
            (new CurlHttpClient(1.0, 2.0))->sendRequest($request);
            self::fail();
        } catch (HttpClientError $e) {
            self::assertSame([], array_diff_key($e->getRequest()->getHeaders(), ['Host' => 1]), 'the carried request has no headers');
            self::assertSame('', (string) $e->getRequest()->getBody());
            $this->assertNoLeak($e, ['RAW-SECRET-JWT', 'RAW-SECRET-BODY']);
        }
    }

    public function testBuiltInClientHungRequestIsBoundedByTheOverallDeadline(): void
    {
        $srv = new FaultServer(['GET /v2/hang' => [['status' => 200, 'body' => '{}', 'delay' => 4.0]]]);
        // requestTimeout 30 s but the overall deadline is 1.5 s: the per-attempt limit is min(30, remaining).
        $c = new FaultClient($srv, false, new TransportOptions(30.0, 1.5, RetryPolicy::none()));
        $t0 = microtime(true);
        try {
            $c->client->transport()->send(new HttpRequest('GET', '/hang', idempotent: true));
            self::fail();
        } catch (TimeoutException) {
            self::assertLessThan(3.0, microtime(true) - $t0);
        }
        usleep(2_600_000);
    }

    public function testDiscoveredGuzzleIsHardenedNoRedirectsAndBoundedDeadline(): void
    {
        $other = new FaultServer(['GET /steal' => [['status' => 200, 'body' => '{}']]]);
        $srv = new FaultServer([
            'GET /v2/r' => [['status' => 302, 'headers' => ['Location' => $other->baseUrl() . '/steal'], 'body' => '']],
            'GET /v2/hang' => [['status' => 200, 'body' => '{}', 'delay' => 4.0]],
        ]);
        $client = KsefClient::builder()
            ->environment($srv->environment())
            ->credentials((new FaultClient($srv, false))->credentials)
            ->discoverHttpClient()
            ->transportOptions(new TransportOptions(30.0, 1.5, RetryPolicy::none()))
            ->build();
        try {
            $client->transport()->send(new HttpRequest('GET', '/r', bearer: new SensitiveString('TOKSECRET-BEARER'), idempotent: true));
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(302, $e->statusCode());
        }
        self::assertSame([], $other->requests(), 'stock Guzzle would have followed the redirect');
        $t0 = microtime(true);
        try {
            $client->transport()->send(new HttpRequest('GET', '/hang', idempotent: true));
            self::fail();
        } catch (TimeoutException $e) {
            self::assertLessThan(3.0, microtime(true) - $t0);
            $this->assertNoLeak($e, ['TOKSECRET-BEARER']);
        }
        usleep(2_600_000);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function authScript(int $finalStatus = 200): array
    {
        $keys = new KsefLikeKeys();
        $jwt = static fn (string $t): string => 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.' . $t;

        return [
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]],
            'POST /v2/auth/challenge' => [['status' => 200, 'body' => json_encode(['challenge' => '20260101-CR-ABCDEF0123-ABCDEF0123-AB', 'timestamp' => '2026-01-01T10:00:00+00:00', 'timestampMs' => 1767261600123, 'clientIp' => '127.0.0.1'])]],
            'POST /v2/auth/ksef-token' => [['status' => 202, 'body' => json_encode(['referenceNumber' => '20260101-AU-ABCDEF0123-ABCDEF0123-AB', 'authenticationToken' => ['token' => $jwt('TEMP'), 'validUntil' => gmdate('c', time() + 600)]])]],
            'GET /v2/auth/20260101-AU-ABCDEF0123-ABCDEF0123-AB' => [['status' => 200, 'body' => json_encode(['startDate' => '2026-01-01T00:00:00+00:00', 'authenticationMethod' => 'Token', 'status' => ['code' => $finalStatus, 'description' => 'd']])]],
            'POST /v2/auth/token/redeem' => [['status' => 200, 'body' => json_encode(['accessToken' => ['token' => $jwt('A'), 'validUntil' => gmdate('c', time() + 900)], 'refreshToken' => ['token' => $jwt('R'), 'validUntil' => gmdate('c', time() + 86400)]])]],
            'POST /v2/auth/token/refresh' => [['status' => 401, 'body' => '{"title":"Unauthorized","status":401,"detail":"no","timestamp":"t"}']],
            'GET /v2/limits/context' => [['status' => 401, 'body' => '{"title":"Unauthorized","status":401,"detail":"revoked","timestamp":"t"}']],
        ];
    }

    private function authHits(FaultServer $srv): int
    {
        return count(array_filter($srv->requests(), static fn (array $r): bool => str_starts_with((string) $r['path'], '/v2/auth/')));
    }

    public function testPersistent401DoesNotReLoginOnEveryCall(): void
    {
        $srv = new FaultServer($this->authScript());
        $c = new FaultClient($srv, true);
        $counts = [];
        for ($i = 1; $i <= 6; ++$i) {
            try {
                $c->client->limits()->context();
                self::fail('401 must surface');
            } catch (AuthenticationException $e) {
                self::assertSame(401, $e->statusCode());
            }
            $counts[$i] = $this->authHits($srv);
        }
        self::assertSame($counts[1], $counts[6], 'after the first failing call no further /auth/* request is made: ' . json_encode($counts));
        self::assertLessThanOrEqual(6, $counts[1], 'at most one re-login (challenge, submit, poll, redeem, refresh attempt) per request');
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'));
        self::assertSame(2, $srv->count('GET', '/v2/limits/context'), 'one original attempt + exactly one retry, then fail fast without calls');

        // the window expires: one more full attempt is allowed, never a loop
        $c->now += 31;
        try {
            $c->client->limits()->context();
            self::fail();
        } catch (AuthenticationException) {
            self::assertSame(2, $srv->count('POST', '/v2/auth/challenge'));
        }
    }

    public function testRejectedLoginIsNegativelyCached(): void
    {
        $srv = new FaultServer($this->authScript(450));
        $c = new FaultClient($srv, false);
        for ($i = 0; $i < 5; ++$i) {
            try {
                $c->client->auth()->getAccessToken($c->credentials);
                self::fail();
            } catch (AuthenticationException $e) {
                self::assertSame(450, $e->authStatusCode());
            }
        }
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'), 'wrong credentials are not retried against KSeF on every call');
        $c->client->auth()->forget($c->credentials);
        try {
            $c->client->auth()->getAccessToken($c->credentials);
        } catch (AuthenticationException) {
            self::assertSame(2, $srv->count('POST', '/v2/auth/challenge'), 'forget() clears the negative cache');
        }
    }

    public function testLoopingContinuationTokensTerminate(): void
    {
        $inv = ['ordinalNumber' => 1, 'referenceNumber' => '20260101-EE-ABCDEF0123-ABCDEF0123-CD', 'invoiceHash' => 'h', 'invoicingDate' => '2026-01-01T00:00:00+00:00', 'status' => ['code' => 200, 'description' => 'ok']];
        $srv = new FaultServer([
            'GET /v2/sessions/S/invoices' => [['status' => 200, 'body' => json_encode(['continuationToken' => 'SAME', 'invoices' => [$inv]])]],
            'GET /v2/tokens' => [['status' => 200, 'body' => json_encode(['continuationToken' => 'SAME', 'tokens' => []])]],
            'GET /v2/auth/sessions' => [['status' => 200, 'body' => json_encode(['continuationToken' => 'SAME', 'items' => []])]],
            'POST /v2/invoices/query/metadata' => [['status' => 200, 'body' => json_encode(['hasMore' => true, 'isTruncated' => false, 'invoices' => [[
                'ksefNumber' => 'A1', 'invoiceNumber' => 'n', 'issueDate' => '2026-01-02', 'invoicingDate' => '2026-01-03T00:00:00+00:00', 'acquisitionDate' => '2026-01-03T00:00:00+00:00',
                'permanentStorageDate' => '2026-01-03T00:00:00+00:00', 'seller' => ['nip' => '7980332920'], 'buyer' => ['identifier' => ['type' => 'None']], 'netAmount' => 1, 'grossAmount' => 1, 'vatAmount' => 0,
                'currency' => 'PLN', 'invoicingMode' => 'Online', 'invoiceType' => 'Vat', 'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
                'isSelfInvoicing' => false, 'hasAttachment' => false, 'invoiceHash' => 'h',
            ]]])]],
        ]);
        $c = new FaultClient($srv);
        $cases = [
            'session invoices' => fn () => iterator_to_array($c->client->sessions()->iterateSessionInvoices('S', 10), false),
            'reconcile (hash absent)' => fn () => $c->client->sessions()->reconcile('S', 'no-such-hash'),
            'tokens' => fn () => iterator_to_array($c->client->tokens()->iterate([], 10), false),
            'auth sessions' => fn () => iterator_to_array($c->client->authSessions()->iterate(10), false),
            'metadata hasMore without progress' => fn () => iterator_to_array($c->client->query()->iterate(
                new \LuroWeb\KsefFoundry\Query\InvoiceQueryFilters(\LuroWeb\KsefFoundry\Query\SubjectType::Subject1, \LuroWeb\KsefFoundry\Query\DateType::PermanentStorage, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-01-20T00:00:00+00:00')),
                10
            ), false),
        ];
        foreach ($cases as $name => $run) {
            $before = count($srv->requests());
            try {
                $run();
                self::fail($name . ' must not terminate normally');
            } catch (KsefException $e) {
                self::assertStringContainsString('Pagination', $e->getMessage(), $name);
            }
            self::assertLessThanOrEqual(3, count($srv->requests()) - $before, $name . ': bounded number of requests');
        }
    }

    public function testDoctypeAndPiAreRejectedEvenWithValidationDisabled(): void
    {
        $keys = new KsefLikeKeys();
        $srv = new FaultServer([
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]],
            'POST /v2/sessions/online' => [['status' => 201, 'body' => json_encode(['referenceNumber' => self::SESSION, 'validUntil' => gmdate('c', time() + 3600)])]],
        ]);
        $c = new FaultClient($srv);
        $session = $c->client->sessions()->openOnline();
        $before = count($srv->requests());
        $hostile = [
            'doctype' => '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>',
            'pi' => '<a><?php echo 1; ?></a>',
            'bom' => "\xEF\xBB\xBF<a/>",
            'forbidden char' => "<a>x\u{0086}y</a>",
        ];
        foreach ($hostile as $name => $xml) {
            try {
                $c->client->sessions()->sendInvoice($session, $xml, new SendOptions(validate: false));
                self::fail($name);
            } catch (XmlValidationException) {
                $this->addToAssertionCount(1);
            }
            try {
                $c->client->sender()->submit($xml, new SubmitOptions(send: new SendOptions(validate: false)));
                self::fail($name);
            } catch (XmlValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertCount($before, $srv->requests(), 'nothing was sent: neither invoices, nor a new session');
    }
}
