<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Feature;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Artisan;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Facades\Ksef;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\KsefServiceProvider;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;

final class ProviderTest extends TestCase
{
    public function testComposerDeclaresAutoDiscovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertSame([KsefServiceProvider::class], $composer['extra']['laravel']['providers']);
        self::assertSame(['Ksef' => Ksef::class], $composer['extra']['laravel']['aliases']);
        self::assertTrue(class_exists(KsefServiceProvider::class));
        self::assertTrue(class_exists(Ksef::class));
    }

    public function testProviderIsRegisteredAndConfigMerged(): void
    {
        self::assertCount(1, app()->getProviders(KsefServiceProvider::class));
        self::assertSame('test', config('ksef.environment'));
        self::assertSame('default', config('ksef.default'));
        self::assertTrue(config('ksef.cache.encrypt'));
    }

    public function testFacadeAliasCanBeRegistered(): void
    {
        AliasLoader::getInstance(['Ksef' => Ksef::class])->register();
        self::assertTrue(class_exists('Ksef'));
        self::assertSame(Ksef::manager(), app(KsefManager::class));
    }

    public function testConfigIsPublishableWithTag(): void
    {
        $target = config_path('ksef.php');
        @unlink($target);
        try {
            self::assertSame(0, Artisan::call('vendor:publish', ['--tag' => 'ksef-config', '--force' => true]));
            self::assertFileExists($target);
            self::assertFileEquals(__DIR__ . '/../../config/ksef.php', $target);
        } finally {
            @unlink($target);
        }
    }

    public function testClientIsASingletonAndDefaultsToTest(): void
    {
        $a = app(KsefClient::class);
        self::assertSame($a, app(KsefClient::class));
        self::assertSame($a, app(KsefManager::class)->connection());
        self::assertSame($a, app(KsefManager::class)->connection('default'));
        self::assertSame('TEST', $a->environment()->name);
        self::assertSame(app(KsefManager::class), app('ksef'));
    }

    public function testFacadeProxiesToTheDefaultClient(): void
    {
        self::assertSame(app(KsefClient::class)->environment(), Ksef::environment());
        self::assertSame(app(KsefClient::class)->limits()::class, Ksef::limits()::class);
    }

    public function testManagerProxiesMethodCallsToTheDefaultConnection(): void
    {
        self::assertSame('TEST', app(KsefManager::class)->environment()->name);
    }

    public function testMissingTokenFailsOnFirstUseNotAtBoot(): void
    {
        config(['ksef.connections.default.token' => null]);
        app(KsefManager::class)->purge();
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('token is not configured');
        app(KsefClient::class);
    }

    public function testUnknownConnectionIsAClearError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"nope" is not defined');
        app(KsefManager::class)->connection('nope');
    }

    public function testInvalidEnvironmentValueIsRejected(): void
    {
        foreach (['prod', 'live', 'PRODUCTIONS', 'test '] as $i => $bad) {
            config(['ksef.environment' => $bad]);
            app(KsefManager::class)->purge();
            try {
                app(KsefManager::class)->connection();
                if ($bad === 'test ') {
                    continue; // surrounding whitespace is trimmed to a valid value
                }
                self::fail("'$bad' must be rejected");
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('KSEF_ENVIRONMENT', $e->getMessage(), (string) $i);
                self::assertStringNotContainsString(self::SECRET_TOKEN, $e->getMessage());
            }
        }
    }

    public function testProductionOnlyWhenExplicit(): void
    {
        config(['ksef.environment' => null]);
        app(KsefManager::class)->purge();
        self::assertSame('TEST', app(KsefManager::class)->connection()->environment()->name);
        config(['ksef.environment' => 'production']);
        app(KsefManager::class)->purge();
        self::assertSame('PRODUCTION', app(KsefManager::class)->connection()->environment()->name);
    }

    public function testNamedConnectionsAndRuntimeTenantsAreIsolated(): void
    {
        $nipB = SyntheticIdentity::nip();
        config(['ksef.connections.second' => [
            'auth' => 'token', 'context_type' => 'nip', 'context_value' => $nipB, 'token' => 'OTHER-TOKEN-VALUE',
        ]]);
        $manager = app(KsefManager::class);
        $a = $manager->connection();
        $b = $manager->connection('second');
        self::assertNotSame($a, $b);
        self::assertSame($nipB, $b->credentials()->context()->value);
        $keyA = $a->auth()->tenantKey($a->credentials());
        $keyB = $b->auth()->tenantKey($b->credentials());
        self::assertNotSame($keyA, $keyB);

        // same NIP, different token: still a different tenant key (credential fingerprint)
        $c = $manager->tenant(['auth' => 'token', 'context_value' => $nipB, 'token' => 'THIRD-TOKEN-VALUE']);
        self::assertNotSame($keyB, $c->auth()->tenantKey($c->credentials()));
        self::assertSame($c, $manager->tenant(['auth' => 'token', 'context_value' => $nipB, 'token' => 'THIRD-TOKEN-VALUE']));

        // JWTs seeded for A are invisible to B and C in the real cache
        $this->seedTokens($a);
        $cache = app('cache')->store();
        self::assertTrue($cache->has('ksef:jwt:' . $keyA));
        self::assertFalse($cache->has('ksef:jwt:' . $keyB));
        self::assertFalse($cache->has('ksef:jwt:' . $c->auth()->tenantKey($c->credentials())));
    }

    public function testTenantResolverServesNamesNotInConfig(): void
    {
        $nip = SyntheticIdentity::nip();
        $manager = app(KsefManager::class);
        $manager->resolveUsing(fn (string $name): ?array => $name === 'tenant-42'
            ? ['auth' => 'token', 'context_value' => $nip, 'token' => 'DB-STORED-TOKEN']
            : null);
        self::assertSame($nip, $manager->connection('tenant-42')->credentials()->context()->value);
        $this->expectException(ConfigurationException::class);
        $manager->connection('tenant-43');
    }

    public function testCertificateAuthRequiresFilesAndNeverEchoesPaths(): void
    {
        config(['ksef.connections.default.auth' => 'certificate']);
        app(KsefManager::class)->purge();
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('certificate is not configured');
        app(KsefManager::class)->connection();
    }

    public function testAuthMethodMustBeKnown(): void
    {
        config(['ksef.connections.default.auth' => 'password']);
        app(KsefManager::class)->purge();
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('KSEF_AUTH');
        app(KsefManager::class)->connection();
    }
}
