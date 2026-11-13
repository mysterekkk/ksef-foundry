<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Contracts;

use LuroWeb\KsefFoundry\Query\InvoiceMetadata;

/**
 * Where FetchInvoicesJob hands over invoice metadata and keeps its cursor. The package persists nothing itself.
 * accept() must be idempotent by KSeF number (the job may deliver a boundary invoice twice).
 */
interface InvoiceSink
{
    /** Last stored PermanentStorage cursor of the connection, or null for "start from the configured lookback". */
    public function cursor(string $connection): ?\DateTimeImmutable;

    public function accept(string $connection, InvoiceMetadata $invoice): void;

    /** Called after invoices were accepted; store it durably, it is the next run's start. */
    public function saveCursor(string $connection, \DateTimeImmutable $cursor): void;
}
