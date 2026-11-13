<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Fault;

use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\NetworkException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Exception\ServerException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Exception\ValidationException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\RetryPolicy;
use LuroWeb\KsefFoundry\Http\TransportOptions;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Tests\Support\ArrayLogger;
use LuroWeb\KsefFoundry\Tests\Support\FaultClient;
use LuroWeb\KsefFoundry\Tests\Support\FaultServer;
use PHPUnit\Framework\TestCase;

/** fault-injection: a REAL local TCP/HTTP server misbehaves; this is NOT proof of KSeF API compatibility. */
final class TransportFaultTest extends TestCase
{
    private const OK = ['status' => 200, 'body' => '{"ok":true}'];

    private function options(int $attempts = 4, float $timeout = 5.0, float $deadline = 60.0): TransportOptions
    {
        return new TransportOptions($timeout, $deadline, new RetryPolicy($attempts, 0.25, 8.0, 60, false));
    }

    private function get(FaultClient $c, string $path = '/limits/context'): \LuroWeb\KsefFoundry\Http\HttpResponse
    {
        return $c->client->transport()->send(new HttpRequest('GET', $path, idempotent: true));
    }

    public function testGet429WithRetryAfterSecondsIsRetried(): void
    {
        $srv = new FaultServer(['GET /v2/x' => [
            ['status' => 429, 'headers' => ['Retry-After' => '7'], 'body' => '{"status":{"code":429,"description":"Too Many Requests","details":["wait"]}}'],
            self::OK,
        ]]);
        $c = new FaultClient($srv, false, $this->options());
        self::assertSame(200, $this->get($c, '/x')->status);
        self::assertSame(2, $srv->count('GET', '/v2/x'));
        self::assertSame([7.0], $c->sleeps, 'Retry-After wins over the exponential schedule');
    }

    public function testRetryAfterHttpDateIsHonoured(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 12) . ' GMT';
        $srv = new FaultServer(['GET /v2/x' => [['status' => 429, 'headers' => ['Retry-After' => $date], 'body' => '{}'], self::OK]]);
        $c = new FaultClient($srv, false, $this->options());
        $this->get($c, '/x');
        self::assertCount(1, $c->sleeps);
        self::assertGreaterThanOrEqual(10.0, $c->sleeps[0]);
        self::assertLessThanOrEqual(13.0, $c->sleeps[0]);
    }

    public function testGet503ThenOkUsesExponentialBackoff(): void
    {
        $srv = new FaultServer(['GET /v2/x' => [['status' => 503, 'body' => ''], ['status' => 503, 'body' => ''], self::OK]]);
        $c = new FaultClient($srv, false, $this->options());
        self::assertSame(200, $this->get($c, '/x')->status);
        self::assertSame(3, $srv->count('GET', '/v2/x'));
        self::assertSame([0.25, 0.5], $c->sleeps);
    }

    public function testGet500IsNotRetriedAndIsMapped(): void
    {
        $srv = new FaultServer(['GET /v2/x' => [['status' => 500, 'body' => '{"title":"Internal Server Error","status":500,"detail":"boom"}'], self::OK]]);
        $c = new FaultClient($srv, false, $this->options());
        try {
            $this->get($c, '/x');
            self::fail('500 must surface');
        } catch (ServerException $e) {
            self::assertSame(500, $e->statusCode());
            self::assertFalse($e->retryable());
        }
        self::assertSame(1, $srv->count('GET', '/v2/x'));
        self::assertSame([], $c->sleeps);
    }

    public function testRetriesAreBounded(): void
    {
        $srv = new FaultServer(['GET /v2/x' => [['status' => 503, 'body' => '']]]);
        $c = new FaultClient($srv, false, $this->options(3));
        try {
            $this->get($c, '/x');
            self::fail();
        } catch (ServerException $e) {
            self::assertSame(503, $e->statusCode());
            self::assertTrue($e->retryable());
        }
        self::assertSame(3, $srv->count('GET', '/v2/x'));
    }

    public function testPostIsNeverRetriedOn5xxButIsOn429(): void
    {
        $srv = new FaultServer([
            'POST /v2/five' => [['status' => 503, 'body' => ''], self::OK],
            'POST /v2/rate' => [['status' => 429, 'headers' => ['Retry-After' => '1'], 'body' => '{}'], self::OK],
        ]);
        $c = new FaultClient($srv, false, $this->options());
        $t = $c->client->transport();
        try {
            $t->send(new HttpRequest('POST', '/five', body: '{}'));
            self::fail();
        } catch (ServerException) {
            self::assertSame(1, $srv->count('POST', '/v2/five'), 'ambiguous 5xx on a non-idempotent POST: never retried');
        }
        self::assertSame(200, $t->send(new HttpRequest('POST', '/rate', body: '{}'))->status);
        self::assertSame(2, $srv->count('POST', '/v2/rate'), '429 means not processed: retry allowed');
    }

    public function testRateLimitBeyondDeadlineThrowsWithoutSleeping(): void
    {
        $srv = new FaultServer(['GET /v2/x' => [['status' => 429, 'headers' => ['Retry-After' => '50'], 'body' => '{}']]]);
        $c = new FaultClient($srv, false, $this->options(4, 5.0, 10.0));
        try {
            $this->get($c, '/x');
            self::fail();
        } catch (RateLimitException $e) {
            self::assertSame(50, $e->retryAfterSeconds());
        }
        self::assertSame([], $c->sleeps);
        self::assertSame(1, $srv->count('GET', '/v2/x'));
    }

    public function testTimeoutOnGetIsRetriedAndPostIsNot(): void
    {
        $srv = new FaultServer([
            'GET /v2/slow' => [['status' => 200, 'body' => '{}', 'delay' => 2.5]],
            'POST /v2/slow' => [['status' => 200, 'body' => '{}', 'delay' => 2.5]],
        ]);
        $c = new FaultClient($srv, false, $this->options(2, 1.0));
        $t = $c->client->transport();
        $t0 = microtime(true);
        try {
            $t->send(new HttpRequest('GET', '/slow', idempotent: true));
            self::fail();
        } catch (TimeoutException $e) {
            self::assertInstanceOf(NetworkException::class, $e);
        }
        self::assertLessThan(2.4 + 2.6, microtime(true) - $t0);
        usleep(2_800_000); // the single-threaded server is still sleeping on the abandoned requests
        try {
            $t->send(new HttpRequest('POST', '/slow', body: '{}'));
            self::fail();
        } catch (TimeoutException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(2, $srv->count('GET', '/v2/slow'));
        self::assertSame(1, $srv->count('POST', '/v2/slow'), 'timeout on a POST is ambiguous: no automatic retry');
    }

    public function testConnectionResetAndTruncatedBodyAreNetworkErrors(): void
    {
        $srv = new FaultServer([
            'GET /v2/reset' => [['action' => 'reset']],
            'GET /v2/trunc' => [['action' => 'truncate', 'body' => '{"a":1}']],
            'POST /v2/reset' => [['action' => 'reset']],
        ]);
        $c = new FaultClient($srv, false, $this->options(3));
        $t = $c->client->transport();
        foreach (['/reset', '/trunc'] as $p) {
            try {
                $t->send(new HttpRequest('GET', $p, idempotent: true));
                self::fail($p);
            } catch (NetworkException $e) {
                self::assertNotInstanceOf(TimeoutException::class, $e);
            }
        }
        self::assertSame(3, $srv->count('GET', '/v2/reset'), 'idempotent GET retried on network error');
        self::assertSame(3, $srv->count('GET', '/v2/trunc'));
        try {
            $t->send(new HttpRequest('POST', '/reset', body: '{}'));
            self::fail();
        } catch (NetworkException) {
            self::assertSame(1, $srv->count('POST', '/v2/reset'));
        }
    }

    public function testClosedPortIsNetworkException(): void
    {
        $srv = new FaultServer([]);
        $c = new FaultClient($srv, false, $this->options(2));
        $srv->stop();
        $this->expectException(NetworkException::class);
        $this->get($c);
    }

    public function testCrossHostRedirectIsNeverFollowed(): void
    {
        $other = new FaultServer(['GET /steal' => [self::OK]]);
        $srv = new FaultServer(['GET /v2/r' => [['status' => 302, 'headers' => ['Location' => $other->baseUrl() . '/steal'], 'body' => '']]]);
        $c = new FaultClient($srv, false, $this->options());
        $c->seed();
        try {
            $c->client->transport()->send(new HttpRequest('GET', '/r', bearer: new SensitiveString('tok'), idempotent: true));
            self::fail('3xx is an error');
        } catch (ApiException $e) {
            self::assertSame(302, $e->statusCode());
            self::assertStringContainsString('never followed', $e->getMessage());
        }
        self::assertSame([], $other->requests(), 'the redirect target must never receive a request (or the bearer token)');
        // same-host redirects are not followed either
        $srv2 = new FaultServer(['GET /v2/r' => [['status' => 301, 'headers' => ['Location' => '/v2/ok'], 'body' => '']], 'GET /v2/ok' => [self::OK]]);
        $c2 = new FaultClient($srv2, false, $this->options());
        try {
            $c2->client->transport()->send(new HttpRequest('GET', '/r', idempotent: true));
            self::fail();
        } catch (ApiException) {
            self::assertSame(0, $srv2->count('GET', '/v2/ok'));
        }
    }

    public function testAbsoluteUrlsAreRefusedUnlessHostIsEnvironmentOrAllowListed(): void
    {
        $evil = new FaultServer(['GET /x' => [self::OK]]);
        $srv = new FaultServer(['GET /v2/y' => [self::OK]]);
        $c = new FaultClient($srv, false, $this->options());
        $t = $c->client->transport();
        foreach (
            [
            $evil->baseUrl() . '/x',
            'http://example.org/x',
            'https://api-test.ksef.mf.gov.pl.evil.example/x',
            '//evil.example/x',
            'file:///etc/passwd',
            'gopher://127.0.0.1/x',
            'http://user:pw@127.0.0.1:' . $srv->port . '/v2/y',
            ] as $url
        ) {
            try {
                $t->send(new HttpRequest('GET', $url, idempotent: true));
                self::fail('SSRF: ' . $url);
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $evil->requests());
        foreach (['', 'x', '/../etc', "/a\r\nHost: evil"] as $path) {
            try {
                $t->send(new HttpRequest('GET', $path, idempotent: true));
                self::fail('bad path: ' . $path);
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        // own host is allowed as an absolute URL
        self::assertSame(200, $t->send(new HttpRequest('GET', $srv->baseUrl() . '/v2/y', idempotent: true))->status);
    }

    public function testExplicitAllowListPermitsAnotherHost(): void
    {
        $other = new FaultServer(['GET /x' => [self::OK]]);
        $srv = new FaultServer([]);
        $opts = new TransportOptions(5.0, 20.0, new RetryPolicy(1), allowedHosts: ['127.0.0.1:' . $other->port]);
        $c = new FaultClient($srv, false, $opts);
        self::assertSame(200, $c->client->transport()->send(new HttpRequest('GET', $other->baseUrl() . '/x', idempotent: true))->status);
    }

    public function testErrorBodiesMapToTheHierarchyOverRealHttp(): void
    {
        $srv = new FaultServer([
            'POST /v2/v' => [['status' => 400, 'headers' => ['Content-Type' => 'application/problem+json'], 'body' => '{"title":"Bad Request","status":400,"instance":"/v","detail":"d","errors":[{"code":21405,"description":"Błąd","details":["x"]}],"timestamp":"t","traceId":"tr"}']],
            'GET /v2/legacy' => [['status' => 400, 'body' => '{"exception":{"exceptionDetailList":[{"exceptionCode":21173,"exceptionDescription":"Brak sesji"}]}}']],
        ]);
        $c = new FaultClient($srv, false, $this->options());
        $t = $c->client->transport();
        try {
            $t->send(new HttpRequest('POST', '/v', body: '{}'));
            self::fail();
        } catch (ValidationException $e) {
            self::assertSame(21405, $e->ksefCode());
            self::assertSame('tr', $e->traceId());
        }
        try {
            $t->send(new HttpRequest('GET', '/legacy', idempotent: true));
            self::fail();
        } catch (ValidationException $e) {
            self::assertSame(21173, $e->ksefCode());
        }
        $reqs = $srv->requests();
        self::assertSame('problem-details', $reqs[0]['xErrorFormat'], 'every call asks for problem+json');
        self::assertSame('application/json', $reqs[0]['accept']);
        self::assertStringStartsWith('luroweb-ksef-foundry-php/', (string) $reqs[0]['userAgent']);
    }

    public function testBearerIsInjectedAndNothingSecretIsLoggedOrExposed(): void
    {
        $secretBody = '{"encryptedToken":"VERY-SECRET-CIPHERTEXT","challenge":"c"}';
        $srv = new FaultServer([
            'POST /v2/auth' => [['status' => 401, 'body' => '{"title":"Unauthorized","status":401,"detail":"Bearer ' . FaultClient::ACCESS . ' rejected","timestamp":"t"}']],
        ]);
        $log = new ArrayLogger();
        $c = new FaultClient($srv, false, $this->options(), 900, null, $log);
        try {
            $c->client->transport()->send(new HttpRequest('POST', '/auth', body: $secretBody, bearer: new SensitiveString(FaultClient::ACCESS)));
            self::fail();
        } catch (ApiException $e) {
            $everything = (string) json_encode($e->diagnostics()) . $e->getMessage() . $e->getTraceAsString() . $log->dump();
            self::assertStringNotContainsString(FaultClient::ACCESS, $everything);
            self::assertStringNotContainsString('ACCESS-SIGNATURE-VALUE', $everything);
            self::assertStringNotContainsString('VERY-SECRET-CIPHERTEXT', $everything);
        }
        $expected = hash('sha256', 'Bearer ' . FaultClient::ACCESS);
        self::assertSame($expected, $srv->requests()[0]['auth'], 'bearer was injected into the request');
        self::assertNotEmpty($log->records);
        foreach ($log->records as $r) {
            self::assertSame(['method', 'path'], array_slice(array_keys($r['context']), 0, 2));
        }
    }
}
