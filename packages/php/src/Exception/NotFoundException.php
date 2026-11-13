<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** HTTP 404. */
class NotFoundException extends ApiException
{
    public function category(): string
    {
        return 'not_found';
    }
}
