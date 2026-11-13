<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

/** Outcome of InvoiceSender::submit(): only produced for an invoice KSeF ACCEPTED (status 200). */
final class SubmissionResult
{
    public function __construct(
        public readonly string $ksefNumber,
        public readonly string $invoiceReferenceNumber,
        public readonly string $sessionReferenceNumber,
        public readonly string $invoiceHash,
        public readonly bool $upoAvailable,
        public readonly bool $sessionClosed,
        public readonly InvoiceStatus $status,
    ) {
    }
}
