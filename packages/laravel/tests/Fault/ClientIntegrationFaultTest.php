<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Fault;

use GuzzleHttp\Client as GuzzleClient;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Exception\NetworkException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Laravel\Http\GuzzlePsr18Client;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\KsefLikeKeys;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fault-injection: HTTP drivers (built-in curl vs Guzzle wrapper), TLS refusal, cross-process login lock,
 * real login through the Laravel cache storage, redacting log bridge. Real sockets, real cache, no mocks.
 */
final class ClientIntegrationFaultTest extends TestCase
{
    private ?FaultServer $keep = null;
    /** @var resource|null */
    private $tls = null;
    private string $tlsDir = '';

    protected function tearDown(): void
    {
        if (is_resource($this->tls)) {
            proc_terminate($this->tls);
            proc_close($this->tls);
        }
        foreach (glob($this->tlsDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        if ($this->tlsDir !== '') {
            @rmdir($this->tlsDir);
        }
        parent::tearDown();
    }

    /** @param array<string, list<array<string, mixed>>> $script */
    private function server(array $script, string $driver = 'curl'): FaultServer
    {
        $this->keep = new FaultServer($script);
        $this->useServer($this->keep, $driver);

        return $this->keep;
    }

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'builtin curl' => ['curl'];
        yield 'guzzle wrapper' => ['guzzle'];
    }

    #[DataProvider('drivers')]
    public function testRedirectToAnotherHostIsNeverFollowed(string $driver): void
    {
        $srv = $this->server(['GET /v2/limits/context' => [[
            'status' => 302, 'headers' => ['Location' => 'http://localhost:1/v2/evil'], 'body' => '',
        ]]], $driver);
        $this->seedTokens();
        try {
            app(KsefManager::class)->connection()->limits()->context();
            self::fail('a redirect is an error');
        } catch (ApiException $e) {
            self::assertSame(302, $e->statusCode());
        }
        self::assertSame(1, $srv->count('GET', '/v2/limits/context'));
        self::assertCount(1, $srv->requests(), 'nothing else was requested');
    }

    public function testGuzzleWrapperPinsSecurityOptionsEvenForACustomGuzzleInstance(): void
    {
        $srv = $this->server(['GET /v2/limits/context' => [
            ['status' => 302, 'headers' => ['Location' => $this->keep?->baseUrl() . '/v2/second'], 'body' => ''],
        ], 'GET /v2/second' => [['status' => 200, 'body' => '{}']]]);
        $weak = new GuzzleClient(['allow_redirects' => true, 'verify' => false, 'http_errors' => true]);
        $client = new GuzzlePsr18Client(guzzle: $weak);
        $response = $client->sendRequest((new \LuroWeb\KsefFoundry\Http\Psr7\Factory())->createRequest('GET', $srv->baseUrl() . '/v2/limits/context'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, $srv->count('GET', '/v2/second'));

        $o = GuzzlePsr18Client::options(7.0, 3.0);
        self::assertFalse($o['allow_redirects']);
        self::assertTrue($o['verify']);
        self::assertSame(7.0, $o['timeout']);
        self::assertSame(3.0, $o['connect_timeout']);
    }

    public function testGuzzleDriverEnforcesTheConfiguredTimeout(): void
    {
        config(['ksef.http.timeout' => 1.0]);
        $this->server(['GET /v2/limits/context' => [['status' => 200, 'delay' => 3, 'body' => '{}']]], 'guzzle');
        $this->seedTokens();
        $started = microtime(true);
        try {
            app(KsefManager::class)->connection()->limits()->context();
            self::fail('must time out');
        } catch (TimeoutException) {
            self::assertLessThan(2.8, microtime(true) - $started);
        }
    }

    #[DataProvider('drivers')]
    public function testUntrustedTlsCertificateIsRefused(string $driver): void
    {
        $keys = new KsefLikeKeys();
        $this->tlsDir = sys_get_temp_dir() . '/ksef-tls-' . bin2hex(random_bytes(4));
        mkdir($this->tlsDir, 0700);
        $pem = $keys->privateKeyPem . "-----BEGIN CERTIFICATE-----\n" . chunk_split($keys->certificateDerBase64, 64, "\n") . "-----END CERTIFICATE-----\n";
        file_put_contents($this->tlsDir . '/server.pem', $pem);
        $pipes = [];
        $this->tls = proc_open(
            [PHP_BINARY, __DIR__ . '/tls_server.php', $this->tlsDir . '/port', $this->tlsDir . '/server.pem', $this->tlsDir . '/log'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->tlsDir . '/out', 'w'], 2 => ['file', $this->tlsDir . '/err', 'w']],
            $pipes,
        );
        $deadline = microtime(true) + 10;
        while (!is_file($this->tlsDir . '/port') || trim((string) file_get_contents($this->tlsDir . '/port')) === '') {
            self::assertLessThan($deadline, microtime(true), 'TLS server did not start: ' . (string) @file_get_contents($this->tlsDir . '/err'));
            usleep(20_000);
        }
        $port = trim((string) file_get_contents($this->tlsDir . '/port'));
        config([
            'ksef.environment' => 'custom',
            'ksef.connections.default.base_url' => 'https://127.0.0.1:' . $port . '/v2',
            'ksef.http.driver' => $driver,
            'ksef.http.timeout' => 5.0,
        ]);
        app(KsefManager::class)->purge();
        $this->seedTokens();
        try {
            app(KsefManager::class)->connection()->limits()->context();
            self::fail('a self-signed certificate must be refused');
        } catch (NetworkException) {
            self::addToAssertionCount(1);
        }
        self::assertStringNotContainsString('REQUEST-RECEIVED', (string) @file_get_contents($this->tlsDir . '/log'), 'no HTTP request was sent over the untrusted channel');
    }

    public function testLoginHoldsAndRespectsTheCrossProcessLock(): void
    {
        config(['ksef.cache.lock_wait_seconds' => 1]);
        $srv = $this->server($this->loginScript());
        $client = app(KsefManager::class)->connection();
        $tenant = $client->auth()->tenantKey($client->credentials());

        $lock = app('cache')->store()->lock('ksef:login:' . $tenant, 30);
        self::assertTrue($lock->get(), 'another worker is logging in');
        try {
            $client->auth()->getAccessToken($client->credentials());
            self::fail('must not log in while the lock is held elsewhere');
        } catch (KsefException $e) {
            self::assertStringContainsString('login lock', $e->getMessage());
        }
        self::assertSame(0, $srv->count('POST', '/v2/auth/challenge'), 'no login request while the lock is held');
        $lock->release();

        $jwt = $client->auth()->getAccessToken($client->credentials());
        self::assertSame(self::jwt('A-ACCESS'), $jwt->reveal());
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'));
        self::assertTrue(app('cache')->store()->lock('ksef:login:' . $tenant, 5)->get(), 'lock released after the login');
    }

    public function testRealLoginIsCachedEncryptedAndSharedWithAFreshClient(): void
    {
        $srv = $this->server($this->loginScript());
        $client = app(KsefManager::class)->connection();
        $client->auth()->getAccessToken($client->credentials());

        $key = 'ksef:jwt:' . $client->auth()->tenantKey($client->credentials());
        $raw = app('cache')->store()->get($key);
        self::assertIsString($raw);
        self::assertStringNotContainsString('SIG', $raw);
        self::assertStringNotContainsString('accessToken', (string) base64_decode($raw, true));

        // "another process": brand-new manager and client, same cache: no second login
        app(KsefManager::class)->purge();
        $fresh = app(KsefManager::class)->connection();
        self::assertNotSame($client, $fresh);
        self::assertSame(self::jwt('A-ACCESS'), $fresh->auth()->getAccessToken($fresh->credentials())->reveal());
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'));
    }

    public function testLogChannelReceivesSdkLogsWithoutSecrets(): void
    {
        config(['ksef.log.channel' => 'ksef-test']);
        $srv = $this->server($this->loginScript() + ['GET /v2/limits/context' => [['status' => 200, 'body' => json_encode([
            'onlineSession' => ['maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 3, 'maxInvoices' => 10000],
            'batchSession' => ['maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 3, 'maxInvoices' => 10000],
        ])]]]);
        app(KsefManager::class)->connection()->limits()->context();
        self::assertSame(1, $srv->count('GET', '/v2/limits/context'));

        $log = $this->logContents();
        self::assertStringContainsString('ksef.http.response', $log);
        self::assertStringContainsString('ksef.auth.logged_in', $log);
        foreach ([self::SECRET_TOKEN, self::jwt('A-ACCESS'), self::jwt('A-REFRESH'), self::jwt('TEMP'), 'Bearer eyJ', 'SIG'] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
    }

    private static function jwt(string $tag): string
    {
        return 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.' . $tag . '-SIG';
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function loginScript(): array
    {
        $keys = new KsefLikeKeys();
        $ref = '20260101-AU-ABCDEF0123-ABCDEF0123-AB';

        return [
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]],
            'POST /v2/auth/challenge' => [['status' => 200, 'body' => json_encode(['challenge' => '20260101-CR-ABCDEF0123-ABCDEF0123-AB', 'timestamp' => '2026-01-01T10:00:00+00:00', 'timestampMs' => 1767261600123, 'clientIp' => '127.0.0.1'])]],
            'POST /v2/auth/ksef-token' => [['status' => 202, 'body' => json_encode(['referenceNumber' => $ref, 'authenticationToken' => ['token' => self::jwt('TEMP'), 'validUntil' => gmdate('c', time() + 600)]])]],
            'GET /v2/auth/' . $ref => [['status' => 200, 'body' => json_encode(['startDate' => '2026-01-01T00:00:00+00:00', 'authenticationMethod' => 'Token', 'status' => ['code' => 200, 'description' => 'ok', 'details' => []]])]],
            'POST /v2/auth/token/redeem' => [['status' => 200, 'body' => json_encode(['accessToken' => ['token' => self::jwt('A-ACCESS'), 'validUntil' => gmdate('c', time() + 900)], 'refreshToken' => ['token' => self::jwt('A-REFRESH'), 'validUntil' => gmdate('c', time() + 86400)]])]],
        ];
    }
}
