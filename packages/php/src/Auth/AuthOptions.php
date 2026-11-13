<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

final class AuthOptions
{
    /**
     * @param int $expirySkewSeconds access token is renewed when exp - now < skew (contract: 60)
     * @param float $pollTimeoutSeconds overall deadline for the asynchronous login to reach status 200
     * @param float $pollIntervalSeconds delay between status polls
     * @param int $rejectionBackoffSeconds after the server rejected a fresh login/token (401, auth status 425/450 ...)
     *                                     fail fast with the same error for this long instead of logging in on every call
     */
    public function __construct(
        public readonly int $expirySkewSeconds = 60,
        public readonly float $pollTimeoutSeconds = 120.0,
        public readonly float $pollIntervalSeconds = 1.0,
        public readonly int $rejectionBackoffSeconds = 30,
    ) {
    }
}
