<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Support;

use LuroWeb\KsefFoundry\Auth\InMemoryTokenStorage;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Auth\TokenPair;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Http\RetryPolicy;
use LuroWeb\KsefFoundry\Http\TransportOptions;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;

/** Builds a KsefClient wired to a FaultServer, with recorded back-off sleeps and optional pre-seeded tokens. */
final class FaultClient
{
    public const SECRET_TOKEN = 'KSEF-SECRET-TOKEN-0123456789';
    public const ACCESS = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.ACCESS-SIGNATURE-VALUE';
    public const REFRESH = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.REFRESH-SIGNATURE-VALUE';

    public KsefClient $client;
    public InMemoryTokenStorage $storage;
    public KsefTokenCredentials $credentials;
    /** @var list<float> */
    public array $sleeps = [];
    public int $now;

    public function __construct(
        FaultServer $server,
        bool $seedTokens = true,
        ?TransportOptions $options = null,
        int $accessTtl = 900,
        ?\Closure $sleeper = null,
        ?\Psr\Log\LoggerInterface $logger = null,
    ) {
        $this->now = time();
        $this->credentials = new KsefTokenCredentials(new SensitiveString(self::SECRET_TOKEN), ContextIdentifier::nip(SyntheticIdentity::nip()));
        $this->storage = new InMemoryTokenStorage(fn (): int => $this->now);
        $builder = KsefClient::builder()
            ->environment($server->environment())
            ->credentials($this->credentials)
            ->tokenStorage($this->storage)
            ->clock(fn (): int => $this->now)
            ->sleeper($sleeper ?? function (float $s): void {
                $this->sleeps[] = $s;
            })
            ->transportOptions($options ?? new TransportOptions(
                requestTimeout: 5.0,
                overallDeadline: 60.0,
                retry: new RetryPolicy(maxAttempts: 4, baseDelaySeconds: 0.25, jitter: false),
            ));
        if ($logger !== null) {
            $builder->logger($logger);
        }
        $this->client = $builder->build();
        if ($seedTokens) {
            $this->seed($accessTtl);
        }
    }

    public function seed(int $accessTtl = 900, int $refreshTtl = 86400): void
    {
        $this->storage->put(
            $this->client->auth()->tenantKey($this->credentials),
            new TokenPair(
                new SensitiveString(self::ACCESS),
                (new \DateTimeImmutable())->setTimestamp($this->now + $accessTtl),
                new SensitiveString(self::REFRESH),
                (new \DateTimeImmutable())->setTimestamp($this->now + $refreshTtl),
            ),
            $refreshTtl,
        );
    }
}
