<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Support\Arr;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/** Response of POST /auth/xades-signature and /auth/ksef-token (AuthenticationInitResponse). */
final class AuthInit
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly SensitiveString $authenticationToken,
        public readonly \DateTimeImmutable $validUntil,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $t = Arr::arr($a, 'authenticationToken');

        return new self(
            Arr::string($a, 'referenceNumber'),
            new SensitiveString(Arr::string($t, 'token')),
            Arr::date($t, 'validUntil'),
        );
    }
}
