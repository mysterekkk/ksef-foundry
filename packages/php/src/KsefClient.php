<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry;

use LuroWeb\KsefFoundry\Auth\AuthApi;
use LuroWeb\KsefFoundry\Auth\AuthenticationManager;
use LuroWeb\KsefFoundry\Auth\AuthOptions;
use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Auth\Credentials;
use LuroWeb\KsefFoundry\Auth\TokenStorageInterface;
use LuroWeb\KsefFoundry\Auth\XadesSigner;
use LuroWeb\KsefFoundry\Crypto\Crypto;
use LuroWeb\KsefFoundry\Crypto\PublicKeyProvider;
use LuroWeb\KsefFoundry\Http\HttpTransport;
use LuroWeb\KsefFoundry\Http\TransportOptions;
use LuroWeb\KsefFoundry\Query\InvoiceQueryService;
use LuroWeb\KsefFoundry\Service\AuthSessionService;
use LuroWeb\KsefFoundry\Service\InvoiceService;
use LuroWeb\KsefFoundry\Service\KsefTokenService;
use LuroWeb\KsefFoundry\Service\LimitsService;
use LuroWeb\KsefFoundry\Service\TestData;
use LuroWeb\KsefFoundry\Service\UpoService;
use LuroWeb\KsefFoundry\Session\InvoiceSender;
use LuroWeb\KsefFoundry\Session\InvoiceSessionService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Facade / composition root. Build with KsefClient::builder() or KsefClient::create().
 * One client = one set of credentials = one context.
 */
final class KsefClient
{
    private readonly HttpTransport $transport;
    private readonly AuthenticationManager $auth;
    private readonly PublicKeyProvider $keys;
    private readonly AuthorizedClient $api;
    private readonly Crypto $crypto;
    private readonly \Closure $sleeper;

    private ?InvoiceSessionService $sessions = null;
    private ?InvoiceSender $sender = null;

    /**
     * @param (\Closure(string, \Closure(): \LuroWeb\KsefFoundry\Auth\TokenPair): \LuroWeb\KsefFoundry\Auth\TokenPair)|null $lock
     * @param (\Closure(): int)|null $clock
     * @param (\Closure(float): void)|null $sleeper
     *
     * @internal use KsefClient::builder() / KsefClient::create()
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly Credentials $credentials,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        TransportOptions $transportOptions,
        AuthOptions $authOptions,
        TokenStorageInterface $storage,
        ?LoggerInterface $logger = null,
        ?XadesSigner $signer = null,
        ?\Closure $lock = null,
        ?\Closure $clock = null,
        ?\Closure $sleeper = null,
    ) {
        $clock ??= static fn (): int => time();
        $this->sleeper = $sleeper ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
        $this->crypto = new Crypto();
        $this->transport = new HttpTransport($environment, $httpClient, $requestFactory, $streamFactory, $transportOptions, $logger, $sleeper);
        $this->keys = new PublicKeyProvider($this->transport, $clock);
        $this->auth = new AuthenticationManager(
            $environment,
            new AuthApi($this->transport),
            $this->keys,
            $this->crypto,
            $storage,
            $signer,
            $authOptions,
            $clock,
            $sleeper,
            $lock,
            $logger,
        );
        $this->api = new AuthorizedClient($this->transport, $this->auth, $credentials);
    }

    public static function builder(): KsefClientBuilder
    {
        return new KsefClientBuilder();
    }

    /** Shortcut: TEST environment by default; PRODUCTION must be passed explicitly. */
    public static function create(Credentials $credentials, ?Environment $environment = null): self
    {
        $b = new KsefClientBuilder();
        if ($environment !== null) {
            $b->environment($environment);
        }

        return $b->credentials($credentials)->build();
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    public function transport(): HttpTransport
    {
        return $this->transport;
    }

    public function auth(): AuthenticationManager
    {
        return $this->auth;
    }

    public function publicKeys(): PublicKeyProvider
    {
        return $this->keys;
    }

    public function crypto(): Crypto
    {
        return $this->crypto;
    }

    public function sessions(): InvoiceSessionService
    {
        return $this->sessions ??= new InvoiceSessionService($this->api, $this->keys, $this->crypto, $this->sleeper);
    }

    /** High-level InvoiceSender::submit(xml). */
    public function sender(): InvoiceSender
    {
        return $this->sender ??= new InvoiceSender($this->sessions(), $this->sleeper);
    }

    public function invoices(): InvoiceService
    {
        return new InvoiceService($this->api, $this->sleeper);
    }

    public function query(): InvoiceQueryService
    {
        return new InvoiceQueryService($this->api);
    }

    public function upo(): UpoService
    {
        return new UpoService($this->api);
    }

    public function tokens(): KsefTokenService
    {
        return new KsefTokenService($this->api, $this->sleeper);
    }

    public function authSessions(): AuthSessionService
    {
        return new AuthSessionService($this->api);
    }

    public function limits(): LimitsService
    {
        return new LimitsService($this->api);
    }

    /** TEST environment only: throws ConfigurationException elsewhere. */
    public function testData(): TestData
    {
        return new TestData($this->transport);
    }
}
