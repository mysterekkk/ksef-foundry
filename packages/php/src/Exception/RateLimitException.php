<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** HTTP 429. The request was not processed; retry after retryAfterSeconds(). */
class RateLimitException extends ApiException
{
    public function category(): string
    {
        return 'rate_limit';
    }

    public function retryable(): bool
    {
        return true;
    }
}
