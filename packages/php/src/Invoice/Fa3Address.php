<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/** FA(3) TAdres: KodKraju + AdresL1 (+ AdresL2). Free-form lines, max 512 chars each. */
final class Fa3Address
{
    public function __construct(
        public readonly string $line1,
        public readonly ?string $line2 = null,
        public readonly string $country = 'PL',
    ) {
    }

    /** @param array<array-key, mixed> $a keys: line1, line2?, country? */
    public static function fromArray(array $a, string $path = 'address'): self
    {
        $unknown = array_diff(array_keys($a), ['line1', 'line2', 'country']);
        if ($unknown !== []) {
            throw new InvoiceBuilderException(
                sprintf('%s: unknown key(s): %s', $path, implode(', ', array_map('strval', $unknown))),
            );
        }
        if (!isset($a['line1']) || !is_string($a['line1'])) {
            throw new InvoiceBuilderException($path . '.line1 is required (string).');
        }
        $line2 = $a['line2'] ?? null;
        $country = $a['country'] ?? 'PL';
        if ($line2 !== null && !is_string($line2)) {
            throw new InvoiceBuilderException($path . '.line2 must be a string.');
        }
        if (!is_string($country)) {
            throw new InvoiceBuilderException($path . '.country must be a string.');
        }
        return new self($a['line1'], $line2, $country);
    }
}
