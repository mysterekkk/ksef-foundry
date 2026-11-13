<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Support;

use LuroWeb\KsefFoundry\Exception\KsefException;

/** Tolerant typed accessors used by DTO::fromArray (unknown keys are ignored). */
final class Arr
{
    /** @param array<mixed> $a */
    public static function string(array $a, string $k): string
    {
        $v = $a[$k] ?? null;
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        throw new KsefException(sprintf('API response field "%s" missing or not a string.', $k));
    }

    /** @param array<mixed> $a */
    public static function stringOrNull(array $a, string $k): ?string
    {
        $v = $a[$k] ?? null;

        return is_string($v) ? $v : null;
    }

    /** @param array<mixed> $a */
    public static function int(array $a, string $k): int
    {
        $v = $a[$k] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }
        throw new KsefException(sprintf('API response field "%s" missing or not an int.', $k));
    }

    /** @param array<mixed> $a */
    public static function intOrNull(array $a, string $k): ?int
    {
        $v = $a[$k] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    /** @param array<mixed> $a */
    public static function bool(array $a, string $k, bool $default = false): bool
    {
        $v = $a[$k] ?? null;

        return is_bool($v) ? $v : $default;
    }

    /** @param array<mixed> $a */
    public static function boolOrNull(array $a, string $k): ?bool
    {
        $v = $a[$k] ?? null;

        return is_bool($v) ? $v : null;
    }

    /** @param array<mixed> $a */
    public static function float(array $a, string $k): float
    {
        $v = $a[$k] ?? null;
        if (is_numeric($v)) {
            return (float) $v;
        }
        throw new KsefException(sprintf('API response field "%s" missing or not a number.', $k));
    }

    /**
     * @param array<mixed> $a
     * @return array<mixed>
     */
    public static function arr(array $a, string $k): array
    {
        $v = $a[$k] ?? null;

        return is_array($v) ? $v : [];
    }

    /**
     * @param array<mixed> $a
     * @return array<mixed>|null
     */
    public static function arrOrNull(array $a, string $k): ?array
    {
        $v = $a[$k] ?? null;

        return is_array($v) ? $v : null;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>
     */
    public static function strings(array $a, string $k): array
    {
        $out = [];
        foreach (self::arr($a, $k) as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** @param array<mixed> $a */
    public static function date(array $a, string $k): \DateTimeImmutable
    {
        $d = self::dateOrNull($a, $k);
        if ($d === null) {
            throw new KsefException(sprintf('API response field "%s" missing or not a date.', $k));
        }

        return $d;
    }

    /** @param array<mixed> $a */
    public static function dateOrNull(array $a, string $k): ?\DateTimeImmutable
    {
        $v = $a[$k] ?? null;
        if (!is_string($v) || $v === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<mixed> $list
     * @return list<array<mixed>>
     */
    public static function objects(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            if (is_array($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
