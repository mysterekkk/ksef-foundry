<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/** Long-lived KSeF token (obtained once via POST /tokens after an XAdES login). */
final class KsefTokenCredentials extends Credentials
{
    public function __construct(
        #[\SensitiveParameter] private readonly SensitiveString $token,
        private readonly ContextIdentifier $context,
    ) {
        if ($token->isEmpty()) {
            throw new ConfigurationException('KSeF token must not be empty.');
        }
    }

    public function token(): SensitiveString
    {
        return $this->token;
    }

    public function context(): ContextIdentifier
    {
        return $this->context;
    }

    public function method(): string
    {
        return 'ksef-token';
    }

    public function fingerprint(): string
    {
        return hash('sha256', 'ksef-token:' . $this->token->reveal());
    }
}
