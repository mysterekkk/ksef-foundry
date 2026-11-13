<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

final class TransportOptions
{
    /**
     * @param float $requestTimeout seconds per single HTTP attempt (enforced for the built-in client)
     * @param float $overallDeadline seconds for one logical call including retries and back-off
     * @param list<string> $allowedHosts extra hosts (besides the environment host) that absolute URLs may point to
     */
    public function __construct(
        public readonly float $requestTimeout = 30.0,
        public readonly float $overallDeadline = 120.0,
        public readonly RetryPolicy $retry = new RetryPolicy(),
        public readonly string $userAgent = 'luroweb-ksef-foundry-php/0.1',
        public readonly array $allowedHosts = [],
    ) {
    }
}
