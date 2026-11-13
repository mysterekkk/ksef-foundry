<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Unit;

use LuroWeb\KsefFoundry\Crypto\SessionKey;
use LuroWeb\KsefFoundry\Support\Redactor;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase
{
    public function testSensitiveStringNeverLeaks(): void
    {
        $s = new SensitiveString('super-secret-value');
        self::assertSame('[REDACTED]', (string) $s);
        self::assertStringNotContainsString('super-secret', (string) json_encode($s));
        self::assertStringNotContainsString('super-secret', print_r($s, true));
        ob_start();
        var_dump($s);
        self::assertStringNotContainsString('super-secret', (string) ob_get_clean());
        self::assertSame('super-secret-value', $s->reveal());
    }

    public function testSensitiveStringCannotBeSerialised(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new SensitiveString('x'));
    }

    public function testSessionKeyIsRedactedAndNotSerialisable(): void
    {
        $k = SessionKey::generate();
        ob_start();
        var_dump($k);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString(bin2hex($k->key()), $dump);
        self::assertStringNotContainsString(base64_encode($k->key()), $dump);
        $this->expectException(\LogicException::class);
        serialize($k);
    }

    public function testRedactorMasksTokensHeadersAndKeys(): void
    {
        $r = new Redactor();
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.c2lnbmF0dXJl';
        self::assertStringNotContainsString($jwt, $r->redactString('token ' . $jwt . ' end'));
        self::assertSame('Bearer [REDACTED]', $r->redactString('Bearer abc.def-ghi_123'));
        $json = '{"accessToken":"AAA","encryptedInvoiceContent":"BBB","other":"ok"}';
        $out = $r->redactString($json);
        self::assertStringNotContainsString('AAA', $out);
        self::assertStringNotContainsString('BBB', $out);
        self::assertStringContainsString('"other":"ok"', $out);
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\n-----END PRIVATE KEY-----";
        self::assertStringNotContainsString('MIIEvQ', $r->redactString('x ' . $pem . ' y'));
        $h = $r->redactHeaders(['Authorization' => 'Bearer zzz', 'Accept' => 'application/json']);
        self::assertSame('[REDACTED]', $h['Authorization']);
        self::assertSame('application/json', $h['Accept']);
        $a = $r->redactArray(['token' => 't', 'nested' => ['refreshToken' => 'r', 'x' => 1], 'ok' => 'fine']);
        self::assertSame('[REDACTED]', $a['token']);
        self::assertSame(['refreshToken' => '[REDACTED]', 'x' => 1], $a['nested']);
        self::assertSame('fine', $a['ok']);
    }
}
