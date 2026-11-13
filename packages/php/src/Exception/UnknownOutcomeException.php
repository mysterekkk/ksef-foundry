<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/**
 * Ambiguous failure of a non-idempotent call (timeout, reset, 5xx on send): the invoice may or may not
 * have been accepted. NEVER retry blindly: reconcile via InvoiceSessionService::reconcile() first.
 */
final class UnknownOutcomeException extends KsefException
{
    public function __construct(
        string $message,
        private readonly ?string $invoiceHash = null,
        private readonly ?string $sessionReferenceNumber = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function category(): string
    {
        return 'unknown_outcome';
    }

    public function invoiceHash(): ?string
    {
        return $this->invoiceHash;
    }

    public function sessionReferenceNumber(): ?string
    {
        return $this->sessionReferenceNumber;
    }

    public function diagnostics(): array
    {
        return parent::diagnostics() + [
            'invoiceHash' => $this->invoiceHash,
            'sessionReferenceNumber' => $this->sessionReferenceNumber,
            'cause' => $this->getPrevious()?->getMessage(),
        ];
    }
}
