<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** HTTP 401 or authentication-operation failure (status 415/425/450 ...). */
class AuthenticationException extends ApiException
{
    public function category(): string
    {
        return 'authentication';
    }
}
