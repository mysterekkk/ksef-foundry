<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Events;

/** Dispatched when KSeF reported a final status >= 400 for an invoice. Carries no invoice content. */
final class InvoiceRejected
{
    /** @param list<string> $details */
    public function __construct(
        public readonly string $connection,
        public readonly string $invoiceHash,
        public readonly int $statusCode,
        public readonly string $description,
        public readonly array $details,
        public readonly ?string $invoiceReferenceNumber,
        public readonly ?string $sessionReferenceNumber,
    ) {
    }
}
