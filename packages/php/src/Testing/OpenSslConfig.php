<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Testing;

use LuroWeb\KsefFoundry\Exception\CertificateException;

/**
 * Minimal self-written openssl.cnf for key/CSR/certificate generation, so ext-openssl never depends on the system's
 * (possibly missing or unreadable) default configuration file. The file is created with owner-only permissions in the
 * system temp dir and always removed, even on failure. TEST/tooling use only.
 */
final class OpenSslConfig
{
    /** Bits passed for EC keys too: ext-openssl < 8.4 warns when private_key_bits is unset or below 384. */
    public const KEY_BITS = 2048;

    private const CONTENT = <<<'CNF'
        [req]
        default_bits = 2048
        distinguished_name = dn
        prompt = no
        [dn]
        [v3_leaf]
        basicConstraints = CA:FALSE
        keyUsage = digitalSignature, nonRepudiation
        CNF;

    /**
     * @template T
     * @param \Closure(string): T $work receives the config file path
     * @return T
     */
    public static function run(\Closure $work): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'ksefcnf');
        if ($path === false) {
            throw new CertificateException('Cannot create temporary OpenSSL config.');
        }
        try {
            chmod($path, 0600);
            if (file_put_contents($path, preg_replace('/^ {8}/m', '', self::CONTENT) . "\n") === false) {
                throw new CertificateException('Cannot write temporary OpenSSL config.');
            }

            return $work($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Options for openssl_pkey_new / csr_new / csr_sign.
     *
     * @return array<string, mixed>
     */
    public static function options(string $configPath, bool $ec = false): array
    {
        $key = $ec
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => self::KEY_BITS, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => self::KEY_BITS];

        return ['config' => $configPath, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_leaf'] + $key;
    }
}
