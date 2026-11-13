<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LuroWeb\KsefFoundry\Auth\AuthOptions;
use LuroWeb\KsefFoundry\Auth\Credentials;
use LuroWeb\KsefFoundry\Auth\InMemoryTokenStorage;
use LuroWeb\KsefFoundry\Auth\TokenStorageInterface;
use LuroWeb\KsefFoundry\Auth\XadesSigner;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Http\CurlHttpClient;
use LuroWeb\KsefFoundry\Http\GuzzleHttpClient;
use LuroWeb\KsefFoundry\Http\TimeoutAwareClient;
use LuroWeb\KsefFoundry\Http\Psr7\Factory;
use LuroWeb\KsefFoundry\Http\TransportOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Composition root configuration. Defaults: TEST environment, built-in curl PSR-18 client, no-op logger,
 * in-memory token storage.
 */
final class KsefClientBuilder
{
    private Environment $environment;
    private ?Credentials $credentials = null;
    private ?ClientInterface $httpClient = null;
    private bool $discover = false;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;
    private ?LoggerInterface $logger = null;
    private ?TokenStorageInterface $storage = null;
    private TransportOptions $transportOptions;
    private AuthOptions $authOptions;
    private ?XadesSigner $signer = null;
    private ?\Closure $lock = null;
    private ?\Closure $clock = null;
    private ?\Closure $sleeper = null;

    public function __construct()
    {
        $this->environment = Environment::test();
        $this->transportOptions = new TransportOptions();
        $this->authOptions = new AuthOptions();
    }

    public function environment(Environment $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    public function credentials(Credentials $credentials): self
    {
        $this->credentials = $credentials;

        return $this;
    }

    /**
     * Use your own PSR-18 client and PSR-17 factories. The client MUST NOT follow redirects to other hosts
     * (configure it so) and must have TLS verification enabled.
     */
    public function httpClient(ClientInterface $client, RequestFactoryInterface $requests, StreamFactoryInterface $streams): self
    {
        $this->httpClient = $client;
        $this->requestFactory = $requests;
        $this->streamFactory = $streams;

        return $this;
    }

    /**
     * Opt in to php-http/discovery for the PSR-18 client and PSR-17 factories. A discovered stock client is NOT used
     * as is (stock Guzzle follows redirects and has no deadline): Guzzle is wrapped in the hardened GuzzleHttpClient
     * (no redirects, enforced timeouts bounded by the overall deadline, size cap, sanitised errors). Any other
     * discovered client is REJECTED at build() unless it implements TimeoutAwareClient; pass it explicitly through
     * httpClient() when you have configured it safely yourself.
     */
    public function discoverHttpClient(): self
    {
        $this->discover = true;
        $this->httpClient = null;

        return $this;
    }

    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function tokenStorage(TokenStorageInterface $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    public function transportOptions(TransportOptions $options): self
    {
        $this->transportOptions = $options;

        return $this;
    }

    public function authOptions(AuthOptions $options): self
    {
        $this->authOptions = $options;

        return $this;
    }

    public function xadesSigner(XadesSigner $signer): self
    {
        $this->signer = $signer;

        return $this;
    }

    /**
     * Optional cross-process mutex wrapped around login/refresh.
     *
     * @param \Closure(string, \Closure(): \LuroWeb\KsefFoundry\Auth\TokenPair): \LuroWeb\KsefFoundry\Auth\TokenPair $lock
     */
    public function lock(\Closure $lock): self
    {
        $this->lock = $lock;

        return $this;
    }

    /** @param \Closure(): int $clock unix time (tests) */
    public function clock(\Closure $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    /** @param \Closure(float): void $sleeper (tests) */
    public function sleeper(\Closure $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    public function build(): KsefClient
    {
        if ($this->credentials === null) {
            throw new ConfigurationException('Credentials are required (KsefTokenCredentials or CertificateCredentials).');
        }
        $factory = new Factory();
        if ($this->discover) {
            $found = Psr18ClientDiscovery::find();
            if ($found instanceof \GuzzleHttp\ClientInterface) {
                $found = new GuzzleHttpClient($found, $this->transportOptions->requestTimeout);
                // The discovered Guzzle instance's own configuration is ignored on purpose: it is re-sent with hardened options.
            } elseif (!$found instanceof TimeoutAwareClient) {
                throw new ConfigurationException(sprintf(
                    'Discovered HTTP client %s cannot be hardened (redirects/timeouts): pass it explicitly via httpClient() '
                    . 'after configuring it with redirects disabled, or use the built-in client.',
                    $found::class
                ));
            }
            $this->httpClient = $found;
            $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
            $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
        }
        $client = $this->httpClient ?? new CurlHttpClient();

        return new KsefClient(
            $this->environment,
            $this->credentials,
            $client,
            $this->requestFactory ?? $factory,
            $this->streamFactory ?? $factory,
            $this->transportOptions,
            $this->authOptions,
            $this->storage ?? new InMemoryTokenStorage($this->clock),
            $this->logger,
            $this->signer,
            $this->lock,
            $this->clock,
            $this->sleeper,
        );
    }
}
