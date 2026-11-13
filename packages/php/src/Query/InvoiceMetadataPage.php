<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Query;

use LuroWeb\KsefFoundry\Support\Arr;

/** QueryInvoicesMetadataResponse. */
final class InvoiceMetadataPage
{
    /** @param list<InvoiceMetadata> $invoices */
    public function __construct(
        public readonly array $invoices,
        public readonly bool $hasMore,
        public readonly bool $isTruncated,
        public readonly ?\DateTimeImmutable $permanentStorageHwmDate,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $items = [];
        foreach (Arr::objects(Arr::arr($a, 'invoices')) as $i) {
            $items[] = InvoiceMetadata::fromArray($i);
        }

        return new self(
            $items,
            Arr::bool($a, 'hasMore'),
            Arr::bool($a, 'isTruncated'),
            Arr::dateOrNull($a, 'permanentStorageHwmDate'),
        );
    }
}
