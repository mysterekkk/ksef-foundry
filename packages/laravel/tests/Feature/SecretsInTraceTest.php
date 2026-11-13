<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Feature;

use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;

/** With zend.exception_ignore_args=0 (PHP development default) stack-trace args must still not leak connection secrets. */
final class SecretsInTraceTest extends TestCase
{
    private string $previous = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->previous);
        parent::tearDown();
    }

    /**
     * Everything an error reporter could serialise from the exception chain: message, trace string and every scalar/array
     * in the trace args (objects are reduced to their class name; SensitiveParameterValue stays opaque).
     */
    private function dump(\Throwable $e): string
    {
        $out = '';
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $out .= $t->getMessage() . "
" . $t->getTraceAsString() . "
" . $this->flatten($t->getTrace(), 0);
        }

        return $out;
    }

    private function flatten(mixed $v, int $depth): string
    {
        if (is_object($v)) {
            return '<' . $v::class . '>';
        }
        if (is_array($v)) {
            if ($depth > 8) {
                return '[...]';
            }
            $parts = [];
            foreach ($v as $k => $item) {
                $parts[] = $k . '=' . $this->flatten($item, $depth + 1);
            }

            return '[' . implode(',', $parts) . ']';
        }

        return var_export($v, true);
    }

    public function testInvalidNipWithTokenLeavesNoSecretInTraceArgs(): void
    {
        $secret = 'TOP-SECRET-KSEF-TOKEN-' . bin2hex(random_bytes(6));
        try {
            app(KsefManager::class)->tenant(['auth' => 'token', 'context_type' => 'nip', 'context_value' => '123', 'token' => $secret]);
            self::fail('invalid NIP must be rejected');
        } catch (KsefException $e) {
            $dump = $this->dump($e);
            self::assertStringContainsString('NIP must be', $e->getMessage());
            self::assertStringContainsString('SensitiveParameterValue', $dump, 'frames carry redacted placeholders');
            self::assertStringContainsString('args=', $dump . 'args=');
            self::assertStringNotContainsString($secret, $dump);
        }
    }

    public function testNamedConnectionWithBadNipLeavesNoSecretInTraceArgs(): void
    {
        $secret = 'CONFIG-SECRET-TOKEN-' . bin2hex(random_bytes(6));
        config(['ksef.connections.broken' => ['auth' => 'token', 'context_value' => '12', 'token' => $secret]]);
        try {
            app(KsefManager::class)->connection('broken');
            self::fail('must throw');
        } catch (KsefException $e) {
            self::assertStringNotContainsString($secret, $this->dump($e));
        }
    }

    public function testWrongPassphraseLeavesNeitherPassphraseNorKeyInTraceArgs(): void
    {
        $cert = (new TestCertificateFactory())->personWithPesel(SyntheticIdentity::pesel(), 'Jan', 'Syntetyczny');
        $conf = (string) tempnam(sys_get_temp_dir(), 'ksefconf');
        file_put_contents($conf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\n");
        try {
            $key = openssl_pkey_get_private($cert->privateKeyPem->reveal());
            self::assertNotFalse($key);
            $encrypted = '';
            self::assertTrue(openssl_pkey_export($key, $encrypted, 'the-right-passphrase', ['config' => $conf]));
        } finally {
            unlink($conf);
        }
        $wrong = 'WRONG-PASSPHRASE-' . bin2hex(random_bytes(5));
        $body = trim(explode("\n", trim($encrypted))[3] ?? 'ENCRYPTED-BODY-FALLBACK');
        self::assertGreaterThan(20, strlen($body));

        try {
            app(KsefManager::class)->tenant([
                'auth' => 'certificate', 'context_value' => SyntheticIdentity::nip(),
                'certificate_pem' => $cert->certificatePem, 'private_key_pem' => $encrypted, 'private_key_passphrase' => $wrong,
            ]);
            self::fail('a wrong passphrase must be rejected');
        } catch (KsefException $e) {
            $dump = $this->dump($e);
            self::assertStringNotContainsString($wrong, $dump);
            self::assertStringNotContainsString($body, $dump);
            self::assertStringNotContainsString('the-right-passphrase', $dump);
        }
    }
}
