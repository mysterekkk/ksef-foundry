<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Fault;

use LuroWeb\KsefFoundry\Auth\InMemoryTokenStorage;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\NetworkException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Tests\Support\FaultClient;
use LuroWeb\KsefFoundry\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Tests\Support\KsefLikeKeys;
use PHPUnit\Framework\TestCase;

/** fault-injection: authentication manager behaviour against a REAL local server speaking the KSeF auth protocol shape. */
final class AuthenticationFaultTest extends TestCase
{
    private KsefLikeKeys $keys;

    protected function setUp(): void
    {
        $this->keys = new KsefLikeKeys();
    }

    private static function jwt(string $tag): string
    {
        return 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.' . $tag . '-SIG';
    }

    /**
     * @param list<int> $statusSequence
     * @return array<string, list<array<string, mixed>>>
     */
    private function loginScript(array $statusSequence = [100, 200], string $jwtTag = 'A'): array
    {
        $statuses = [];
        foreach ($statusSequence as $code) {
            $statuses[] = ['status' => 200, 'body' => json_encode(['startDate' => '2026-01-01T00:00:00+00:00', 'authenticationMethod' => 'Token', 'status' => ['code' => $code, 'description' => 'd', 'details' => $code >= 400 ? ['Brak przypisanych uprawnień.'] : []]])];
        }

        return [
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $this->keys->listJson()]],
            'POST /v2/auth/challenge' => [['status' => 200, 'body' => json_encode(['challenge' => '20260101-CR-ABCDEF0123-ABCDEF0123-AB', 'timestamp' => '2026-01-01T10:00:00+00:00', 'timestampMs' => 1767261600123, 'clientIp' => '127.0.0.1'])]],
            'POST /v2/auth/ksef-token' => [['status' => 202, 'body' => json_encode(['referenceNumber' => '20260101-AU-ABCDEF0123-ABCDEF0123-AB', 'authenticationToken' => ['token' => self::jwt('TEMP'), 'validUntil' => gmdate('c', time() + 600)]])]],
            'GET /v2/auth/20260101-AU-ABCDEF0123-ABCDEF0123-AB' => $statuses,
            'POST /v2/auth/token/redeem' => [['status' => 200, 'body' => json_encode(['accessToken' => ['token' => self::jwt($jwtTag . '-ACCESS'), 'validUntil' => gmdate('c', time() + 900)], 'refreshToken' => ['token' => self::jwt($jwtTag . '-REFRESH'), 'validUntil' => gmdate('c', time() + 86400)]])]],
        ];
    }

    public function testFullTokenFlowEncryptsTokenAndTimestampAndPolls(): void
    {
        $srv = new FaultServer($this->loginScript([100, 100, 200]));
        $c = new FaultClient($srv, false);
        $jwt = $c->client->auth()->getAccessToken($c->credentials);
        self::assertSame(self::jwt('A-ACCESS'), $jwt->reveal());

        $req = array_values(array_filter($srv->requests(), static fn (array $r): bool => $r['path'] === '/v2/auth/ksef-token'))[0];
        $body = json_decode((string) $req['body'], true);
        self::assertSame('20260101-CR-ABCDEF0123-ABCDEF0123-AB', $body['challenge']);
        self::assertSame(['type' => 'Nip', 'value' => $c->credentials->context()->value], $body['contextIdentifier']);
        self::assertSame($this->keys->publicKeyId, $body['publicKeyId']);
        $plain = $c->client->crypto()->rsaOaepSha256Decrypt((string) base64_decode($body['encryptedToken'], true), $this->keys->privateKeyPem);
        self::assertSame(FaultClient::SECRET_TOKEN . '|1767261600123', $plain, 'format {token}|{timestampMs}');
        self::assertStringNotContainsString(FaultClient::SECRET_TOKEN, (string) $req['body']);

        self::assertSame(3, $srv->count('GET', '/v2/auth/20260101-AU-ABCDEF0123-ABCDEF0123-AB'));
        self::assertSame(hash('sha256', 'Bearer ' . self::jwt('TEMP')), array_values(array_filter($srv->requests(), static fn (array $r): bool => str_starts_with((string) $r['path'], '/v2/auth/2026')))[0]['auth'], 'poll uses the temporary authenticationToken');
        self::assertSame(1, $srv->count('POST', '/v2/auth/token/redeem'));
        self::assertSame([1.0, 1.0], $c->sleeps);

        // cached: no further network
        $c->client->auth()->getAccessToken($c->credentials);
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'));
    }

    public function testNoPermissionsAuthStatus415IsAuthorizationExceptionLikeTypeScript(): void
    {
        $srv = new FaultServer($this->loginScript([100, 415]));
        $c = new FaultClient($srv, false);
        try {
            $c->client->auth()->getAccessToken($c->credentials);
            self::fail();
        } catch (AuthorizationException $e) {
            self::assertSame(415, $e->authStatusCode());
            self::assertSame(403, $e->statusCode());
            self::assertSame(['Brak przypisanych uprawnień.'], $e->details());
            self::assertSame('Authentication failed: 415 d (Brak przypisanych uprawnień.)', $e->getMessage());
            self::assertSame('authorization', $e->category());
            self::assertSame(415, $e->diagnostics()['authStatusCode']);
        }
        self::assertSame(0, $srv->count('POST', '/v2/auth/token/redeem'), 'redeem is only called after status 200');
    }

    public function testOtherAuthFailureStatusesAreAuthenticationException(): void
    {
        foreach ([425, 450, 460, 470, 550] as $code) {
            $srv = new FaultServer($this->loginScript([$code]));
            $c = new FaultClient($srv, false);
            try {
                $c->client->auth()->getAccessToken($c->credentials);
                self::fail((string) $code);
            } catch (AuthenticationException $e) {
                self::assertSame($code, $e->authStatusCode());
                self::assertSame(401, $e->statusCode());
                self::assertSame('authentication', $e->category());
            }
        }
    }

    public function testPollingHasAnOverallDeadline(): void
    {
        $srv = new FaultServer($this->loginScript([100]));
        $c = new FaultClient($srv, false, null, 900, static function (float $s): void {
            usleep(30_000);
        });
        $b = KsefClient::builder()->environment($srv->environment())->credentials($c->credentials)
            ->authOptions(new \LuroWeb\KsefFoundry\Auth\AuthOptions(60, 0.2, 0.05))
            ->sleeper(static function (float $s): void {
                usleep(60_000);
            })->build();
        $this->expectException(TimeoutException::class);
        $b->auth()->getAccessToken($c->credentials);
    }

    public function testKeyRotationError21470RefetchesOnceAndRetriesOnce(): void
    {
        $script = $this->loginScript();
        $script['POST /v2/auth/ksef-token'] = [
            ['status' => 400, 'body' => json_encode(['errors' => [['code' => 21470, 'description' => 'Klucz wycofany']]])],
            ...$script['POST /v2/auth/ksef-token'],
        ];
        $srv = new FaultServer($script);
        $c = new FaultClient($srv, false);
        self::assertSame(self::jwt('A-ACCESS'), $c->client->auth()->getAccessToken($c->credentials)->reveal());
        self::assertSame(2, $srv->count('POST', '/v2/auth/ksef-token'));
        self::assertSame(2, $srv->count('GET', '/v2/security/public-key-certificates'));
        self::assertSame(2, $srv->count('POST', '/v2/auth/challenge'));
    }

    public function testExpiryWithinSkewTriggersRefreshNotLogin(): void
    {
        $script = $this->loginScript();
        $script['POST /v2/auth/token/refresh'] = [['status' => 200, 'body' => json_encode(['accessToken' => ['token' => self::jwt('NEW-ACCESS'), 'validUntil' => gmdate('c', time() + 900)]])]];
        $srv = new FaultServer($script);
        $c = new FaultClient($srv, true, null, 30); // access expires in 30s; skew is 60s
        $jwt = $c->client->auth()->getAccessToken($c->credentials);
        self::assertSame(self::jwt('NEW-ACCESS'), $jwt->reveal());
        self::assertSame(1, $srv->count('POST', '/v2/auth/token/refresh'));
        self::assertSame(0, $srv->count('POST', '/v2/auth/challenge'));
        self::assertSame(hash('sha256', 'Bearer ' . FaultClient::REFRESH), $srv->requests()[0]['auth'], 'refresh token is the bearer');
        // still valid, far from expiry: no more calls
        $c->client->auth()->getAccessToken($c->credentials);
        self::assertSame(1, $srv->count('POST', '/v2/auth/token/refresh'));
        // time passes: now = +850s => within skew again
        $c->now += 850;
        $c->client->auth()->getAccessToken($c->credentials);
        self::assertSame(2, $srv->count('POST', '/v2/auth/token/refresh'));
    }

    public function testTokenFreshOutsideSkewIsReusedWithoutNetwork(): void
    {
        $srv = new FaultServer([]);
        $c = new FaultClient($srv, true, null, 120);
        self::assertSame(FaultClient::ACCESS, $c->client->auth()->getAccessToken($c->credentials)->reveal());
        self::assertSame([], $srv->requests());
    }

    public function testRejectedRefreshTokenCausesExactlyOneFullReLogin(): void
    {
        $script = $this->loginScript([200], 'B');
        $script['POST /v2/auth/token/refresh'] = [['status' => 401, 'body' => '{"title":"Unauthorized","status":401,"detail":"expired","timestamp":"t"}']];
        $srv = new FaultServer($script);
        $c = new FaultClient($srv, true, null, 10);
        self::assertSame(self::jwt('B-ACCESS'), $c->client->auth()->getAccessToken($c->credentials)->reveal());
        self::assertSame(1, $srv->count('POST', '/v2/auth/token/refresh'));
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'));
        self::assertSame(1, $srv->count('POST', '/v2/auth/token/redeem'));
    }

    public function testExpiredRefreshTokenSkipsRefreshAndLogsInAgain(): void
    {
        $srv = new FaultServer($this->loginScript([200], 'C'));
        $c = new FaultClient($srv, false);
        $c->storage->put(
            $c->client->auth()->tenantKey($c->credentials),
            new \LuroWeb\KsefFoundry\Auth\TokenPair(new SensitiveString('old'), (new \DateTimeImmutable())->setTimestamp($c->now - 10), new SensitiveString('oldr'), (new \DateTimeImmutable())->setTimestamp($c->now + 20)),
            100
        );
        self::assertSame(self::jwt('C-ACCESS'), $c->client->auth()->getAccessToken($c->credentials)->reveal());
        self::assertSame(0, $srv->count('POST', '/v2/auth/token/refresh'));
    }

    public function testConcurrentFibersShareOneLogin(): void
    {
        $srv = new FaultServer($this->loginScript([100, 200], 'F'));
        $c = new FaultClient($srv, false, null, 900, static function (float $s): void {
            \Fiber::suspend(); // yield during the poll wait so other callers run while the login is in flight
        });
        $tokens = [];
        $fibers = [];
        for ($i = 0; $i < 4; ++$i) {
            $fibers[] = new \Fiber(function () use ($c, &$tokens, $i): void {
                $tokens[$i] = $c->client->auth()->getAccessToken($c->credentials)->reveal();
            });
        }
        foreach ($fibers as $f) {
            $f->start();
        }
        $guard = 0;
        while (array_filter($fibers, static fn (\Fiber $f): bool => !$f->isTerminated()) !== [] && $guard++ < 50) {
            foreach ($fibers as $f) {
                if ($f->isSuspended()) {
                    $f->resume();
                }
            }
        }
        self::assertCount(4, $tokens);
        self::assertSame([self::jwt('F-ACCESS')], array_values(array_unique($tokens)));
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'), 'single flight: exactly one login');
        self::assertSame(1, $srv->count('POST', '/v2/auth/token/redeem'));
    }

    public function testTenantIsolationByContextCredentialAndEnvironment(): void
    {
        $srv = new FaultServer($this->loginScript([200], 'T'));
        $storage = new InMemoryTokenStorage();
        $nipA = SyntheticIdentity::nip();
        $nipB = SyntheticIdentity::nip();
        $mk = static fn (string $token, string $nip, ?Environment $env = null): KsefClient => KsefClient::builder()
            ->environment($env ?? $srv->environment())
            ->credentials(new KsefTokenCredentials(new SensitiveString($token), ContextIdentifier::nip($nip)))
            ->tokenStorage($storage)->build();
        $a = $mk('tok-1', $nipA);
        $sameContextOtherToken = $mk('tok-2', $nipA);
        $otherContext = $mk('tok-1', $nipB);
        $otherServer = new FaultServer([]);
        $otherEnv = $mk('tok-1', $nipA, $otherServer->environment());

        $keyA = $a->auth()->tenantKey($a->credentials());
        $keys = [$keyA, $sameContextOtherToken->auth()->tenantKey($sameContextOtherToken->credentials()), $otherContext->auth()->tenantKey($otherContext->credentials())];
        self::assertCount(3, array_unique($keys), 'context and credential are part of the key');
        self::assertSame($keyA, $mk('tok-1', $nipA)->auth()->tenantKey($mk('tok-1', $nipA)->credentials()), 'stable for identical inputs');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $keyA);
        self::assertStringNotContainsString('tok-1', $keyA);

        // A logs in; B must NOT receive A's token from the shared storage and must perform its own login
        $a->auth()->getAccessToken($a->credentials());
        self::assertSame(1, $srv->count('POST', '/v2/auth/challenge'));
        $otherContext->auth()->getAccessToken($otherContext->credentials());
        self::assertSame(2, $srv->count('POST', '/v2/auth/challenge'));
        $sameContextOtherToken->auth()->getAccessToken($sameContextOtherToken->credentials());
        self::assertSame(3, $srv->count('POST', '/v2/auth/challenge'));
        // and A is still served from cache
        $a->auth()->getAccessToken($a->credentials());
        self::assertCount(3, array_filter($srv->requests(), static fn (array $r): bool => $r['path'] === '/v2/auth/challenge'));
        self::assertNotSame($keyA, $otherEnv->auth()->tenantKey($otherEnv->credentials()), 'base URL is part of the key');
    }

    public function testStorageNeverReceivesTheLongLivedSecret(): void
    {
        $srv = new FaultServer($this->loginScript([200]));
        $c = new FaultClient($srv, false);
        $c->client->auth()->getAccessToken($c->credentials);
        $pair = $c->storage->get($c->client->auth()->tenantKey($c->credentials));
        self::assertNotNull($pair);
        $dump = (string) json_encode($pair->toStorageArray());
        self::assertStringNotContainsString(FaultClient::SECRET_TOKEN, $dump);
        ob_start();
        var_dump($pair);
        self::assertStringNotContainsString('A-ACCESS-SIG', (string) ob_get_clean());
    }

    public function testNetworkFailureDuringLoginSurfaces(): void
    {
        $srv = new FaultServer([]);
        $c = new FaultClient($srv, false);
        $srv->stop();
        $this->expectException(NetworkException::class);
        $c->client->auth()->getAccessToken($c->credentials);
    }

    public function testUnauthorizedOnServiceCallRenewsTokenOnceAndRepeats(): void
    {
        $script = $this->loginScript([200], 'R');
        $script['POST /v2/auth/token/refresh'] = [['status' => 200, 'body' => json_encode(['accessToken' => ['token' => self::jwt('R2-ACCESS'), 'validUntil' => gmdate('c', time() + 900)]])]];
        $script['GET /v2/limits/context'] = [
            ['status' => 401, 'body' => '{"title":"Unauthorized","status":401,"detail":"revoked","timestamp":"t"}'],
            ['status' => 200, 'body' => '{"onlineSession":{"maxInvoiceSizeInMB":1,"maxInvoiceWithAttachmentSizeInMB":3,"maxInvoices":10000},"batchSession":{"maxInvoiceSizeInMB":1,"maxInvoiceWithAttachmentSizeInMB":3,"maxInvoices":10000},"collectiveIdentifier":{"maxInvoices":2}}'],
        ];
        $srv = new FaultServer($script);
        $c = new FaultClient($srv, true);
        $limits = $c->client->limits()->context();
        self::assertSame(10000, $limits->onlineSession->maxInvoices);
        self::assertSame(2, $srv->count('GET', '/v2/limits/context'));
        self::assertSame(1, $srv->count('POST', '/v2/auth/token/refresh'));
        $calls = array_values(array_filter($srv->requests(), static fn (array $r): bool => $r['path'] === '/v2/limits/context'));
        self::assertSame(hash('sha256', 'Bearer ' . FaultClient::ACCESS), $calls[0]['auth']);
        self::assertSame(hash('sha256', 'Bearer ' . self::jwt('R2-ACCESS')), $calls[1]['auth']);
    }
}
