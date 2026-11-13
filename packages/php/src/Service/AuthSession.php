<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Session\StatusInfo;
use LuroWeb\KsefFoundry\Support\Arr;

/** AuthenticationListItem. */
final class AuthSession
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly \DateTimeImmutable $startDate,
        public readonly string $authenticationMethod,
        public readonly StatusInfo $status,
        public readonly bool $isCurrent,
        public readonly ?bool $isTokenRedeemed,
        public readonly ?\DateTimeImmutable $lastTokenRefreshDate,
        public readonly ?\DateTimeImmutable $refreshTokenValidUntil,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            Arr::string($a, 'referenceNumber'),
            Arr::date($a, 'startDate'),
            Arr::stringOrNull($a, 'authenticationMethod') ?? '',
            StatusInfo::fromArray(Arr::arr($a, 'status')),
            Arr::bool($a, 'isCurrent'),
            Arr::boolOrNull($a, 'isTokenRedeemed'),
            Arr::dateOrNull($a, 'lastTokenRefreshDate'),
            Arr::dateOrNull($a, 'refreshTokenValidUntil'),
        );
    }
}
