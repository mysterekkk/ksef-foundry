<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** Connect/DNS/TLS/reset failure. Retryable for idempotent requests only. */
class NetworkException extends KsefException
{
    public function retryable(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'network';
    }
}
