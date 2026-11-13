<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Support\Arr;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/**
 * accessToken (JWT, short) + refreshToken (long, up to 7 days). This is the ONLY thing handed to storage:
 * the long-lived KSeF token / private key never is.
 */
final class TokenPair
{
    public function __construct(
        #[\SensitiveParameter] public readonly SensitiveString $accessToken,
        public readonly \DateTimeImmutable $accessValidUntil,
        #[\SensitiveParameter] public readonly SensitiveString $refreshToken,
        public readonly \DateTimeImmutable $refreshValidUntil,
    ) {
    }

    public function withAccessToken(#[\SensitiveParameter] SensitiveString $token, \DateTimeImmutable $validUntil): self
    {
        return new self($token, $validUntil, $this->refreshToken, $this->refreshValidUntil);
    }

    public function accessValidFor(int $now, int $skewSeconds): bool
    {
        return $this->accessValidUntil->getTimestamp() - $now > $skewSeconds;
    }

    public function refreshValidFor(int $now, int $skewSeconds): bool
    {
        return $this->refreshValidUntil->getTimestamp() - $now > $skewSeconds;
    }

    /**
     * Plain array for storage adapters (cache, DB). CONTAINS SECRETS: encrypt at rest.
     *
     * @return array{accessToken: string, accessValidUntil: string, refreshToken: string, refreshValidUntil: string}
     */
    public function toStorageArray(): array
    {
        return [
            'accessToken' => $this->accessToken->reveal(),
            'accessValidUntil' => $this->accessValidUntil->format(\DateTimeInterface::ATOM),
            'refreshToken' => $this->refreshToken->reveal(),
            'refreshValidUntil' => $this->refreshValidUntil->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<mixed> $a */
    public static function fromStorageArray(#[\SensitiveParameter] array $a): self
    {
        return new self(
            new SensitiveString(Arr::string($a, 'accessToken')),
            Arr::date($a, 'accessValidUntil'),
            new SensitiveString(Arr::string($a, 'refreshToken')),
            Arr::date($a, 'refreshValidUntil'),
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[REDACTED]',
            'accessValidUntil' => $this->accessValidUntil->format('c'),
            'refreshToken' => '[REDACTED]',
            'refreshValidUntil' => $this->refreshValidUntil->format('c'),
        ];
    }
}
