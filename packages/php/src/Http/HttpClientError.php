<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** Transport-level failure raised by the built-in curl client (PSR-18 NetworkException). */
final class HttpClientError extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly bool $timeout = false,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['message' => $this->getMessage(), 'timeout' => $this->timeout, 'request' => '[method + URI only]'];
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
