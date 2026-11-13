<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Auth\TokenPair;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Auth\CacheTokenStorage;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\KsefServiceProvider;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use Orchestra\Testbench\TestCase as Orchestra;

/** Real container, real config/cache/queue/events (Orchestra Testbench). No mocks. */
abstract class TestCase extends Orchestra
{
    public const SECRET_TOKEN = 'KSEF-SECRET-TOKEN-0123456789';
    public const ACCESS = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.ACCESS-SIGNATURE-VALUE';
    public const REFRESH = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.REFRESH-SIGNATURE-VALUE';

    protected string $logFile = '';
    protected string $nip = '';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [KsefServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->nip = SyntheticIdentity::nip();
        $this->logFile = sys_get_temp_dir() . '/ksef-laravel-' . bin2hex(random_bytes(5)) . '.log';
        $config = $app['config'];
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('cache.default', 'array');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);
        $config->set('logging.channels.ksef-test', ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug']);
        $config->set('logging.default', 'ksef-test');
        $this->configureKsef($config);
    }

    protected function configureKsef(\Illuminate\Contracts\Config\Repository $config): void
    {
        $config->set('ksef.connections.default.context_value', $this->nip);
        $config->set('ksef.connections.default.token', self::SECRET_TOKEN);
        $config->set('ksef.jobs.reconcile_delay', 0);
        $config->set('ksef.jobs.await_seconds', 5);
        $config->set('ksef.retry.max_attempts', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
    }

    protected function tearDown(): void
    {
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    protected function createQueueTables(): void
    {
        Schema::create('jobs', static function ($t): void {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', static function ($t): void {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    /** Points the DEFAULT connection at a local fault server (custom environment: loopback http, never a KSeF host). */
    protected function useServer(FaultServer $server, string $driver = 'curl'): void
    {
        config([
            'ksef.environment' => 'custom',
            'ksef.connections.default.base_url' => $server->baseUrl() . '/v2',
            'ksef.http.driver' => $driver,
        ]);
        app(KsefManager::class)->purge();
        $this->app?->forgetInstance(KsefClient::class);
    }

    /** Stores a JWT pair for the default connection through the real cache storage (APP_KEY encrypted). */
    protected function seedTokens(?KsefClient $client = null): void
    {
        $client ??= app(KsefManager::class)->connection();
        $creds = $client->credentials();
        self::assertInstanceOf(KsefTokenCredentials::class, $creds);
        $storage = new CacheTokenStorage(app('cache')->store(), app('encrypter'), 'ksef');
        $storage->put($client->auth()->tenantKey($creds), new TokenPair(
            new SensitiveString(self::ACCESS),
            (new \DateTimeImmutable())->modify('+900 seconds'),
            new SensitiveString(self::REFRESH),
            (new \DateTimeImmutable())->modify('+1 day'),
        ), 86400);
    }

    /** Runs exactly one queued job through a real worker (database queue). */
    protected function work(): int
    {
        return Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 0, '--quiet' => true]);
    }

    protected function makeJobsAvailableNow(): void
    {
        DB::table('jobs')->update(['available_at' => time() - 1, 'reserved_at' => null]);
    }

    protected function logContents(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }
}
