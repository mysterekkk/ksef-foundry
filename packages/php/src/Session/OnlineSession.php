<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Crypto\SessionKey;

/** An open interactive session. The key material lives in memory only. */
final class OnlineSession
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly \DateTimeImmutable $validUntil,
        public readonly FormCode $formCode,
        #[\SensitiveParameter] private readonly SessionKey $key,
    ) {
    }

    public function key(): SessionKey
    {
        return $this->key;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'referenceNumber' => $this->referenceNumber,
            'validUntil' => $this->validUntil->format('c'),
            'key' => '[REDACTED]',
        ];
    }
}
