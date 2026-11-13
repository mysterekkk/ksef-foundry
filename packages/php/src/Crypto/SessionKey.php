<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Crypto;

use LuroWeb\KsefFoundry\Exception\EncryptionException;

/** Per-session AES-256 key (32 B) and IV (16 B). Memory only; never printed or serialised. */
final class SessionKey
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $key,
        #[\SensitiveParameter] private readonly string $iv,
    ) {
        if (strlen($key) !== 32 || strlen($iv) !== 16) {
            throw new EncryptionException('Session key must be 32 bytes and IV 16 bytes.');
        }
    }

    public static function generate(): self
    {
        return new self(random_bytes(32), random_bytes(16));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function iv(): string
    {
        return $this->iv;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['key' => '[REDACTED]', 'iv' => '[REDACTED]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('SessionKey cannot be serialised.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('SessionKey cannot be unserialised.');
    }
}
