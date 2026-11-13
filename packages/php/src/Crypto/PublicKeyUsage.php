<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Crypto;

enum PublicKeyUsage: string
{
    case KsefTokenEncryption = 'KsefTokenEncryption';
    case SymmetricKeyEncryption = 'SymmetricKeyEncryption';
}
