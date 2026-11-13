<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Support\Arr;

/** StatusInfo / InvoiceStatusInfo from the OpenAPI. */
final class StatusInfo
{
    /**
     * @param list<string> $details
     * @param array<string, string|null> $extensions
     */
    public function __construct(
        public readonly int $code,
        public readonly string $description,
        public readonly array $details = [],
        public readonly array $extensions = [],
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $ext = [];
        foreach (Arr::arr($a, 'extensions') as $k => $v) {
            if (is_string($k) && (is_string($v) || $v === null)) {
                $ext[$k] = $v;
            }
        }

        return new self(Arr::int($a, 'code'), Arr::stringOrNull($a, 'description') ?? '', Arr::strings($a, 'details'), $ext);
    }
}
