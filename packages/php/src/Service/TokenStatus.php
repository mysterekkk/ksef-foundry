<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

/** AuthenticationTokenStatus from the OpenAPI. */
enum TokenStatus: string
{
    case Pending = 'Pending';
    case Active = 'Active';
    case Revoking = 'Revoking';
    case Revoked = 'Revoked';
    case Failed = 'Failed';
}
