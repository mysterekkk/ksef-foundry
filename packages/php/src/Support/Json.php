<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Support;

use LuroWeb\KsefFoundry\Exception\KsefException;

final class Json
{
    /** @return array<mixed> */
    public static function decodeArray(#[\SensitiveParameter] string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Not chained: the JsonException trace holds the raw body (may contain tokens).
            throw new KsefException('Invalid JSON in API response: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new KsefException('Expected a JSON object/array in API response.');
        }

        return $data;
    }

    /** @param array<mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
