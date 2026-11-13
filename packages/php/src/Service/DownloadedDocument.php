<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

/** XML document (invoice or UPO) with the SHA-256 KSeF declared (x-ms-meta-hash) and our own verification. */
final class DownloadedDocument
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $xml,
        public readonly string $sha256Base64,
        public readonly ?string $declaredSha256Base64,
        public readonly bool $hashVerified,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        // Invoice content must never leak through var_dump.
        return ['bytes' => strlen($this->xml), 'sha256Base64' => $this->sha256Base64, 'hashVerified' => $this->hashVerified];
    }
}
