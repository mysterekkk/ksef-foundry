<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** HTTP 403 (reasonCode, security.requiredAnyOfPermissions ...). */
class AuthorizationException extends ApiException
{
    public function category(): string
    {
        return 'authorization';
    }

    public function reasonCode(): ?string
    {
        $v = $this->problem()['reasonCode'] ?? null;

        return is_string($v) ? $v : null;
    }
}
