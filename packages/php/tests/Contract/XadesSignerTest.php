<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Contract;

use LuroWeb\KsefFoundry\Auth\AuthTokenRequestBuilder;
use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Auth\XadesSigner;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Context\SubjectIdentifierType;
use LuroWeb\KsefFoundry\Exception\CertificateException;
use LuroWeb\KsefFoundry\Exception\XmlValidationException;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Support\X509;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;
use LuroWeb\KsefFoundry\Tests\Support\XadesVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class XadesSignerTest extends TestCase
{
    private const CHALLENGE = '20250604-CR-461EA5B000-537A6BA15D-D7';

    private function unsigned(ContextIdentifier $ctx): string
    {
        return (new AuthTokenRequestBuilder())->build(self::CHALLENGE, $ctx, SubjectIdentifierType::CertificateSubject);
    }

    private function credentials(bool $ec, ContextIdentifier $ctx): CertificateCredentials
    {
        $c = (new TestCertificateFactory())->personWithPesel(SyntheticIdentity::pesel(), ec: $ec);

        return new CertificateCredentials($c->certificatePem, $c->privateKeyPem, $ctx);
    }

    public function testUnsignedRequestValidatesAgainstOfficialXsdForAllContextTypes(): void
    {
        $xsd = dirname(__DIR__, 4) . '/schemas/xsd/auth/schemat_auth_v2-1.xsd';
        if (!is_file($xsd)) {
            self::markTestSkipped('schemas/xsd/auth/schemat_auth_v2-1.xsd not available outside the monorepo');
        }
        // NipVatUe is not covered: the official XSD pattern for TNipVatUE ends in a literal '$' (XSD patterns are
        // implicitly anchored), so no value can match it. See docs/dev/findings-live-test.md.
        $contexts = [
            ContextIdentifier::nip('7980332920'),
            ContextIdentifier::internalId('7980332920-12345'),
        ];
        foreach ($contexts as $ctx) {
            foreach (SubjectIdentifierType::cases() as $sit) {
                $dom = new \DOMDocument();
                $dom->loadXML((new AuthTokenRequestBuilder())->build(self::CHALLENGE, $ctx, $sit));
                self::assertTrue($dom->schemaValidate($xsd), $ctx->type->value . '/' . $sit->value);
            }
        }
    }

    /** @return array<string, array{bool}> */
    public static function keyTypes(): array
    {
        return ['RSA-2048' => [false], 'ECDSA P-256' => [true]];
    }

    #[DataProvider('keyTypes')]
    public function testSignatureSelfVerifies(bool $ec): void
    {
        $ctx = ContextIdentifier::nip(SyntheticIdentity::nip());
        $creds = $this->credentials($ec, $ctx);
        $signed = (new XadesSigner())->sign($this->unsigned($ctx), $creds);
        self::assertSame([], (new XadesVerifier())->verify($signed));
        self::assertStringContainsString($ec ? 'ecdsa-sha256' : 'rsa-sha256', $signed);
        self::assertStringNotContainsString('PRIVATE KEY', $signed);
    }

    #[DataProvider('keyTypes')]
    public function testStructureMatchesAcceptedProfile(bool $ec): void
    {
        $ctx = ContextIdentifier::nip(SyntheticIdentity::nip());
        $creds = $this->credentials($ec, $ctx);
        $signed = (new XadesSigner(fn (): int => 1_800_000_000))->sign($this->unsigned($ctx), $creds);
        $dom = new \DOMDocument();
        $dom->loadXML($signed);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('a', AuthTokenRequestBuilder::NAMESPACE_URI);
        $xp->registerNamespace('ds', XadesSigner::DS);
        $xp->registerNamespace('xades', XadesSigner::XADES);

        self::assertSame(1, self::nodeCount($xp, '/a:AuthTokenRequest/ds:Signature'), 'enveloped: Signature is a child of the root');
        self::assertSame(2, self::nodeCount($xp, '//ds:SignedInfo/ds:Reference'));
        self::assertSame('', self::first($xp, '//ds:Reference[1]/@URI')?->nodeValue);
        self::assertSame(
            [XadesSigner::ENVELOPED, XadesSigner::EXC_C14N],
            array_map(static fn (\DOMNode $n): string => (string) $n->nodeValue, iterator_to_array($xp->query('//ds:Reference[1]/ds:Transforms/ds:Transform/@Algorithm') ?: [])),
        );
        self::assertSame('http://uri.etsi.org/01903#SignedProperties', self::first($xp, '//ds:Reference[2]/@Type')?->nodeValue);
        foreach ($xp->query('//ds:DigestMethod/@Algorithm') ?: [] as $a) {
            self::assertSame(XadesSigner::DIGEST_SHA256, $a->nodeValue);
        }
        self::assertSame('2027-01-15T08:00:00Z', self::first($xp, '//xades:SigningTime')?->nodeValue);

        $certDer = base64_decode((string) self::first($xp, '//ds:X509Certificate')?->nodeValue, true);
        self::assertNotFalse($certDer);
        self::assertSame(
            base64_encode(hash('sha256', (string) $certDer, true)),
            self::first($xp, '//xades:CertDigest/ds:DigestValue')?->nodeValue,
            'SigningCertificate digest is SHA-256 of the embedded certificate',
        );
        $cert = openssl_x509_read("-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode((string) $certDer), 64, "\n") . "-----END CERTIFICATE-----\n");
        self::assertNotFalse($cert);
        $serialDec = self::first($xp, '//xades:IssuerSerial/ds:X509SerialNumber')?->nodeValue;
        $parsed = openssl_x509_parse($cert);
        self::assertIsArray($parsed);
        self::assertSame(self::hexToDec((string) ($parsed['serialNumberHex'] ?? '0')), $serialDec);

        if ($ec) {
            $sv = base64_decode((string) self::first($xp, '//ds:SignatureValue')?->nodeValue, true);
            self::assertSame(64, strlen((string) $sv), 'ECDSA P-256 SignatureValue is fixed R||S (64 bytes), not DER');
        }
    }

    public function testTamperingIsDetected(): void
    {
        $ctx = ContextIdentifier::nip(SyntheticIdentity::nip());
        $signed = (new XadesSigner())->sign($this->unsigned($ctx), $this->credentials(false, $ctx));
        $tampered = str_replace(self::CHALLENGE, '20250604-CR-461EA5B000-537A6BA15D-D8', $signed);
        $problems = (new XadesVerifier())->verify($tampered);
        self::assertNotSame([], $problems);
        self::assertStringContainsString('digest mismatch', implode(';', $problems));

        $sp = preg_replace('#<xades:SigningTime>[^<]*</xades:SigningTime>#', '<xades:SigningTime>2000-01-01T00:00:00Z</xades:SigningTime>', $signed);
        self::assertNotSame([], (new XadesVerifier())->verify((string) $sp));

        $sigTampered = preg_replace('#(<ds:SignatureValue>)(.)#', '${1}A', $signed) ?? '';
        if ($sigTampered !== $signed) {
            self::assertNotSame([], (new XadesVerifier())->verify($sigTampered));
        }
    }

    public function testRejectsDoctypeMalformedAndDoubleSigning(): void
    {
        $ctx = ContextIdentifier::nip(SyntheticIdentity::nip());
        $creds = $this->credentials(false, $ctx);
        $signer = new XadesSigner();
        foreach (['<a>', '<!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>'] as $bad) {
            try {
                $signer->sign($bad, $creds);
                self::fail('must reject');
            } catch (XmlValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(XmlValidationException::class);
        $signer->sign($signer->sign($this->unsigned($ctx), $creds), $creds);
    }

    public function testGeneratedTestCertificateHasKsefSubjectFields(): void
    {
        foreach ([[false, 'PNOPL', '44051401359'], [true, 'TINPL', '7980332920']] as [$ec, $prefix, $id]) {
            $c = (new TestCertificateFactory())->personal('Jan', 'Kowalski', $prefix, $id, 'Jan Kowalski', $ec);
            $cert = openssl_x509_read($c->certificatePem);
            self::assertNotFalse($cert);
            $p = openssl_x509_parse($cert);
            self::assertIsArray($p);
            self::assertSame('PL', $p['subject']['C']);
            self::assertSame('Jan Kowalski', $p['subject']['CN']);
            self::assertSame('Jan', $p['subject']['GN'], 'givenName 2.5.4.42');
            self::assertSame('Kowalski', $p['subject']['SN'], 'surname 2.5.4.4');
            self::assertSame($prefix . '-' . $id, $p['subject']['serialNumber'], 'serialNumber 2.5.4.5');
            self::assertSame($p['subject'], $p['issuer'], 'self-signed');
            $info = X509::publicKey($cert);
            self::assertSame($ec ? OPENSSL_KEYTYPE_EC : OPENSSL_KEYTYPE_RSA, $info['type']);
            self::assertGreaterThanOrEqual($ec ? 256 : 2048, $info['bits']);
            self::assertMatchesRegularExpression($prefix === 'PNOPL' ? '/(PNOPL|PESEL).*?\d{11}/' : '/(TINPL|NIP).*?\d{10}/', $p['subject']['serialNumber']);
        }
    }

    public function testCredentialsValidation(): void
    {
        $ctx = ContextIdentifier::nip(SyntheticIdentity::nip());
        $a = (new TestCertificateFactory())->personWithPesel(SyntheticIdentity::pesel());
        $b = (new TestCertificateFactory())->personWithPesel(SyntheticIdentity::pesel());
        try {
            new CertificateCredentials($a->certificatePem, $b->privateKeyPem, $ctx);
            self::fail('mismatched key');
        } catch (CertificateException $e) {
            self::assertStringContainsString('does not match', $e->getMessage());
        }
        foreach ([['garbage', $a->privateKeyPem], [$a->certificatePem, new SensitiveString('garbage')]] as [$pem, $key]) {
            try {
                new CertificateCredentials($pem, $key, $ctx);
                self::fail('garbage');
            } catch (CertificateException) {
                $this->addToAssertionCount(1);
            }
        }
        $ok = new CertificateCredentials($a->certificatePem, $a->privateKeyPem, $ctx);
        ob_start();
        var_dump($ok);
        self::assertStringNotContainsString('PRIVATE', (string) ob_get_clean());
        self::assertNotSame($ok->fingerprint(), (new CertificateCredentials($b->certificatePem, $b->privateKeyPem, $ctx))->fingerprint());
    }

    public function testExpiredCertificateIsRefused(): void
    {
        $conf = tempnam(sys_get_temp_dir(), 'c');
        self::assertNotFalse($conf);
        file_put_contents($conf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\n");
        $o = ['config' => $conf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $k = openssl_pkey_new($o);
        self::assertNotFalse($k);
        $kk = $k;
        $csr = openssl_csr_new(['commonName' => 'x'], $kk, $o);
        self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $csr);
        $cert = openssl_csr_sign($csr, null, $k, 0, $o);
        self::assertNotFalse($cert);
        $certPem = null;
        $keyPem = null;
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($k, $keyPem, null, ['config' => $conf]);
        unlink($conf);
        sleep(2); // days=0: valid for zero seconds, so it is expired by now
        $this->expectException(CertificateException::class);
        new CertificateCredentials((string) $certPem, new SensitiveString((string) $keyPem), ContextIdentifier::nip('7980332920'));
    }

    private static function hexToDec(string $hex): string
    {
        $dec = '0';
        foreach (str_split(strtoupper($hex)) as $ch) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($ch));
        }

        return $dec;
    }

    private static function first(\DOMXPath $xp, string $expr, ?\DOMNode $ctx = null): ?\DOMNode
    {
        $r = $ctx === null ? $xp->query($expr) : $xp->query($expr, $ctx);

        return $r === false ? null : $r->item(0);
    }

    private static function nodeCount(\DOMXPath $xp, string $expr): int
    {
        $r = $xp->query($expr);

        return $r === false ? 0 : $r->length;
    }
}
