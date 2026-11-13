<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cache\Repository as CacheRepository;
use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Auth\Credentials;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Auth\TokenPair;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Context\SubjectIdentifierType;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Http\RetryPolicy;
use LuroWeb\KsefFoundry\Http\TransportOptions;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Auth\CacheTokenStorage;
use LuroWeb\KsefFoundry\Laravel\Http\GuzzlePsr18Client;
use LuroWeb\KsefFoundry\Laravel\Support\RedactingLogger;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/**
 * Builds a KsefClient from a connection config array (same keys for config('ksef.connections.*')
 * and for runtime tenants). Nothing here ever echoes a secret value into an exception message.
 */
final class KsefClientFactory
{
    public function __construct(private readonly Container $app)
    {
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $global the whole config('ksef') array (defaults)
     */
    public function make(#[\SensitiveParameter] array $connection, #[\SensitiveParameter] array $global): KsefClient
    {
        $env = self::environment($connection['environment'] ?? $global['environment'] ?? null, $connection['base_url'] ?? null);
        $builder = KsefClient::builder()
            ->environment($env)
            ->credentials($this->credentials($connection));

        $http = (array) ($global['http'] ?? []);
        $retry = (array) ($global['retry'] ?? []);
        $timeout = self::float($connection['timeout'] ?? $http['timeout'] ?? 30.0);
        $connect = self::float($connection['connect_timeout'] ?? $http['connect_timeout'] ?? 10.0);
        $builder->transportOptions(new TransportOptions(
            requestTimeout: $timeout,
            overallDeadline: self::float($connection['overall_deadline'] ?? $http['overall_deadline'] ?? 120.0),
            retry: new RetryPolicy(
                maxAttempts: max(1, self::int($retry['max_attempts'] ?? 4)),
                baseDelaySeconds: self::float($retry['base_delay'] ?? 0.25),
                maxDelaySeconds: self::float($retry['max_delay'] ?? 8.0),
                maxRetryAfterSeconds: self::int($retry['max_retry_after'] ?? 60),
            ),
        ));

        $driver = self::str($connection['http_driver'] ?? $http['driver'] ?? null, 'curl');
        if ($driver === 'guzzle') {
            $factory = new \LuroWeb\KsefFoundry\Http\Psr7\Factory();
            $builder->httpClient(new GuzzlePsr18Client($timeout, $connect), $factory, $factory);
        } elseif ($driver !== 'curl') {
            throw new ConfigurationException('ksef.http.driver must be "curl" or "guzzle".');
        }

        $cache = (array) ($global['cache'] ?? []);
        $store = $this->cacheStore($connection['cache_store'] ?? $cache['store'] ?? null);
        $prefix = self::str($connection['cache_prefix'] ?? $cache['prefix'] ?? null, 'ksef');
        $encrypt = (bool) ($cache['encrypt'] ?? true);
        $builder->tokenStorage(new CacheTokenStorage($store, $encrypt ? $this->encrypter() : null, $prefix));
        $store = $store->getStore();
        if (($cache['lock'] ?? true) && $store instanceof LockProvider) {
            $seconds = max(1, self::int($cache['lock_seconds'] ?? 150));
            $wait = max(1, self::int($cache['lock_wait_seconds'] ?? 130));
            $lock = static function (string $key, \Closure $work) use ($store, $prefix, $seconds, $wait): TokenPair {
                try {
                    /** @var TokenPair $pair */
                    $pair = $store->lock($prefix . ':login:' . $key, $seconds)->block($wait, $work);

                    return $pair;
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                    throw new KsefException('Could not acquire the KSeF login lock in time.');
                }
            };
            $builder->lock($lock);
        }

        $log = (array) ($global['log'] ?? []);
        $channel = $connection['log_channel'] ?? $log['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            $logs = $this->app->make('log');
            $builder->logger(new RedactingLogger($logs->channel($channel)));
        }

        return $builder->build();
    }

    public static function environment(mixed $value, mixed $baseUrl = null): Environment
    {
        if ($value === null || $value === '') {
            return Environment::test();
        }
        if (!is_string($value)) {
            throw new ConfigurationException('KSEF_ENVIRONMENT must be one of: test, demo, production.');
        }

        return match (strtolower(trim($value))) {
            'test' => Environment::test(),
            'demo' => Environment::demo(),
            'production' => Environment::production(),
            'custom' => Environment::custom(is_string($baseUrl) ? $baseUrl : ''),
            default => throw new ConfigurationException('KSEF_ENVIRONMENT must be one of: test, demo, production.'),
        };
    }

    /** @param array<string, mixed> $c */
    public function credentials(#[\SensitiveParameter] array $c): Credentials
    {
        $context = self::context($c);
        $auth = strtolower(self::str($c['auth'] ?? null, 'token'));
        if ($auth === 'token') {
            $token = $c['token'] ?? null;
            if (!is_string($token) || $token === '') {
                throw new ConfigurationException('KSeF token is not configured (KSEF_TOKEN / connection "token").');
            }

            return new KsefTokenCredentials(new SensitiveString($token), $context);
        }
        if ($auth !== 'certificate') {
            throw new ConfigurationException('KSEF_AUTH must be "token" or "certificate".');
        }
        $cert = self::pemFrom($c, 'certificate_pem', 'certificate_path', 'certificate');
        $key = self::pemFrom($c, 'private_key_pem', 'private_key_path', 'private key');
        $pass = $c['private_key_passphrase'] ?? null;
        $subject = ($c['subject_identifier'] ?? null) === 'certificateFingerprint'
            ? SubjectIdentifierType::CertificateFingerprint
            : SubjectIdentifierType::CertificateSubject;

        return new CertificateCredentials(
            $cert,
            new SensitiveString($key),
            $context,
            $subject,
            is_string($pass) && $pass !== '' ? new SensitiveString($pass) : null,
        );
    }

    /** @param array<string, mixed> $c */
    private static function context(#[\SensitiveParameter] array $c): ContextIdentifier
    {
        $value = $c['context_value'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ConfigurationException('KSeF context is not configured (KSEF_CONTEXT_NIP / connection "context_value").');
        }

        return match (strtolower(self::str($c['context_type'] ?? null, 'nip'))) {
            'nip' => ContextIdentifier::nip($value),
            'internal_id' => ContextIdentifier::internalId($value),
            'nip_vat_ue' => ContextIdentifier::nipVatUe($value),
            default => throw new ConfigurationException('KSEF_CONTEXT_TYPE must be nip, internal_id or nip_vat_ue.'),
        };
    }

    /** @param array<string, mixed> $c */
    private static function pemFrom(#[\SensitiveParameter] array $c, string $inline, string $path, string $what): string
    {
        $pem = $c[$inline] ?? null;
        if (is_string($pem) && $pem !== '') {
            return $pem;
        }
        $file = $c[$path] ?? null;
        if (!is_string($file) || $file === '') {
            throw new ConfigurationException(sprintf('KSeF %s is not configured (%s or %s).', $what, $path, $inline));
        }
        $data = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($data) || $data === '') {
            throw new ConfigurationException(sprintf('KSeF %s file is not readable (%s).', $what, $path));
        }

        return $data;
    }

    private function cacheStore(mixed $name): CacheRepository
    {
        $factory = $this->app->make(CacheFactory::class);
        $repo = $factory->store(is_string($name) && $name !== '' ? $name : null);
        if (!$repo instanceof CacheRepository) {
            throw new ConfigurationException('The configured cache store is not an Illuminate cache repository.');
        }

        return $repo;
    }

    private function encrypter(): Encrypter
    {
        try {
            $e = $this->app->make('encrypter');
        } catch (\Throwable) {
            throw new ConfigurationException('Token cache encryption needs APP_KEY (or set KSEF_CACHE_ENCRYPT=false, not recommended).');
        }

        return $e instanceof Encrypter ? $e : throw new ConfigurationException('No Laravel encrypter available.');
    }

    private static function str(mixed $v, string $default): string
    {
        return is_string($v) && $v !== '' ? $v : $default;
    }

    private static function float(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
