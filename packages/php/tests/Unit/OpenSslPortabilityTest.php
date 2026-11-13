<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Key and certificate generation must not depend on the system openssl.cnf (missing on some PHP builds / Windows
 * installs) and must be warning-free on PHP 8.2 (EC keys need private_key_bits there).
 */
final class OpenSslPortabilityTest extends TestCase
{
    /** @return list<string> */
    private function leftovers(): array
    {
        return glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ksefcnf*') ?: [];
    }

    /** @return array{0: int, 1: string} exit code and combined output */
    private function runChild(?string $opensslConf): array
    {
        $script = tempnam(sys_get_temp_dir(), 'ksefchild');
        self::assertNotFalse($script);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($script, '<?php
error_reporting(E_ALL);
ini_set("display_errors", "1");
require ' . var_export($autoload, true) . ';
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;
$f = new TestCertificateFactory();
foreach ([false, true] as $ec) {
    $c = $f->personWithPesel("44051401359", ec: $ec);
    $p = openssl_x509_parse($c->certificatePem);
    if ($p === false || $p["subject"]["serialNumber"] !== "PNOPL-44051401359") { echo "BAD"; exit(3); }
}
echo "OK";
');
        $env = array_merge(getenv() ?: [], $opensslConf === null ? [] : ['OPENSSL_CONF' => $opensslConf]);
        if ($opensslConf === null) {
            unset($env['OPENSSL_CONF'], $env['SSLEAY_CONF']);
        }
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        unlink($script);

        return [$code, trim($out)];
    }

    public function testKeyGenerationWorksWithANonexistentSystemOpensslConf(): void
    {
        $before = $this->leftovers();
        [$code, $out] = $this->runChild('/nonexistent/dir/openssl.cnf');
        self::assertStringEndsWith('OK', $out);
        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('Private key length', $out);
        self::assertSame($before, $this->leftovers(), 'temporary openssl.cnf files are always removed');
    }

    public function testKeyGenerationWorksWithoutAnyOpensslConfEnvironment(): void
    {
        [$code, $out] = $this->runChild(null);
        self::assertStringEndsWith('OK', $out);
        self::assertSame(0, $code, $out);
    }

    public function testGeneratedConfigIsOwnerOnlyAndRemoved(): void
    {
        $seen = null;
        \LuroWeb\KsefFoundry\Testing\OpenSslConfig::run(static function (string $path) use (&$seen): void {
            $seen = ['path' => $path, 'perms' => fileperms($path) & 0777, 'content' => file_get_contents($path)];
        });
        self::assertIsArray($seen);
        self::assertFileDoesNotExist($seen['path']);
        self::assertStringContainsString('default_bits', (string) $seen['content']);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, $seen['perms']);
        }
    }

    public function testConfigIsRemovedEvenWhenTheWorkFails(): void
    {
        $path = null;
        try {
            \LuroWeb\KsefFoundry\Testing\OpenSslConfig::run(static function (string $p) use (&$path): void {
                $path = $p;

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            self::assertIsString($path);
            self::assertFileDoesNotExist($path);
        }
    }
}
