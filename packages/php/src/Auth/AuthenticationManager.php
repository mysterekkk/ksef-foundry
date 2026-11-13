<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Crypto\Crypto;
use LuroWeb\KsefFoundry\Crypto\PublicKeyProvider;
use LuroWeb\KsefFoundry\Crypto\PublicKeyUsage;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Exception\ValidationException;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Obtains and caches access tokens (JWT) per tenant key (contract section 4).
 *
 * Token flow: challenge, RSA-OAEP(token|ts), /auth/ksef-token, poll, redeem. Certificate flow: challenge,
 * AuthTokenRequest + XAdES, /auth/xades-signature, poll, redeem.
 * Cached per tenant key sha256(env|ctxType|ctxValue|method|credentialFingerprint); refreshed when
 * exp - now < skew; one full re-login if the refresh token is rejected; concurrent callers share one flight.
 * The JWT is never decoded for authorisation, only the server-declared validUntil is used for scheduling.
 */
final class AuthenticationManager
{
    /** Authentication operation status "no permissions assigned". */
    public const NO_PERMISSION_AUTH_STATUS = 415;

    private readonly \Closure $clock;
    private readonly \Closure $sleeper;
    private readonly LoggerInterface $logger;

    /** @var array<string, true> */
    private array $inFlight = [];

    /** @var array<string, int> tenant key => unix time the cached tokens were issued by this manager */
    private array $issuedAt = [];

    /** @var array<string, array{0: int, 1: ApiException}> negative cache: tenant key => [blocked until, error] */
    private array $blocked = [];

    /**
     * @param (\Closure(): int)|null $clock unix time
     * @param (\Closure(float): void)|null $sleeper
     * @param (\Closure(string, \Closure(): TokenPair): TokenPair)|null $lock optional cross-process mutex: lock(key, critical section)
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly AuthApi $api,
        private readonly PublicKeyProvider $keys,
        private readonly Crypto $crypto,
        private readonly TokenStorageInterface $storage = new InMemoryTokenStorage(),
        private readonly ?XadesSigner $signer = null,
        private readonly AuthOptions $options = new AuthOptions(),
        ?\Closure $clock = null,
        ?\Closure $sleeper = null,
        private readonly ?\Closure $lock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->sleeper = $sleeper ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
        $this->logger = $logger ?? new NullLogger();
    }

    public function tenantKey(Credentials $credentials): string
    {
        $ctx = $credentials->context();

        return hash('sha256', implode('|', [
            $this->environment->name,
            $this->environment->baseUrl,
            $ctx->type->value,
            $ctx->value,
            $credentials->method(),
            $credentials->fingerprint(),
        ]));
    }

    /**
     * A valid JWT for these credentials. $forceRenew skips the cached access token (use after a 401).
     */
    public function getAccessToken(Credentials $credentials, bool $forceRenew = false): SensitiveString
    {
        $key = $this->tenantKey($credentials);
        $now = ($this->clock)();
        $pair = $this->storage->get($key);
        if (!$forceRenew && $pair !== null && $pair->accessValidFor($now, $this->options->expirySkewSeconds)) {
            return $pair->accessToken;
        }
        $block = $this->blocked[$key] ?? null;
        if ($block !== null) {
            if ($now < $block[0]) {
                throw $block[1]; // negative cache: do not hammer /auth/* with credentials the server just rejected
            }
            unset($this->blocked[$key]);
        }

        return $this->singleFlight($key, fn (): TokenPair => $this->renew($key, $credentials, $forceRenew, $pair))->accessToken;
    }

    /** Forgets cached tokens of these credentials (does not revoke the session on the server). */
    public function forget(Credentials $credentials): void
    {
        $key = $this->tenantKey($credentials);
        $this->storage->delete($key);
        unset($this->blocked[$key]);
    }

    /**
     * Called when a freshly (re)issued token was rejected by the API again (persistent 401): drops the cached tokens and
     * makes getAccessToken() fail fast with this error for AuthOptions::$rejectionBackoffSeconds.
     */
    public function reject(Credentials $credentials, ApiException $error): void
    {
        $key = $this->tenantKey($credentials);
        $this->storage->delete($key);
        $this->blocked[$key] = [($this->clock)() + $this->options->rejectionBackoffSeconds, $error];
    }

    /**
     * @param \Closure(): TokenPair $work
     */
    private function singleFlight(string $key, \Closure $work): TokenPair
    {
        while (isset($this->inFlight[$key])) {
            if (\Fiber::getCurrent() === null) {
                throw new ConfigurationException('Re-entrant authentication for the same tenant outside a Fiber.');
            }
            \Fiber::suspend();
            $pair = $this->storage->get($key);
            if ($pair !== null && $pair->accessValidFor(($this->clock)(), $this->options->expirySkewSeconds)) {
                return $pair;
            }
        }
        $this->inFlight[$key] = true;
        try {
            return $this->lock !== null ? ($this->lock)($key, $work) : $work();
        } finally {
            unset($this->inFlight[$key]);
        }
    }

    private function renew(string $key, Credentials $credentials, bool $forceRenew, ?TokenPair $seen): TokenPair
    {
        $skew = $this->options->expirySkewSeconds;
        // Another process may have renewed while we waited for the lock.
        $pair = $this->storage->get($key) ?? $seen;
        $now = ($this->clock)();
        if (!$forceRenew && $pair !== null && $pair->accessValidFor($now, $skew)) {
            return $pair;
        }
        if ($pair !== null && $pair->refreshValidFor($now, $skew)) {
            try {
                [$access, $until] = $this->api->refresh($pair->refreshToken);
                $new = $pair->withAccessToken($access, $until);
                $this->store($key, $new);
                $this->logger->info('ksef.auth.refreshed');

                return $new;
            } catch (AuthenticationException | AuthorizationException | ValidationException $e) {
                $this->logger->info('ksef.auth.refresh_rejected', ['status' => $e->statusCode()]);
                $this->storage->delete($key);
            }
        }
        try {
            $new = $this->login($credentials);
        } catch (AuthenticationException $e) {
            // 415 (no permissions yet) is an AuthorizationException and is deliberately NOT cached: callers poll it.
            $this->blocked[$key] = [($this->clock)() + $this->options->rejectionBackoffSeconds, $e];

            throw $e;
        }
        unset($this->blocked[$key]);
        $this->store($key, $new);
        $this->logger->info('ksef.auth.logged_in', ['method' => $credentials->method()]);

        return $new;
    }

    /**
     * True when this manager issued (login/refresh) the cached access token within the last $withinSeconds seconds:
     * a 401 on such a token is a real rejection, not an expired token, so renewing again would only hammer /auth/*.
     */
    public function wasJustIssued(Credentials $credentials, int $withinSeconds = 5): bool
    {
        $at = $this->issuedAt[$this->tenantKey($credentials)] ?? null;

        return $at !== null && ($this->clock)() - $at <= $withinSeconds;
    }

    private function store(string $key, TokenPair $pair): void
    {
        $this->issuedAt[$key] = ($this->clock)();
        $ttl = $pair->refreshValidUntil->getTimestamp() - ($this->clock)();
        $this->storage->put($key, $pair, max(1, $ttl));
    }

    private function login(Credentials $credentials): TokenPair
    {
        $init = match (true) {
            $credentials instanceof KsefTokenCredentials => $this->initWithToken($credentials),
            $credentials instanceof CertificateCredentials => $this->initWithCertificate($credentials),
            default => throw new ConfigurationException('Unsupported credentials type.'),
        };
        $this->awaitSuccess($init);

        return $this->api->redeem($init->authenticationToken);
    }

    private function initWithToken(KsefTokenCredentials $c): AuthInit
    {
        for ($attempt = 1;; ++$attempt) {
            $challenge = $this->api->challenge();
            $publicKey = $this->keys->get(PublicKeyUsage::KsefTokenEncryption);
            $encrypted = $this->crypto->encryptKsefToken($c->token()->reveal(), $challenge->timestampMs, $publicKey);
            try {
                return $this->api->submitKsefToken($challenge, $c->context(), $encrypted, $publicKey->publicKeyId);
            } catch (ApiException $e) {
                if ($attempt === 1 && $e->hasKsefCode(21470)) {
                    $this->keys->refresh(); // key rotated: refetch once and retry once
                    continue;
                }
                throw $e;
            }
        }
    }

    private function initWithCertificate(CertificateCredentials $c): AuthInit
    {
        $signer = $this->signer ?? new XadesSigner($this->clock);
        $challenge = $this->api->challenge();
        $xml = (new AuthTokenRequestBuilder())->build($challenge->challenge, $c->context(), $c->subjectIdentifierType());

        return $this->api->submitXades($signer->sign($xml, $c));
    }

    private function awaitSuccess(AuthInit $init): void
    {
        $deadline = microtime(true) + $this->options->pollTimeoutSeconds;
        while (true) {
            $status = $this->api->status($init->referenceNumber, $init->authenticationToken);
            if ($status->isSuccess()) {
                return;
            }
            if (!$status->isInProgress()) {
                // Parity with the TypeScript SDK: 415 (no permissions) is an AuthorizationException (HTTP-like 403),
                // every other failed authentication status (425, 450, 460 ...) an AuthenticationException (401).
                $message = sprintf(
                    'Authentication failed: %d %s%s',
                    $status->code,
                    $status->description,
                    $status->details === [] ? '' : ' (' . implode('; ', $status->details) . ')'
                );
                if ($status->code === self::NO_PERMISSION_AUTH_STATUS) {
                    throw new AuthorizationException($message, 403, [], $status->details, null, [], null, null, $status->code);
                }

                throw new AuthenticationException($message, 401, [], $status->details, null, [], null, null, $status->code);
            }
            if (microtime(true) + $this->options->pollIntervalSeconds >= $deadline) {
                throw new TimeoutException('Timed out waiting for KSeF authentication to complete (status stayed "in progress").');
            }
            ($this->sleeper)($this->options->pollIntervalSeconds);
        }
    }

    /** @internal for the facade: never used to decide authorisation. */
    public function environment(): Environment
    {
        return $this->environment;
    }
}
