<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Support\Arr;

/** Response of GET /auth/{referenceNumber} (AuthenticationOperationStatusResponse). */
final class AuthStatus
{
    public const IN_PROGRESS = 100;
    public const SUCCESS = 200;

    /**
     * @param list<string> $details
     */
    public function __construct(
        public readonly int $code,
        public readonly string $description,
        public readonly array $details,
        public readonly ?string $authenticationMethod,
        public readonly ?\DateTimeImmutable $startDate,
        public readonly ?bool $isTokenRedeemed,
        public readonly ?\DateTimeImmutable $refreshTokenValidUntil,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $s = Arr::arr($a, 'status');

        return new self(
            Arr::int($s, 'code'),
            Arr::stringOrNull($s, 'description') ?? '',
            Arr::strings($s, 'details'),
            Arr::stringOrNull($a, 'authenticationMethod'),
            Arr::dateOrNull($a, 'startDate'),
            Arr::boolOrNull($a, 'isTokenRedeemed'),
            Arr::dateOrNull($a, 'refreshTokenValidUntil'),
        );
    }

    public function isInProgress(): bool
    {
        return $this->code < self::SUCCESS;
    }

    public function isSuccess(): bool
    {
        return $this->code === self::SUCCESS;
    }
}
