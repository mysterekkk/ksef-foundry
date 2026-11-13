<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Crypto;

use LuroWeb\KsefFoundry\Exception\CertificateException;
use LuroWeb\KsefFoundry\Exception\EncryptionException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\HttpTransport;
use LuroWeb\KsefFoundry\Support\Arr;
use LuroWeb\KsefFoundry\Support\X509;

/**
 * GET /security/public-key-certificates, cached in memory, selected by usage (valid now, latest validFrom wins).
 */
final class PublicKeyProvider
{
    /** @var list<PublicKey>|null */
    private ?array $cache = null;

    private ?int $fetchedAt = null;

    /**
     * @param \Closure(): int $clock unix time
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly \Closure $clock,
        private readonly int $ttlSeconds = 3600,
    ) {
    }

    public function get(PublicKeyUsage $usage): PublicKey
    {
        $now = ($this->clock)();
        if ($this->cache === null || $this->fetchedAt === null || $now - $this->fetchedAt > $this->ttlSeconds) {
            $this->refresh();
        }
        $key = $this->select($this->cache ?? [], $usage, $now);
        if ($key === null) {
            // The cached set may be stale (rotation); look once more before giving up.
            $this->refresh();
            $key = $this->select($this->cache ?? [], $usage, ($this->clock)());
        }
        if ($key === null) {
            throw new EncryptionException(sprintf('No valid KSeF public key with usage %s.', $usage->value));
        }

        return $key;
    }

    /** Drops the cache and fetches again (use after KSeF error 21470). */
    public function refresh(): void
    {
        $response = $this->transport->send(new HttpRequest('GET', '/security/public-key-certificates', idempotent: true));
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new EncryptionException('Public key list is not valid JSON.');
        }
        $keys = [];
        foreach (Arr::objects($decoded) as $entry) {
            try {
                $keys[] = self::parseEntry($entry);
            } catch (EncryptionException) {
                // A single malformed/weak entry must not poison the others; selection fails later if nothing is usable.
                continue;
            }
        }
        $this->cache = $keys;
        $this->fetchedAt = ($this->clock)();
    }

    /**
     * Validates one entry: parseable X.509, RSA >= 2048, publicKeyId = base64(sha256(SPKI DER)).
     *
     * @param array<mixed> $entry
     */
    public static function parseEntry(array $entry): PublicKey
    {
        $der = base64_decode(Arr::string($entry, 'certificate'), true);
        if ($der === false || $der === '') {
            throw new EncryptionException('Public key certificate is not valid base64.');
        }
        $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        try {
            $cert = X509::read($certPem);
            $pk = X509::publicKey($cert);
        } catch (CertificateException $e) {
            throw new EncryptionException('Public key certificate cannot be parsed.', 0, $e);
        }
        if ($pk['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new EncryptionException('KSeF public key is not RSA.');
        }
        if ($pk['bits'] < 2048) {
            throw new EncryptionException('KSeF RSA public key is shorter than 2048 bits.');
        }
        $bits = $pk['bits'];
        $spkiPem = $pk['pem'];
        $computedId = base64_encode(hash('sha256', $pk['der'], true));
        $declaredId = Arr::string($entry, 'publicKeyId');
        if (!hash_equals($computedId, $declaredId)) {
            throw new EncryptionException('publicKeyId does not match SHA-256 of the certificate SubjectPublicKeyInfo.');
        }
        $from = Arr::date($entry, 'validFrom');
        $to = Arr::date($entry, 'validTo');
        $x = X509::info($cert);
        // Declared validity must not exceed what the certificate itself says.
        if ($x['validFrom'] > 0 && $x['validTo'] > 0) {
            $from = $from->getTimestamp() < $x['validFrom'] ? (new \DateTimeImmutable())->setTimestamp($x['validFrom']) : $from;
            $to = $to->getTimestamp() > $x['validTo'] ? (new \DateTimeImmutable())->setTimestamp($x['validTo']) : $to;
        }

        return new PublicKey(
            $declaredId,
            Arr::stringOrNull($entry, 'certificateId') ?? '',
            $spkiPem,
            $from,
            $to,
            Arr::strings($entry, 'usage'),
            $bits,
        );
    }

    /**
     * @param list<PublicKey> $keys
     */
    private function select(array $keys, PublicKeyUsage $usage, int $now): ?PublicKey
    {
        $at = (new \DateTimeImmutable())->setTimestamp($now);
        $best = null;
        foreach ($keys as $k) {
            if (!$k->hasUsage($usage) || !$k->isValidAt($at)) {
                continue;
            }
            if ($best === null || $k->validFrom > $best->validFrom) {
                $best = $k;
            }
        }

        return $best;
    }
}
