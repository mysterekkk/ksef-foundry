<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

/** Result of POST /sessions/online/{ref}/invoices (HTTP 202: queued, NOT accepted). */
final class SentInvoice
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly string $invoiceHash,
        public readonly string $sessionReferenceNumber,
    ) {
    }
}
