<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Testing;

use LuroWeb\KsefFoundry\Support\SensitiveString;

/** TEST-ONLY self-signed certificate and its private key. */
final class TestCertificate
{
    public function __construct(
        public readonly string $certificatePem,
        #[\SensitiveParameter] public readonly SensitiveString $privateKeyPem,
    ) {
    }
}
