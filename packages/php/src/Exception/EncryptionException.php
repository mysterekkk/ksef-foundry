<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** Local encryption/decryption failure or invalid public key material. */
class EncryptionException extends KsefException
{
    public function category(): string
    {
        return 'encryption';
    }
}
