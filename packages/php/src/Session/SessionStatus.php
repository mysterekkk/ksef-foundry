<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Support\Arr;

/** SessionStatusResponse (GET /sessions/{ref}). */
final class SessionStatus
{
    /**
     * @param list<UpoPage> $upoPages
     */
    public function __construct(
        public readonly StatusInfo $status,
        public readonly \DateTimeImmutable $dateCreated,
        public readonly \DateTimeImmutable $dateUpdated,
        public readonly ?\DateTimeImmutable $validUntil,
        public readonly array $upoPages,
        public readonly ?int $invoiceCount,
        public readonly ?int $successfulInvoiceCount,
        public readonly ?int $failedInvoiceCount,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $pages = [];
        foreach (Arr::objects(Arr::arr(Arr::arr($a, 'upo'), 'pages')) as $p) {
            $pages[] = UpoPage::fromArray($p);
        }

        return new self(
            StatusInfo::fromArray(Arr::arr($a, 'status')),
            Arr::date($a, 'dateCreated'),
            Arr::date($a, 'dateUpdated'),
            Arr::dateOrNull($a, 'validUntil'),
            $pages,
            Arr::intOrNull($a, 'invoiceCount'),
            Arr::intOrNull($a, 'successfulInvoiceCount'),
            Arr::intOrNull($a, 'failedInvoiceCount'),
        );
    }
}
