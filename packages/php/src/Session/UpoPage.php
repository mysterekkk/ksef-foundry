<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Support\Arr;

/** One page of the collective session UPO. downloadUrl is informational and is never followed by the SDK. */
final class UpoPage
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly string $downloadUrl,
        public readonly \DateTimeImmutable $downloadUrlExpirationDate,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            Arr::string($a, 'referenceNumber'),
            Arr::string($a, 'downloadUrl'),
            Arr::date($a, 'downloadUrlExpirationDate'),
        );
    }
}
