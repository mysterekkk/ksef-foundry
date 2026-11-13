<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\Arr;

/** GET /limits/context (EffectiveContextLimits). */
final class ContextLimits
{
    public function __construct(
        public readonly SessionLimits $onlineSession,
        public readonly SessionLimits $batchSession,
        public readonly int $collectiveIdentifierMaxInvoices,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            SessionLimits::fromArray(Arr::arr($a, 'onlineSession')),
            SessionLimits::fromArray(Arr::arr($a, 'batchSession')),
            Arr::intOrNull(Arr::arr($a, 'collectiveIdentifier'), 'maxInvoices') ?? 0,
        );
    }
}
