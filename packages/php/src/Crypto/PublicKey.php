<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Crypto;

/** A validated KSeF public key (RSA, certificate-backed). */
final class PublicKey
{
    /**
     * @param list<string> $usage
     */
    public function __construct(
        public readonly string $publicKeyId,
        public readonly string $certificateId,
        public readonly string $pem,
        public readonly \DateTimeImmutable $validFrom,
        public readonly \DateTimeImmutable $validTo,
        public readonly array $usage,
        public readonly int $rsaBits,
    ) {
    }

    public function hasUsage(PublicKeyUsage $usage): bool
    {
        return in_array($usage->value, $this->usage, true);
    }

    public function isValidAt(\DateTimeImmutable $at): bool
    {
        return $at >= $this->validFrom && $at <= $this->validTo;
    }
}
