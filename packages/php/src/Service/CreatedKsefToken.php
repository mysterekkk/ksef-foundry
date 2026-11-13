<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\SensitiveString;

/** Response of POST /tokens. The secret token is returned by KSeF only once: store it safely. */
final class CreatedKsefToken
{
    public function __construct(
        public readonly string $referenceNumber,
        #[\SensitiveParameter] public readonly SensitiveString $token,
    ) {
    }
}
