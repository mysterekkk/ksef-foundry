<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Support;

use LuroWeb\KsefFoundry\Laravel\Contracts\InvoiceSink;
use LuroWeb\KsefFoundry\Query\InvoiceMetadata;

/** Default sink: drops everything, logs nothing. Bind your own InvoiceSink to persist invoices. */
final class NullInvoiceSink implements InvoiceSink
{
    public function cursor(string $connection): ?\DateTimeImmutable
    {
        return null;
    }

    public function accept(string $connection, InvoiceMetadata $invoice): void
    {
    }

    public function saveCursor(string $connection, \DateTimeImmutable $cursor): void
    {
    }
}
