<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Contract;

use LuroWeb\KsefFoundry\Crypto\Crypto;
use LuroWeb\KsefFoundry\Crypto\PublicKey;
use LuroWeb\KsefFoundry\Crypto\PublicKeyProvider;
use LuroWeb\KsefFoundry\Crypto\SessionKey;
use LuroWeb\KsefFoundry\Exception\EncryptionException;
use LuroWeb\KsefFoundry\Support\X509;
use LuroWeb\KsefFoundry\Tests\Support\KsefLikeKeys;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    /** Expected values computed independently with the openssl CLI (see test docblock in README of tests). */
    private const KA_PLAIN = "KSeF Foundry known-answer test: za\u{017C}\u{00F3}\u{0142}\u{0107} g\u{0119}\u{015B}l\u{0105} ja\u{017A}\u{0144}";
    private const KA_KEY_HEX = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const KA_IV_HEX = '0f0e0d0c0b0a09080706050403020100';
    private const KA_CIPHER_B64 = 'y2XdbPAfHUn9cn68axJcoF55UAo4HPOI2g6nPXoxhj2YK5JRLcp+Kt9cs6FxctdfpFgGIcxPApJZe4PjXxVusg==';
    private const KA_PLAIN_SHA = 'E+njT9+U6nK2lbCtQQGPEV6xoaBXLz4dhjC0qgqH1Hc=';
    private const KA_CIPHER_SHA = 'DRQi41EI/wLU+wYhRgL7AXNq4W1sq+1IlUl4VUUD3mg=';

    public function testAesKnownAnswerAndInvoiceMetadata(): void
    {
        $c = new Crypto();
        $key = new SessionKey((string) hex2bin(self::KA_KEY_HEX), (string) hex2bin(self::KA_IV_HEX));
        self::assertSame(58, strlen(self::KA_PLAIN));
        $enc = $c->encryptInvoice(self::KA_PLAIN, $key);
        self::assertSame(self::KA_CIPHER_B64, $enc->encryptedInvoiceContent);
        self::assertSame(self::KA_PLAIN_SHA, $enc->invoiceHash);
        self::assertSame(58, $enc->invoiceSize);
        self::assertSame(self::KA_CIPHER_SHA, $enc->encryptedInvoiceHash);
        self::assertSame(64, $enc->encryptedInvoiceSize, 'PKCS#7 pads to the next 16-byte block');
        self::assertSame(self::KA_PLAIN, $c->aes256CbcDecrypt((string) base64_decode($enc->encryptedInvoiceContent, true), $key));
        self::assertSame(44, strlen($enc->invoiceHash));
    }

    public function testPaddingBoundaries(): void
    {
        $c = new Crypto();
        $key = SessionKey::generate();
        foreach ([1 => 16, 15 => 16, 16 => 32, 17 => 32, 31 => 32, 32 => 48] as $plain => $cipher) {
            self::assertSame($cipher, $c->encryptInvoice(str_repeat('x', $plain), $key)->encryptedInvoiceSize, "plain $plain");
        }
        $this->expectException(EncryptionException::class);
        $c->encryptInvoice('', $key);
    }

    public function testSha256Base64KnownAnswer(): void
    {
        self::assertSame('ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0=', (new Crypto())->sha256Base64('abc'));
    }

    public function testSessionKeySizesAndUniqueness(): void
    {
        $a = SessionKey::generate();
        $b = SessionKey::generate();
        self::assertSame(32, strlen($a->key()));
        self::assertSame(16, strlen($a->iv()));
        self::assertNotSame($a->key(), $b->key());
        self::assertNotSame($a->iv(), $b->iv());
        $this->expectException(EncryptionException::class);
        new SessionKey('short', 'short');
    }

    public function testRsaOaepSha256RoundTripAndTokenFormat(): void
    {
        $keys = new KsefLikeKeys();
        $entry = json_decode($keys->listJson(['KsefTokenEncryption']), true)[0];
        $pub = PublicKeyProvider::parseEntry($entry);
        $c = new Crypto();
        $sk = SessionKey::generate();
        $cipher = (string) base64_decode($c->encryptSessionKey($sk, $pub), true);
        self::assertSame(256, strlen($cipher), 'RSA-2048 block');
        self::assertSame($sk->key(), $c->rsaOaepSha256Decrypt($cipher, $keys->privateKeyPem));
        self::assertNotSame($cipher, (string) base64_decode($c->encryptSessionKey($sk, $pub), true), 'OAEP is randomised');

        $tokenCipher = (string) base64_decode($c->encryptKsefToken('MY-TOKEN', 1_700_000_000_123, $pub), true);
        self::assertSame('MY-TOKEN|1700000000123', $c->rsaOaepSha256Decrypt($tokenCipher, $keys->privateKeyPem));
    }

    public function testRsaOaepIsReallySha256NotSha1(): void
    {
        // ext-openssl can only decrypt OAEP with SHA-1: it must FAIL on our SHA-256 ciphertext.
        $keys = new KsefLikeKeys();
        $pub = PublicKeyProvider::parseEntry(json_decode($keys->listJson(['SymmetricKeyEncryption']), true)[0]);
        $cipher = (new Crypto())->rsaOaepSha256Encrypt('secret', $pub);
        $priv = openssl_pkey_get_private($keys->privateKeyPem);
        self::assertNotFalse($priv);
        $out = '';
        $ok = openssl_private_decrypt($cipher, $out, $priv, OPENSSL_PKCS1_OAEP_PADDING);
        self::assertFalse($ok, 'SHA-1 OAEP decryption must not succeed on SHA-256 OAEP data');
    }

    public function testRsaOaepSha256CrossCheckWithOpensslCli(): void
    {
        $bin = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where openssl 2>NUL' : 'command -v openssl'));
        if ($bin === '') {
            self::markTestSkipped('openssl CLI not found: independent OAEP-SHA256 cross-check NOT run');
        }
        $bin = strtok($bin, "\r\n") ?: 'openssl';
        $keys = new KsefLikeKeys();
        $pub = PublicKeyProvider::parseEntry(json_decode($keys->listJson(['SymmetricKeyEncryption']), true)[0]);
        $dir = sys_get_temp_dir() . '/ksef-oaep-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/k.pem', $keys->privateKeyPem);
            file_put_contents($dir . '/c.bin', (new Crypto())->rsaOaepSha256Encrypt('independent-check-0123456789', $pub));
            $cmd = sprintf(
                '"%s" pkeyutl -decrypt -inkey %s -in %s -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>&1',
                $bin,
                escapeshellarg($dir . '/k.pem'),
                escapeshellarg($dir . '/c.bin')
            );
            $out = (string) shell_exec($cmd);
            self::assertSame('independent-check-0123456789', $out);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    public function testPublicKeyEntryValidation(): void
    {
        $keys = new KsefLikeKeys();
        $entry = json_decode($keys->listJson(['SymmetricKeyEncryption']), true)[0];
        $ok = PublicKeyProvider::parseEntry($entry);
        self::assertSame($keys->publicKeyId, $ok->publicKeyId);
        self::assertSame(2048, $ok->rsaBits);
        self::assertInstanceOf(PublicKey::class, $ok);

        $wrongId = $entry;
        $wrongId['publicKeyId'] = base64_encode(hash('sha256', 'other', true));
        $weak = (new KsefLikeKeys(1024));
        $weakEntry = json_decode($weak->listJson(['SymmetricKeyEncryption']), true)[0];
        $garbage = $entry;
        $garbage['certificate'] = base64_encode('not a certificate');
        foreach (['wrong publicKeyId' => $wrongId, 'RSA-1024' => $weakEntry, 'garbage certificate' => $garbage] as $name => $bad) {
            try {
                PublicKeyProvider::parseEntry($bad);
                self::fail($name . ' must be rejected');
            } catch (EncryptionException) {
                $this->addToAssertionCount(1);
            }
        }
        $cert = X509::read("-----BEGIN CERTIFICATE-----\n" . chunk_split($keys->certificateDerBase64, 64, "\n") . "-----END CERTIFICATE-----\n");
        self::assertSame($keys->publicKeyId, base64_encode(hash('sha256', X509::publicKey($cert)['der'], true)));
    }
}
