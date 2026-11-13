<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** Request or overall deadline exceeded. */
class TimeoutException extends NetworkException
{
    public function category(): string
    {
        return 'timeout';
    }
}
