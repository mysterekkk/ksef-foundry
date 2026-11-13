<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Http\HttpRequest;

/** GET /limits/context, /limits/subject, /rate-limits. */
final class LimitsService
{
    public function __construct(private readonly AuthorizedClient $api)
    {
    }

    public function context(): ContextLimits
    {
        return ContextLimits::fromArray($this->api->send(new HttpRequest('GET', '/limits/context', idempotent: true))->json());
    }

    public function subject(): SubjectLimits
    {
        return SubjectLimits::fromArray($this->api->send(new HttpRequest('GET', '/limits/subject', idempotent: true))->json());
    }

    public function rateLimits(): RateLimits
    {
        return RateLimits::fromArray($this->api->send(new HttpRequest('GET', '/rate-limits', idempotent: true))->json());
    }
}
