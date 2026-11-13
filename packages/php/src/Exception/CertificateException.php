<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** Bad/expired certificate, wrong type, bad key or passphrase. */
class CertificateException extends KsefException
{
    public function category(): string
    {
        return 'certificate';
    }
}
