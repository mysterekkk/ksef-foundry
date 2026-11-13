<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Unit;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use LuroWeb\KsefFoundry\Auth\TokenPair;
use LuroWeb\KsefFoundry\Laravel\Auth\CacheTokenStorage;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/** Real Illuminate cache repositories (array + file) and the real Encrypter. */
final class CacheTokenStorageTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            (new Filesystem())->deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function pair(int $accessTtl = 900, int $refreshTtl = 86400): TokenPair
    {
        return new TokenPair(
            new SensitiveString(self::ACCESS),
            (new \DateTimeImmutable())->modify("+$accessTtl seconds"),
            new SensitiveString(self::REFRESH),
            (new \DateTimeImmutable())->modify("+$refreshTtl seconds"),
        );
    }

    private function fileCache(): Repository
    {
        $this->dir = sys_get_temp_dir() . '/ksef-cache-' . bin2hex(random_bytes(4));

        return new Repository(new FileStore(new Filesystem(), $this->dir));
    }

    private function encrypter(): Encrypter
    {
        return new Encrypter(random_bytes(32), 'aes-256-cbc');
    }

    public function testRoundTripOnArrayStore(): void
    {
        $storage = new CacheTokenStorage(app('cache')->store('array'), $this->encrypter(), 'ksef');
        self::assertNull($storage->get('k1'));
        $storage->put('k1', $this->pair(), 3600);
        $got = $storage->get('k1');
        self::assertNotNull($got);
        self::assertSame(self::ACCESS, $got->accessToken->reveal());
        self::assertSame(self::REFRESH, $got->refreshToken->reveal());
        $storage->delete('k1');
        self::assertNull($storage->get('k1'));
    }

    public function testEncryptedAtRestOnFileStoreWithRealFiles(): void
    {
        $cache = $this->fileCache();
        $storage = new CacheTokenStorage($cache, $this->encrypter(), 'ksef');
        $storage->put('tenant-a', $this->pair(), 3600);

        $raw = $cache->get('ksef:jwt:tenant-a');
        self::assertIsString($raw);
        self::assertStringNotContainsString(self::ACCESS, $raw);
        self::assertStringNotContainsString(self::REFRESH, $raw);
        self::assertStringNotContainsString('accessToken', $raw);
        // and not in the raw cache files on disk either
        $files = (new Filesystem())->allFiles($this->dir);
        self::assertNotEmpty($files);
        foreach ($files as $f) {
            $content = (string) file_get_contents($f->getPathname());
            self::assertStringNotContainsString('SIGNATURE-VALUE', $content);
            self::assertStringNotContainsString('eyJhbGci', $content);
        }
    }

    public function testWithoutEncrypterStoresJsonAndSaysSo(): void
    {
        $cache = app('cache')->store('array');
        $storage = new CacheTokenStorage($cache, null, 'plain');
        $storage->put('k', $this->pair(), 3600);
        $raw = $cache->get('plain:jwt:k');
        self::assertIsString($raw);
        self::assertStringContainsString(self::ACCESS, $raw, 'encryption off is an explicit, documented opt-out');
    }

    public function testTtlIsClampedToRefreshExpiryAndEntryExpires(): void
    {
        $cache = $this->fileCache();
        $storage = new CacheTokenStorage($cache, $this->encrypter(), 'ksef');
        // caller asks for an hour, the refresh token lives only 1 second: the entry must not outlive it
        $storage->put('short', $this->pair(900, 1), 3600);
        self::assertNotNull($storage->get('short'));
        sleep(3);
        self::assertNull($storage->get('short'), 'entry expired with the refresh token');
        self::assertNull($cache->get('ksef:jwt:short'));
    }

    public function testTenantsAreIsolatedByKeyAndPrefix(): void
    {
        $cache = app('cache')->store('array');
        $enc = $this->encrypter();
        $a = new CacheTokenStorage($cache, $enc, 'ksef');
        $b = new CacheTokenStorage($cache, $enc, 'ksef');
        $other = new CacheTokenStorage($cache, $enc, 'another-app');
        $a->put('tenant-a', $this->pair(), 3600);
        self::assertNotNull($a->get('tenant-a'));
        self::assertNull($b->get('tenant-b'));
        self::assertNull($other->get('tenant-a'), 'a different prefix is a different namespace');
        $a->delete('tenant-b');
        self::assertNotNull($a->get('tenant-a'), 'deleting another tenant does not touch this one');
    }

    public function testEntryEncryptedWithAnotherKeyIsAMissAndIsRemoved(): void
    {
        $cache = app('cache')->store('array');
        (new CacheTokenStorage($cache, $this->encrypter(), 'ksef'))->put('k', $this->pair(), 3600);
        $rotated = new CacheTokenStorage($cache, $this->encrypter(), 'ksef'); // "APP_KEY rotated"
        self::assertNull($rotated->get('k'));
        self::assertNull($cache->get('ksef:jwt:k'));
    }

    public function testGarbageInCacheIsAMiss(): void
    {
        $cache = app('cache')->store('array');
        $cache->put('ksef:jwt:g', 'not-a-payload', 60);
        self::assertNull((new CacheTokenStorage($cache, $this->encrypter(), 'ksef'))->get('g'));
        $cache->put('ksef:jwt:g2', '{"a":1}', 60);
        self::assertNull((new CacheTokenStorage($cache, null, 'ksef'))->get('g2'));
    }
}
