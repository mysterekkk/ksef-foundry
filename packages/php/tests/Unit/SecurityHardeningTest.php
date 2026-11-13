<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Unit;

use LuroWeb\KsefFoundry\Auth\AuthTokenRequestBuilder;
use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Context\ContextIdentifierType;
use LuroWeb\KsefFoundry\Context\SubjectIdentifierType;
use LuroWeb\KsefFoundry\Crypto\Crypto;
use LuroWeb\KsefFoundry\Crypto\SessionKey;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use LuroWeb\KsefFoundry\Support\PageGuard;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Support\X509;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase
{
    private string|false $previousIgnoreArgs = '';

    protected function setUp(): void
    {
        // Default of PHP without php.ini (e.g. Docker): stack traces keep call arguments.
        $this->previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->previousIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
        }
    }

    private function encryptedKey(string $pem, string $passphrase): string
    {
        $conf = tempnam(sys_get_temp_dir(), 'c');
        self::assertNotFalse($conf);
        file_put_contents($conf, "[req]
distinguished_name = dn
prompt = no
[dn]
");
        $key = openssl_pkey_get_private($pem);
        self::assertNotFalse($key);
        $out = null;
        openssl_pkey_export($key, $out, $passphrase, ['config' => $conf]);
        unlink($conf);
        self::assertIsString($out);

        return $out;
    }

    private function assertNoSecretIn(\Throwable $e, string ...$secrets): void
    {
        $dumps = [print_r($e, true), $e->getTraceAsString(), (string) json_encode($e instanceof KsefException ? $e->diagnostics() : [])];
        ob_start();
        var_dump($e);
        $dumps[] = (string) ob_get_clean();
        $dumps[] = var_export($e->getTrace(), true);
        foreach ($dumps as $dump) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $dump);
            }
        }
        $sawSensitive = false;
        $walk = static function (array $trace) use (&$sawSensitive): void {
            foreach ($trace as $frame) {
                foreach ($frame['args'] ?? [] as $arg) {
                    if ($arg instanceof \SensitiveParameterValue) {
                        $sawSensitive = true;
                    }
                }
            }
        };
        $walk($e->getTrace());
        self::assertTrue($sawSensitive, 'trace args must hold SensitiveParameterValue for the secret parameters');
    }

    public function testWrongPassphraseNeverLeaksIntoTraceArguments(): void
    {
        $cert = (new \LuroWeb\KsefFoundry\Testing\TestCertificateFactory())->personWithPesel(SyntheticIdentity::pesel());
        $pem = $this->encryptedKey($cert->privateKeyPem->reveal(), 'right-PASSPHRASE-123');
        try {
            X509::readPrivateKey($pem, 'wrong-PASSPHRASE-456');
            self::fail('wrong passphrase must fail');
        } catch (\LuroWeb\KsefFoundry\Exception\CertificateException $e) {
            $this->assertNoSecretIn($e, 'wrong-PASSPHRASE-456', 'right-PASSPHRASE-123', substr($pem, 40, 60));
        }
    }

    public function testCertificateCredentialsWithWrongPassphraseDoNotLeak(): void
    {
        $cert = (new \LuroWeb\KsefFoundry\Testing\TestCertificateFactory())->personWithPesel(SyntheticIdentity::pesel());
        $enc = $this->encryptedKey($cert->privateKeyPem->reveal(), 'correct-HORSE-battery');
        try {
            new CertificateCredentials(
                $cert->certificatePem,
                new SensitiveString($enc),
                ContextIdentifier::nip(SyntheticIdentity::nip()),
                SubjectIdentifierType::CertificateSubject,
                new SensitiveString('wrong-HORSE-battery'),
            );
            self::fail();
        } catch (\LuroWeb\KsefFoundry\Exception\CertificateException $e) {
            $this->assertNoSecretIn($e, 'wrong-HORSE-battery', 'correct-HORSE-battery', substr($enc, 60, 60));
        }
    }

    public function testCryptoPlaintextAndKeysAreMaskedInTraces(): void
    {
        $secret = 'PLAINTEXT-SESSION-KEY-MATERIAL';
        try {
            (new Crypto())->rsaOaepSha256Encrypt($secret, 'not a public key at all');
            self::fail();
        } catch (\LuroWeb\KsefFoundry\Exception\EncryptionException $e) {
            $this->assertNoSecretIn($e, $secret);
        }
        try {
            (new Crypto())->rsaOaepSha256Decrypt('CIPHERTEXT-SECRET-BYTES', 'not a private key PEM');
            self::fail();
        } catch (\LuroWeb\KsefFoundry\Exception\EncryptionException $e) {
            $this->assertNoSecretIn($e, 'CIPHERTEXT-SECRET-BYTES', 'not a private key PEM');
        }
        $key = SessionKey::generate();
        try {
            (new Crypto())->encryptInvoice('', $key);
            self::fail();
        } catch (\LuroWeb\KsefFoundry\Exception\EncryptionException $e) {
            $this->assertNoSecretIn($e, base64_encode($key->key()), bin2hex($key->key()));
        }
    }

    public function testInvoiceContentIsMaskedInValidationExceptionTraces(): void
    {
        $xml = '<Faktura>CONFIDENTIAL-INVOICE-CONTENT';
        try {
            (new XmlValidator())->assertValid($xml);
            self::fail();
        } catch (\LuroWeb\KsefFoundry\Exception\XmlValidationException $e) {
            $this->assertNoSecretIn($e, 'CONFIDENTIAL-INVOICE-CONTENT');
        }
    }

    public function testEmptyKsefTokenFailsWithoutLeaking(): void
    {
        try {
            new KsefTokenCredentials(new SensitiveString(''), ContextIdentifier::nip(SyntheticIdentity::nip()));
            self::fail();
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString('SensitiveString', print_r($e->getMessage(), true));
        }
    }

    /** @return array<string, array{string}> */
    public static function hostileChallenges(): array
    {
        return [
            'tag injection' => ['20250604-CR-461EA5B000-537A6BA15D-D7</Challenge><x/>'],
            'entity' => ['&lt;20250604-CR-461EA5B000-537A6BA15D-D'],
            'cdata' => ['<![CDATA[20250604-CR-461EA5B000-537A6BA15D-D7]]>'],
            'newline suffix' => ["20250604-CR-461EA5B000-537A6BA15D-D7\n"],
            'lowercase' => ['20250604-cr-461ea5b000-537a6ba15d-d7'],
            'empty' => [''],
        ];
    }

    #[DataProvider('hostileChallenges')]
    public function testAuthTokenRequestRejectsHostileChallenge(string $challenge): void
    {
        $this->expectException(ConfigurationException::class);
        (new AuthTokenRequestBuilder())->build($challenge, ContextIdentifier::nip('7980332920'), SubjectIdentifierType::CertificateSubject);
    }

    public function testAuthTokenRequestIsWellFormedAndXsdValidForEveryContextType(): void
    {
        $xsd = dirname(__DIR__, 4) . '/schemas/xsd/auth/schemat_auth_v2-1.xsd';
        $challenge = '20250604-CR-461EA5B000-537A6BA15D-D7';
        foreach (
            [
            ContextIdentifier::nip('7980332920'),
            ContextIdentifier::internalId('7980332920-12345'),
            ContextIdentifier::peppolId('PPL123456'),
            ] as $ctx
        ) {
            $xml = (new AuthTokenRequestBuilder())->build($challenge, $ctx, SubjectIdentifierType::CertificateFingerprint);
            $dom = new \DOMDocument();
            self::assertTrue($dom->loadXML($xml));
            self::assertSame($ctx->value, $dom->getElementsByTagNameNS(AuthTokenRequestBuilder::NAMESPACE_URI, $ctx->type->value)->item(0)?->textContent);
            // PeppolId is not XSD-checked: the official TPeppolId pattern contains literal ^ and $ (XSD anchors itself).
            if (is_file($xsd) && $ctx->type !== ContextIdentifierType::PeppolId) {
                self::assertTrue($dom->schemaValidate($xsd), $ctx->type->value);
            }
        }
    }

    /** @return array<string, array{ContextIdentifierType, string, bool}> */
    public static function contextValues(): array
    {
        return [
            'nip ok' => [ContextIdentifierType::Nip, '7980332920', true],
            'nip with markup' => [ContextIdentifierType::Nip, '7980332920</Nip><Nip>1', false],
            'nip ampersand' => [ContextIdentifierType::Nip, '79803&amp;920', false],
            'nip zero start' => [ContextIdentifierType::Nip, '0123456789', false],
            'nip trailing newline' => [ContextIdentifierType::Nip, "7980332920\n", false],
            'internal ok' => [ContextIdentifierType::InternalId, '7980332920-12345', true],
            'internal bad' => [ContextIdentifierType::InternalId, '7980332920-1234', false],
            'vatue DE ok' => [ContextIdentifierType::NipVatUe, '7980332920-DE123456789', true],
            'vatue XI ok' => [ContextIdentifierType::NipVatUe, '7980332920-XIGD123', true],
            'vatue wrong length' => [ContextIdentifierType::NipVatUe, '7980332920-DE12345', false],
            'vatue unknown country' => [ContextIdentifierType::NipVatUe, '7980332920-ZZ123456789', false],
            'vatue newline' => [ContextIdentifierType::NipVatUe, "7980332920-DE123456789\n", false],
            'peppol ok' => [ContextIdentifierType::PeppolId, 'PPL123456', true],
            'peppol bad' => [ContextIdentifierType::PeppolId, 'ppl123456', false],
            'peppol markup' => [ContextIdentifierType::PeppolId, 'PPL12<456', false],
            'empty' => [ContextIdentifierType::Nip, '', false],
        ];
    }

    #[DataProvider('contextValues')]
    public function testContextIdentifierOfValidatesByType(ContextIdentifierType $type, string $value, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(ConfigurationException::class);
        }
        $id = ContextIdentifier::of($type, $value);
        self::assertSame($value, $id->value);
        self::assertSame($type, $id->type);
    }

    public function testPageGuardStopsRepeatedTokensAndRunawayPageCounts(): void
    {
        $g = new PageGuard(3);
        $g->advance('a');
        $g->advance('b');
        $g->advance(null);
        $this->expectException(KsefException::class);
        $g->advance('c');
    }

    public function testPageGuardRejectsRepeatedToken(): void
    {
        $g = new PageGuard();
        $g->advance('same');
        $this->expectExceptionMessage('repeated a continuation token');
        $g->advance('same');
    }
}
