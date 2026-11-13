<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http\Psr7;

use Psr\Http\Message\ResponseInterface;

final class Response implements ResponseInterface
{
    use MessageTrait;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        string $body = '',
        private string $reason = '',
    ) {
        $this->initHeaders($headers);
        $this->body = new Stream($body);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $c = clone $this;
        $c->status = $code;
        $c->reason = $reasonPhrase;

        return $c;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason;
    }
}
