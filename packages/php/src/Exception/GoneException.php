<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** HTTP 410. */
class GoneException extends ApiException
{
    public function category(): string
    {
        return 'gone';
    }
}
