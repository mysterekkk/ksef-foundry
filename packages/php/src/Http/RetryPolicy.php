<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Http;

/**
 * Retry maths (no I/O). Contract section 6:
 * idempotent requests retry on network errors, 429 and 502/503/504; non-idempotent requests only on 429.
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 4,
        public readonly float $baseDelaySeconds = 0.25,
        public readonly float $maxDelaySeconds = 8.0,
        public readonly int $maxRetryAfterSeconds = 60,
        public readonly bool $jitter = true,
    ) {
    }

    public static function none(): self
    {
        return new self(1);
    }

    public function shouldRetryStatus(int $status, bool $idempotent): bool
    {
        if ($status === 429) {
            return true;
        }

        return $idempotent && in_array($status, [502, 503, 504], true);
    }

    public function shouldRetryNetworkError(bool $idempotent): bool
    {
        return $idempotent;
    }

    /**
     * Delay before the next attempt. $attempt is the 1-based number of the attempt that just failed.
     * A Retry-After value wins over the exponential schedule.
     */
    public function delaySeconds(int $attempt, ?int $retryAfterSeconds = null, ?float $random = null): float
    {
        if ($retryAfterSeconds !== null) {
            return (float) min(max(0, $retryAfterSeconds), $this->maxRetryAfterSeconds);
        }
        $exp = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** max(0, $attempt - 1)));
        if (!$this->jitter) {
            return $exp;
        }
        $r = $random ?? (mt_rand() / mt_getrandmax());

        return $exp / 2 + ($exp / 2) * $r; // "equal jitter"
    }

    /**
     * Parses Retry-After: delta-seconds or HTTP-date. Returns null when absent or unparsable.
     */
    public static function parseRetryAfter(?string $value, ?int $now = null): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return max(0, $ts - ($now ?? time()));
    }
}
