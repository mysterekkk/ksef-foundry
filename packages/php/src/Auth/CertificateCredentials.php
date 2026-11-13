<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Context\SubjectIdentifierType;
use LuroWeb\KsefFoundry\Exception\CertificateException;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Support\X509;

/**
 * Certificate + private key for XAdES login (qualified signature/seal, KSeF Authentication certificate,
 * or a self-signed certificate on TEST only). Offline certificates are NOT accepted by KSeF for authentication.
 *
 * See XadesSigner for the exact live-verified scope (self-signed personal certificate on TEST).
 */
final class CertificateCredentials extends Credentials
{
    private readonly \OpenSSLAsymmetricKey $key;
    private readonly string $certificateDer;

    public function __construct(
        private readonly string $certificatePem,
        #[\SensitiveParameter] SensitiveString $privateKeyPem,
        private readonly ContextIdentifier $context,
        private readonly SubjectIdentifierType $subjectIdentifierType = SubjectIdentifierType::CertificateSubject,
        #[\SensitiveParameter] ?SensitiveString $passphrase = null,
    ) {
        $cert = X509::read($certificatePem);
        $key = X509::readPrivateKey($privateKeyPem->reveal(), $passphrase?->reveal() ?? '');
        if (!openssl_x509_check_private_key($cert, $key)) {
            throw new CertificateException('Private key does not match the certificate.');
        }
        $info = X509::keyInfo($key);
        if ($info['type'] === OPENSSL_KEYTYPE_RSA) {
            if ($info['bits'] < 2048) {
                throw new CertificateException('RSA key must be at least 2048 bits.');
            }
        } elseif ($info['type'] === OPENSSL_KEYTYPE_EC) {
            if ($info['bits'] < 256) {
                throw new CertificateException('EC key must be at least P-256.');
            }
        } else {
            throw new CertificateException('Only RSA and ECDSA keys are supported.');
        }
        $x = X509::info($cert);
        $now = time();
        if ($now < $x['validFrom'] || $now > $x['validTo']) {
            throw new CertificateException('Certificate is not valid at the current time.');
        }
        $der = X509::der($cert);
        $this->certificateDer = $der;
        $this->key = $key;
    }

    public function context(): ContextIdentifier
    {
        return $this->context;
    }

    public function subjectIdentifierType(): SubjectIdentifierType
    {
        return $this->subjectIdentifierType;
    }

    public function certificatePem(): string
    {
        return $this->certificatePem;
    }

    public function privateKey(): \OpenSSLAsymmetricKey
    {
        return $this->key;
    }

    public function method(): string
    {
        return 'certificate';
    }

    public function fingerprint(): string
    {
        return hash('sha256', 'certificate:' . $this->certificateDer . ':' . $this->subjectIdentifierType->value);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['certificateSha256' => hash('sha256', $this->certificateDer), 'privateKey' => '[REDACTED]'];
    }
}
