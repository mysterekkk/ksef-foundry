<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Support\Arr;

/** SessionInvoiceStatusResponse: one invoice of a session with its processing status. */
final class InvoiceStatus
{
    public const ACCEPTED = 200;

    public function __construct(
        public readonly int $ordinalNumber,
        public readonly ?string $invoiceNumber,
        public readonly ?string $ksefNumber,
        public readonly string $referenceNumber,
        public readonly string $invoiceHash,
        public readonly ?string $invoiceFileName,
        public readonly ?\DateTimeImmutable $acquisitionDate,
        public readonly \DateTimeImmutable $invoicingDate,
        public readonly ?\DateTimeImmutable $permanentStorageDate,
        public readonly ?string $upoDownloadUrl,
        public readonly ?\DateTimeImmutable $upoDownloadUrlExpirationDate,
        public readonly ?string $invoicingMode,
        public readonly StatusInfo $status,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            Arr::intOrNull($a, 'ordinalNumber') ?? 0,
            Arr::stringOrNull($a, 'invoiceNumber'),
            Arr::stringOrNull($a, 'ksefNumber'),
            Arr::string($a, 'referenceNumber'),
            Arr::stringOrNull($a, 'invoiceHash') ?? '', // required by the schema, absent in some official examples
            Arr::stringOrNull($a, 'invoiceFileName'),
            Arr::dateOrNull($a, 'acquisitionDate'),
            Arr::date($a, 'invoicingDate'),
            Arr::dateOrNull($a, 'permanentStorageDate'),
            Arr::stringOrNull($a, 'upoDownloadUrl'),
            Arr::dateOrNull($a, 'upoDownloadUrlExpirationDate'),
            Arr::stringOrNull($a, 'invoicingMode'),
            StatusInfo::fromArray(Arr::arr($a, 'status')),
        );
    }

    /** Accepted means exactly status 200 (HTTP 202 on send is NOT acceptance). */
    public function isAccepted(): bool
    {
        return $this->status->code === self::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status->code >= 400;
    }

    public function isTerminal(): bool
    {
        return $this->isAccepted() || $this->isRejected();
    }
}
