<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Context\ContextIdentifier;

/** Base of the two never-conflated credential types. */
abstract class Credentials
{
    abstract public function context(): ContextIdentifier;

    /** Short stable name of the auth method, part of the tenant key. */
    abstract public function method(): string;

    /**
     * Non-reversible fingerprint (hex SHA-256) of the credential material; part of the tenant key.
     * Never contains the secret itself.
     */
    abstract public function fingerprint(): string;
}
