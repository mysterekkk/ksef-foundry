<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\Arr;

/** Per-session-type limits of the context (EffectiveContextLimits.onlineSession / batchSession). */
final class SessionLimits
{
    public function __construct(
        public readonly int $maxInvoiceSizeInMB,
        public readonly int $maxInvoiceWithAttachmentSizeInMB,
        public readonly int $maxInvoices,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            Arr::intOrNull($a, 'maxInvoiceSizeInMB') ?? 0,
            Arr::intOrNull($a, 'maxInvoiceWithAttachmentSizeInMB') ?? 0,
            Arr::intOrNull($a, 'maxInvoices') ?? 0,
        );
    }
}
