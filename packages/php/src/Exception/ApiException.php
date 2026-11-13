<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

use LuroWeb\KsefFoundry\Support\Redactor;

/** Any non-2xx answer from KSeF. */
class ApiException extends KsefException
{
    /**
     * @param list<int> $ksefCodes
     * @param list<string> $details
     * @param array<mixed> $problem redacted parsed body
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $ksefCodes = [],
        private readonly array $details = [],
        private readonly ?string $traceId = null,
        private readonly array $problem = [],
        private readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
        private readonly ?int $authStatusCode = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function category(): string
    {
        return 'api';
    }

    public function statusCode(): int
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

    /** @return list<string> */
    public function details(): array
    {
        return $this->details;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    /** @return array<mixed> */
    public function problem(): array
    {
        return $this->problem;
    }

    /** KSeF authentication operation status (GET /auth/{ref}) that caused this error, e.g. 415. */
    public function authStatusCode(): ?int
    {
        return $this->authStatusCode;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function retryable(): bool
    {
        return false;
    }

    public function hasKsefCode(int $code): bool
    {
        return in_array($code, $this->ksefCodes, true);
    }

    public function diagnostics(): array
    {
        $r = new Redactor();

        return [
            'class' => static::class,
            'category' => $this->category(),
            'statusCode' => $this->statusCode,
            'ksefCode' => $this->ksefCode(),
            'ksefCodes' => $this->ksefCodes,
            'message' => $r->redactString($this->getMessage()),
            'details' => array_map($r->redactString(...), $this->details),
            'traceId' => $this->traceId,
            'retryable' => $this->retryable(),
            'retryAfterSeconds' => $this->retryAfterSeconds,
            'authStatusCode' => $this->authStatusCode,
            'problem' => $r->redactArray($this->problem),
        ];
    }
}
