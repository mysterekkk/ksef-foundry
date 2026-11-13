<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Psr\Log\LoggerInterface;

/** Small helpers shared by the queue jobs. @internal */
final class JobSupport
{
    public static function cache(): Repository
    {
        $store = Config::get('ksef.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    public static function float(string $key, float $default): float
    {
        $v = Config::get('ksef.jobs.' . $key, $default);

        return is_numeric($v) ? (float) $v : $default;
    }

    public static function int(string $key, int $default): int
    {
        $v = Config::get('ksef.jobs.' . $key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        return (bool) Config::get('ksef.jobs.' . $key, $default);
    }

    /** Delay for release(): server-provided Retry-After capped by ksef.jobs.max_release_delay (default 300 s). */
    public static function releaseDelay(?int $requested, int $default = 30): int
    {
        $cap = max(1, self::int('max_release_delay', 300));

        return min($cap, max(1, $requested ?? $default));
    }

    public static function logger(): LoggerInterface
    {
        $channel = Config::get('ksef.log.channel');

        return new RedactingLogger(app('log')->channel(is_string($channel) && $channel !== '' ? $channel : null));
    }

    /**
     * Secret-free description of a failure for logs.
     *
     * @return array<string, mixed>
     */
    public static function describe(\Throwable $e): array
    {
        if ($e instanceof \LuroWeb\KsefFoundry\Exception\KsefException) {
            return $e->diagnostics();
        }

        return ['class' => $e::class];
    }
}
