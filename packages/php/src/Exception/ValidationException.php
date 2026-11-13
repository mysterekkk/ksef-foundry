<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

/** HTTP 400 (BadRequestProblemDetails.errors[] or legacy ExceptionResponse). */
class ValidationException extends ApiException
{
    public function category(): string
    {
        return 'validation';
    }
}
