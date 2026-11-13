<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/**
 * Session state problem. Same as the TypeScript SDK: thrown by InvoiceSessionService::sendInvoice() for
 * KSeF 21184 ("session temporarily unavailable"); getPrevious() is the original ApiException. Other
 * session-related API errors (21173, 21180 ...) stay plain ValidationException with their ksefCodes.
 */
class SessionException extends KsefException
{
    /**
     * @param list<int> $ksefCodes
     */
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        private readonly ?int $statusCode = null,
        private readonly array $ksefCodes = [],
        private readonly ?string $sessionReferenceNumber = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function category(): string
    {
        return 'session';
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function ksefCode(): ?int
    {
        return $this->ksefCodes[0] ?? null;
    }

    /** @return list<int> */
    public function ksefCodes(): array
    {
        return $this->ksefCodes;
    }

    public function sessionReferenceNumber(): ?string
    {
        return $this->sessionReferenceNumber;
    }

    public function diagnostics(): array
    {
        return parent::diagnostics() + [
            'statusCode' => $this->statusCode,
            'ksefCodes' => $this->ksefCodes,
            'sessionReferenceNumber' => $this->sessionReferenceNumber,
        ];
    }
}
