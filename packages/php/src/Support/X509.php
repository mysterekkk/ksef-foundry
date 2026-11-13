<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Support;

use LuroWeb\KsefFoundry\Exception\CertificateException;

/** Strictly typed wrappers around the loosely typed ext-openssl certificate/key helpers. */
final class X509
{
    /** Parses a PEM certificate without leaking PHP warnings. */
    public static function read(string $pem): \OpenSSLCertificate
    {
        set_error_handler(static fn (): bool => true);
        try {
            $cert = str_contains($pem, 'BEGIN CERTIFICATE') ? openssl_x509_read($pem) : false;
        } finally {
            restore_error_handler();
        }
        if ($cert === false) {
            throw new CertificateException('Certificate PEM cannot be parsed.');
        }

        return $cert;
    }

    /** Loads a private key (optionally passphrase protected) without leaking PHP warnings. */
    public static function readPrivateKey(#[\SensitiveParameter] string $pem, #[\SensitiveParameter] string $passphrase = ''): \OpenSSLAsymmetricKey
    {
        set_error_handler(static fn (): bool => true);
        try {
            $key = openssl_pkey_get_private($pem, $passphrase);
        } finally {
            restore_error_handler();
        }
        if ($key === false) {
            throw new CertificateException('Private key cannot be loaded (wrong format or passphrase).');
        }

        return $key;
    }

    public static function der(\OpenSSLCertificate $cert): string
    {
        $pem = null;
        if (!openssl_x509_export($cert, $pem) || !is_string($pem)) {
            throw new CertificateException('Cannot export certificate.');
        }

        return self::pemToDer($pem);
    }

    public static function pemToDer(string $pem): string
    {
        $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), true);
        if ($der === false || $der === '') {
            throw new CertificateException('Cannot decode PEM to DER.');
        }

        return $der;
    }

    /**
     * @return array{type: int, bits: int}
     */
    public static function keyInfo(\OpenSSLAsymmetricKey $key): array
    {
        $d = openssl_pkey_get_details($key);
        if ($d === false) {
            throw new CertificateException('Cannot inspect key.');
        }

        return ['type' => Arr::intOrNull($d, 'type') ?? -1, 'bits' => Arr::intOrNull($d, 'bits') ?? 0];
    }

    /**
     * SubjectPublicKeyInfo of a certificate as PEM and DER.
     *
     * @return array{pem: string, der: string, type: int, bits: int}
     */
    public static function publicKey(\OpenSSLCertificate $cert): array
    {
        $pub = openssl_pkey_get_public($cert);
        if ($pub === false) {
            throw new CertificateException('Cannot read public key from certificate.');
        }
        $d = openssl_pkey_get_details($pub);
        if ($d === false) {
            throw new CertificateException('Cannot inspect public key.');
        }
        $pem = Arr::stringOrNull($d, 'key');
        if ($pem === null) {
            throw new CertificateException('Cannot export public key.');
        }

        return [
            'pem' => $pem,
            'der' => self::pemToDer($pem),
            'type' => Arr::intOrNull($d, 'type') ?? -1,
            'bits' => Arr::intOrNull($d, 'bits') ?? 0,
        ];
    }

    /**
     * @return array{validFrom: int, validTo: int, serialHex: string, issuer: list<string>}
     */
    public static function info(\OpenSSLCertificate $cert): array
    {
        $p = openssl_x509_parse($cert);
        if ($p === false) {
            throw new CertificateException('Cannot parse certificate.');
        }
        $issuer = [];
        foreach (Arr::arr($p, 'issuer') as $k => $v) {
            foreach (is_array($v) ? $v : [$v] as $item) {
                if (is_scalar($item)) {
                    $issuer[] = $k . '=' . (string) preg_replace('/([,+"\\\\<>;])/', '\\\\$1', (string) $item);
                }
            }
        }

        return [
            'validFrom' => Arr::intOrNull($p, 'validFrom_time_t') ?? 0,
            'validTo' => Arr::intOrNull($p, 'validTo_time_t') ?? 0,
            'serialHex' => strtoupper(Arr::stringOrNull($p, 'serialNumberHex') ?? '0'),
            'issuer' => $issuer,
        ];
    }
}
