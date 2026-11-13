<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use LuroWeb\KsefFoundry\Support\SensitiveString;

/** Immutable description of one API call. */
final class HttpRequest
{
    /**
     * @param array<string, scalar|list<scalar>|null> $query null values are dropped, lists repeat the key
     * @param array<string, string> $headers
     * @param bool $idempotent safe to retry on network errors / 5xx (GET, DELETE of a resource ...)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        #[\SensitiveParameter] public readonly ?string $body = null,
        public readonly string $contentType = 'application/json',
        public readonly string $accept = 'application/json',
        #[\SensitiveParameter] public readonly ?SensitiveString $bearer = null,
        public readonly bool $idempotent = false,
        public readonly array $headers = [],
        public readonly ?float $timeout = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'body' => $this->body === null ? null : '[' . strlen($this->body) . ' bytes, not shown]',
            'bearer' => $this->bearer === null ? null : '[REDACTED]',
            'idempotent' => $this->idempotent,
        ];
    }

    public function withBearer(#[\SensitiveParameter] ?SensitiveString $bearer): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->contentType,
            $this->accept,
            $bearer,
            $this->idempotent,
            $this->headers,
            $this->timeout
        );
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->contentType,
            $this->accept,
            $this->bearer,
            $this->idempotent,
            $headers + $this->headers,
            $this->timeout
        );
    }
}
