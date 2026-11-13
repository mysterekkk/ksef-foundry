<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Support;

/**
 * Wraps a secret (KSeF token, JWT, refresh token, passphrase). Never printed, serialised or dumped.
 */
final class SensitiveString implements \JsonSerializable, \Stringable
{
    private string $value;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        $this->value = $value;
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    public function jsonSerialize(): string
    {
        return '[REDACTED]';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('SensitiveString cannot be serialised.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('SensitiveString cannot be unserialised.');
    }
}
