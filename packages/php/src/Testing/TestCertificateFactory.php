<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Testing;

use LuroWeb\KsefFoundry\Exception\CertificateException;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/**
 * !!! TEST ENVIRONMENT ONLY !!!  Never use in production code paths.
 * These self-signed certificates are accepted by the KSeF TEST environment only; DEMO and PRODUCTION reject them,
 * and a certificate generated here proves no identity. There is deliberately no runtime environment check:
 * the class is a test utility, keep it out of application code.
 *
 * TEST-ONLY: generates self-signed personal certificates that the KSeF TEST environment accepts
 * (they are rejected by DEMO and PRODUCTION). Never use for real data.
 *
 * Subject: C=PL, CN, givenName (2.5.4.42), surname (2.5.4.4), serialNumber (2.5.4.5) = "PNOPL-<pesel>" or "TINPL-<nip>".
 */
final class TestCertificateFactory
{
    /** Certificate of a natural person identified by PESEL (serialNumber PNOPL-...). */
    public function personWithPesel(
        string $pesel,
        string $givenName = 'Jan',
        string $surname = 'Testowy',
        bool $ec = false,
    ): TestCertificate {
        return $this->personal($givenName, $surname, 'PNOPL', $pesel, $givenName . ' ' . $surname, $ec);
    }

    /** Certificate of a natural person identified by NIP (serialNumber TINPL-...). */
    public function personWithNip(
        string $nip,
        string $givenName = 'Jan',
        string $surname = 'Testowy',
        bool $ec = false,
    ): TestCertificate {
        return $this->personal($givenName, $surname, 'TINPL', $nip, $givenName . ' ' . $surname, $ec);
    }

    public function personal(
        string $givenName,
        string $surname,
        string $serialNumberPrefix,
        string $serialNumberValue,
        string $commonName,
        bool $ec = false,
    ): TestCertificate {
        $dn = [
            'countryName' => 'PL',
            'commonName' => $commonName,
            'givenName' => $givenName,
            'surname' => $surname,
            'serialNumber' => $serialNumberPrefix . '-' . $serialNumberValue,
        ];
        // Own minimal openssl.cnf: never depends on (or fails because of) the system's default configuration.
        [$certPem, $keyPem] = OpenSslConfig::run(function (string $conf) use ($dn, $ec): array {
            $opts = OpenSslConfig::options($conf, $ec);
            $key = openssl_pkey_new($opts);
            if ($key === false) {
                throw new CertificateException('Cannot generate key: ' . $this->lastError());
            }
            $csrKey = $key;
            $csr = openssl_csr_new($dn, $csrKey, $opts);
            if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
                throw new CertificateException('Cannot create CSR: ' . $this->lastError());
            }
            $cert = openssl_csr_sign($csr, null, $key, 30, $opts, random_int(1, PHP_INT_MAX));
            if ($cert === false) {
                throw new CertificateException('Cannot sign certificate: ' . $this->lastError());
            }
            $certPem = null;
            $keyPem = null;
            if (
                !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem, null, ['config' => $conf])
                || !is_string($certPem) || !is_string($keyPem)
            ) {
                throw new CertificateException('Cannot export certificate/key: ' . $this->lastError());
            }

            return [$certPem, $keyPem];
        });

        return new TestCertificate($certPem, new SensitiveString($keyPem));
    }

    private function lastError(): string
    {
        $e = [];
        while (($m = openssl_error_string()) !== false) {
            $e[] = $m;
        }

        return implode('; ', $e);
    }
}
