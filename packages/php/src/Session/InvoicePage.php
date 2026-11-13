<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Support\Arr;

/** SessionInvoicesResponse: a page of session invoices; continue with continuationToken when not null. */
final class InvoicePage
{
    /** @param list<InvoiceStatus> $invoices */
    public function __construct(
        public readonly array $invoices,
        public readonly ?string $continuationToken,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $items = [];
        foreach (Arr::objects(Arr::arr($a, 'invoices')) as $i) {
            $items[] = InvoiceStatus::fromArray($i);
        }
        $t = Arr::stringOrNull($a, 'continuationToken');

        return new self($items, $t === '' ? null : $t);
    }
}
