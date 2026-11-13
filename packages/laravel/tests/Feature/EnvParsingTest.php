<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;

/** The shipped config/ksef.php read through real env() with real environment variables. */
final class EnvParsingTest extends TestCase
{
    private const VARS = [
        'KSEF_ENVIRONMENT', 'KSEF_AUTH', 'KSEF_CONTEXT_TYPE', 'KSEF_CONTEXT_NIP', 'KSEF_TOKEN', 'KSEF_HTTP_TIMEOUT',
        'KSEF_RETRY_ATTEMPTS', 'KSEF_CACHE_STORE', 'KSEF_LOG_CHANNEL', 'KSEF_CACHE_ENCRYPT',
    ];

    /** @param array<string, string> $vars */
    private function withEnv(array $vars): void
    {
        foreach ($vars as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        parent::tearDown();
    }

    protected function configureKsef(Repository $config): void
    {
        // leave config/ksef.php untouched: everything must come from the environment
        $config->set('ksef.jobs.reconcile_delay', 0);
    }

    public function testDefaultsWithoutAnyEnvironmentVariablesAreTestAndTokenAuth(): void
    {
        self::assertSame('test', config('ksef.environment'));
        self::assertSame('token', config('ksef.connections.default.auth'));
        self::assertSame('nip', config('ksef.connections.default.context_type'));
        self::assertNull(config('ksef.cache.store'));
        self::assertNull(config('ksef.log.channel'));
        self::assertSame(30.0, config('ksef.http.timeout'));
        self::assertTrue(config('ksef.cache.encrypt'));
    }

    public function testEnvironmentVariablesAreParsedIntoAWorkingClient(): void
    {
        $nip = SyntheticIdentity::nip();
        $this->withEnv([
            'KSEF_AUTH' => 'token', 'KSEF_CONTEXT_TYPE' => 'nip', 'KSEF_CONTEXT_NIP' => $nip,
            'KSEF_TOKEN' => 'ENV-TOKEN-VALUE', 'KSEF_HTTP_TIMEOUT' => '12.5', 'KSEF_RETRY_ATTEMPTS' => '2',
            'KSEF_CACHE_STORE' => 'array', 'KSEF_CACHE_ENCRYPT' => 'false',
        ]);
        $this->refreshApplication();

        self::assertSame($nip, config('ksef.connections.default.context_value'));
        self::assertSame(12.5, config('ksef.http.timeout'));
        self::assertSame(2, config('ksef.retry.max_attempts'));
        self::assertFalse(config('ksef.cache.encrypt'));
        $client = app(KsefManager::class)->connection();
        self::assertSame('TEST', $client->environment()->name);
        self::assertInstanceOf(KsefTokenCredentials::class, $client->credentials());
        self::assertSame($nip, $client->credentials()->context()->value);
    }

    public function testProductionIsUsedOnlyWhenSetExplicitly(): void
    {
        $this->withEnv(['KSEF_CONTEXT_NIP' => SyntheticIdentity::nip(), 'KSEF_TOKEN' => 'x-token-value']);
        $this->refreshApplication();
        self::assertSame('TEST', app(KsefManager::class)->connection()->environment()->name);

        $this->withEnv(['KSEF_ENVIRONMENT' => 'production']);
        $this->refreshApplication();
        self::assertSame('PRODUCTION', app(KsefManager::class)->connection()->environment()->name);
    }

    public function testInvalidEnvironmentValueGivesAClearConfigurationException(): void
    {
        $this->withEnv(['KSEF_ENVIRONMENT' => 'prod', 'KSEF_CONTEXT_NIP' => SyntheticIdentity::nip(), 'KSEF_TOKEN' => 'x-token-value']);
        $this->refreshApplication();
        try {
            app(KsefManager::class)->connection();
            self::fail('must throw');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('KSEF_ENVIRONMENT must be one of: test, demo, production', $e->getMessage());
        }
    }

    public function testInvalidContextTypeAndMalformedNipAreRejected(): void
    {
        $this->withEnv(['KSEF_CONTEXT_TYPE' => 'pesel', 'KSEF_CONTEXT_NIP' => SyntheticIdentity::nip(), 'KSEF_TOKEN' => 'x-token-value']);
        $this->refreshApplication();
        try {
            app(KsefManager::class)->connection();
            self::fail('must throw');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('KSEF_CONTEXT_TYPE', $e->getMessage());
        }
        $this->withEnv(['KSEF_CONTEXT_TYPE' => 'nip', 'KSEF_CONTEXT_NIP' => '123']);
        $this->refreshApplication();
        $this->expectException(ConfigurationException::class);
        app(KsefManager::class)->connection();
    }
}
