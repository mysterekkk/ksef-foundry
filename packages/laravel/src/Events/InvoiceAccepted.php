<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Events;

/** Dispatched when KSeF reported status 200 for an invoice. Carries no invoice content. */
final class InvoiceAccepted
{
    public function __construct(
        public readonly string $connection,
        public readonly string $invoiceHash,
        public readonly string $ksefNumber,
        public readonly string $invoiceReferenceNumber,
        public readonly string $sessionReferenceNumber,
    ) {
    }
}
