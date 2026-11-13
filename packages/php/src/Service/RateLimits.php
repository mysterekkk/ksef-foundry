<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Support\Arr;

/**
 * GET /rate-limits (EffectiveApiRateLimits): group name (onlineSession, invoiceSend, invoiceMetadata, ...)
 * to [perSecond, perMinute, perHour]. Unknown additive groups are kept.
 */
final class RateLimits
{
    /** @param array<string, array{perSecond: int, perMinute: int, perHour: int}> $groups */
    public function __construct(public readonly array $groups)
    {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $groups = [];
        foreach ($a as $name => $v) {
            if (!is_string($name) || !is_array($v)) {
                continue;
            }
            $groups[$name] = [
                'perSecond' => Arr::intOrNull($v, 'perSecond') ?? 0,
                'perMinute' => Arr::intOrNull($v, 'perMinute') ?? 0,
                'perHour' => Arr::intOrNull($v, 'perHour') ?? 0,
            ];
        }

        return new self($groups);
    }

    /** @return array{perSecond: int, perMinute: int, perHour: int}|null */
    public function group(string $name): ?array
    {
        return $this->groups[$name] ?? null;
    }
}
