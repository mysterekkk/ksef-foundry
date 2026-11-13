<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Crypto;

/** Everything POST /sessions/online/{ref}/invoices needs about one encrypted document. */
final class EncryptedInvoice
{
    public function __construct(
        public readonly string $invoiceHash,
        public readonly int $invoiceSize,
        public readonly string $encryptedInvoiceHash,
        public readonly int $encryptedInvoiceSize,
        public readonly string $encryptedInvoiceContent,
    ) {
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'invoiceHash' => $this->invoiceHash,
            'invoiceSize' => $this->invoiceSize,
            'encryptedInvoiceHash' => $this->encryptedInvoiceHash,
            'encryptedInvoiceSize' => $this->encryptedInvoiceSize,
            'encryptedInvoiceContent' => $this->encryptedInvoiceContent,
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'invoiceHash' => $this->invoiceHash,
            'invoiceSize' => $this->invoiceSize,
            'encryptedInvoiceContent' => '[REDACTED]',
        ];
    }
}
