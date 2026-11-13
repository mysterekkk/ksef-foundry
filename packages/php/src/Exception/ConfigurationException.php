<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** Invalid SDK configuration or forbidden operation for the selected environment. */
class ConfigurationException extends KsefException
{
    public function category(): string
    {
        return 'configuration';
    }
}
