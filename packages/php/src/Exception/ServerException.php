<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** HTTP 5xx. */
class ServerException extends ApiException
{
    public function category(): string
    {
        return 'server';
    }

    public function retryable(): bool
    {
        return in_array($this->statusCode(), [502, 503, 504], true);
    }
}
