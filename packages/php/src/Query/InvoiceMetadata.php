<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Query;

use LuroWeb\KsefFoundry\Session\FormCode;
use LuroWeb\KsefFoundry\Support\Arr;

/**
 * InvoiceMetadata. Amounts are exposed as strings with 2 decimals (never float) for exact bcmath use.
 */
final class InvoiceMetadata
{
    public function __construct(
        public readonly string $ksefNumber,
        public readonly string $invoiceNumber,
        public readonly string $issueDate,
        public readonly \DateTimeImmutable $invoicingDate,
        public readonly \DateTimeImmutable $acquisitionDate,
        public readonly \DateTimeImmutable $permanentStorageDate,
        public readonly string $sellerNip,
        public readonly ?string $sellerName,
        public readonly string $buyerIdentifierType,
        public readonly ?string $buyerIdentifierValue,
        public readonly ?string $buyerName,
        public readonly string $netAmount,
        public readonly string $grossAmount,
        public readonly string $vatAmount,
        public readonly string $currency,
        public readonly string $invoicingMode,
        public readonly string $invoiceType,
        public readonly FormCode $formCode,
        public readonly bool $isSelfInvoicing,
        public readonly bool $hasAttachment,
        public readonly string $invoiceHash,
        public readonly ?string $hashOfCorrectedInvoice,
    ) {
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $seller = Arr::arr($a, 'seller');
        $buyer = Arr::arr($a, 'buyer');
        $buyerId = Arr::arr($buyer, 'identifier');

        return new self(
            Arr::string($a, 'ksefNumber'),
            Arr::string($a, 'invoiceNumber'),
            Arr::string($a, 'issueDate'),
            Arr::date($a, 'invoicingDate'),
            Arr::date($a, 'acquisitionDate'),
            Arr::date($a, 'permanentStorageDate'),
            Arr::string($seller, 'nip'),
            Arr::stringOrNull($seller, 'name'),
            Arr::stringOrNull($buyerId, 'type') ?? '',
            Arr::stringOrNull($buyerId, 'value'),
            Arr::stringOrNull($buyer, 'name'),
            self::money($a, 'netAmount'),
            self::money($a, 'grossAmount'),
            self::money($a, 'vatAmount'),
            Arr::string($a, 'currency'),
            Arr::stringOrNull($a, 'invoicingMode') ?? '',
            Arr::stringOrNull($a, 'invoiceType') ?? '',
            FormCode::fromArray(Arr::arr($a, 'formCode')),
            Arr::bool($a, 'isSelfInvoicing'),
            Arr::bool($a, 'hasAttachment'),
            Arr::string($a, 'invoiceHash'),
            Arr::stringOrNull($a, 'hashOfCorrectedInvoice'),
        );
    }

    /** @param array<mixed> $a */
    private static function money(array $a, string $k): string
    {
        return number_format(Arr::float($a, $k), 2, '.', '');
    }

    /** The timestamp that corresponds to the requested DateType (Issue has no time part). */
    public function dateFor(DateType $type): \DateTimeImmutable
    {
        return match ($type) {
            DateType::Issue => new \DateTimeImmutable($this->issueDate . 'T00:00:00+00:00'),
            DateType::Invoicing => $this->invoicingDate,
            DateType::PermanentStorage => $this->permanentStorageDate,
        };
    }
}
