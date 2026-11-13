<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Support;

use LuroWeb\KsefFoundry\Support\X509;

/**
 * Generates a real RSA key pair and a self-signed certificate that stand in for the Ministry of Finance key
 * (real crypto, generated at test runtime; never stored in the repository).
 */
final class KsefLikeKeys
{
    public readonly string $privateKeyPem;
    public readonly string $certificateDerBase64;
    public readonly string $publicKeyId;
    public readonly string $certificateId;

    public function __construct(int $bits = 2048)
    {
        $conf = tempnam(sys_get_temp_dir(), 'ksefkey');
        if ($conf === false) {
            throw new \RuntimeException('tempnam failed');
        }
        file_put_contents($conf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\n");
        try {
            $opts = ['config' => $conf, 'private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new($opts);
            if ($key === false) {
                throw new \RuntimeException('key generation failed');
            }
            $csrKey = $key;
            $csr = openssl_csr_new(['commonName' => 'Ministerstwo Finansow (TEST FAKE)'], $csrKey, $opts);
            if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
                throw new \RuntimeException('csr failed');
            }
            $cert = openssl_csr_sign($csr, null, $key, 365, $opts, random_int(1, PHP_INT_MAX));
            if ($cert === false) {
                throw new \RuntimeException('sign failed');
            }
            $pem = null;
            if (!openssl_pkey_export($key, $pem, null, ['config' => $conf]) || !is_string($pem)) {
                throw new \RuntimeException('export failed');
            }
            $this->privateKeyPem = $pem;
            $der = X509::der($cert);
            $this->certificateDerBase64 = base64_encode($der);
            $this->certificateId = base64_encode(hash('sha256', $der, true));
            $this->publicKeyId = base64_encode(hash('sha256', X509::publicKey($cert)['der'], true));
        } finally {
            unlink($conf);
        }
    }

    /**
     * Body of GET /security/public-key-certificates with one entry per usage.
     *
     * @param list<string> $usages
     */
    public function listJson(array $usages = ['KsefTokenEncryption', 'SymmetricKeyEncryption'], ?string $validFrom = null, ?string $validTo = null): string
    {
        $entries = [];
        foreach ($usages as $u) {
            $entries[] = [
                'certificate' => $this->certificateDerBase64,
                'certificateId' => $this->certificateId,
                'publicKeyId' => $this->publicKeyId,
                'validFrom' => $validFrom ?? gmdate('c', time() - 3600),
                'validTo' => $validTo ?? gmdate('c', time() + 86400 * 300),
                'usage' => [$u],
            ];
        }

        return json_encode($entries, JSON_THROW_ON_ERROR);
    }
}
