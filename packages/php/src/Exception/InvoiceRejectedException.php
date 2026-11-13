<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

use LuroWeb\KsefFoundry\Support\Redactor;

/** Final invoice status >= 400 (e.g. 440 duplicate). */
final class InvoiceRejectedException extends KsefException
{
    public function category(): string
    {
        return 'invoice_rejected';
    }

    /**
     * @param list<string> $details
     * @param array<string, string|null> $extensions e.g. originalKsefNumber / originalSessionReferenceNumber of a 440 duplicate
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $description,
        private readonly array $details = [],
        private readonly ?string $invoiceReferenceNumber = null,
        private readonly ?string $sessionReferenceNumber = null,
        private readonly array $extensions = [],
    ) {
        parent::__construct(sprintf('Invoice rejected by KSeF: status %d (%s).', $statusCode, $description));
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array<string, string|null> */
    public function statusExtensions(): array
    {
        return $this->extensions;
    }

    public function invoiceReferenceNumber(): ?string
    {
        return $this->invoiceReferenceNumber;
    }

    public function sessionReferenceNumber(): ?string
    {
        return $this->sessionReferenceNumber;
    }

    public function diagnostics(): array
    {
        $r = new Redactor();

        return [
            'class' => self::class,
            'statusCode' => $this->statusCode,
            'description' => $this->description,
            'details' => array_map($r->redactString(...), $this->details),
            'statusExtensions' => $this->extensions,
            'invoiceReferenceNumber' => $this->invoiceReferenceNumber,
            'sessionReferenceNumber' => $this->sessionReferenceNumber,
            'category' => $this->category(),
        ];
    }
}
