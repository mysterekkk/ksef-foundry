<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Context;

/** Values of AuthenticationContextIdentifierType in the OpenAPI. */
enum ContextIdentifierType: string
{
    case Nip = 'Nip';
    case InternalId = 'InternalId';
    case NipVatUe = 'NipVatUe';
    case PeppolId = 'PeppolId';
}
