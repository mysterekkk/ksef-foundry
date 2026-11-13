<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

final class AuthChallenge
{
    public function __construct(
        public readonly string $challenge,
        public readonly \DateTimeImmutable $timestamp,
        public readonly int $timestampMs,
        public readonly string $clientIp,
    ) {
    }
}
