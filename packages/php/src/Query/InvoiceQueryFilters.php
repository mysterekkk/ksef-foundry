<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Query;

use LuroWeb\KsefFoundry\Exception\ConfigurationException;

/**
 * InvoiceQueryFilters. Dates are converted to UTC explicitly. KSeF accepts a dateRange of at most 100 days (UTC);
 * InvoiceQueryService::iterate() splits longer ranges automatically.
 */
final class InvoiceQueryFilters
{
    public const MAX_RANGE_DAYS = 100;

    /**
     * @param list<string> $currencyCodes
     * @param list<string> $invoiceTypes InvoiceType values (Vat, Kor, Zal, ...)
     * @param array{type: string, from?: float|null, to?: float|null}|null $amount
     * @param array{type: string, value?: string|null}|null $buyerIdentifier BuyerIdentifierType: Nip|VatUe|Other|None
     */
    public function __construct(
        public readonly SubjectType $subjectType,
        public readonly DateType $dateType,
        public readonly \DateTimeInterface $from,
        public readonly ?\DateTimeInterface $to = null,
        public readonly ?bool $restrictToPermanentStorageHwmDate = null,
        public readonly ?string $ksefNumber = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $sellerNip = null,
        public readonly ?array $buyerIdentifier = null,
        public readonly ?array $amount = null,
        public readonly array $currencyCodes = [],
        public readonly ?string $invoicingMode = null,
        public readonly ?bool $isSelfInvoicing = null,
        public readonly ?string $formType = null,
        public readonly array $invoiceTypes = [],
        public readonly ?bool $hasAttachment = null,
    ) {
    }

    public static function utc(\DateTimeInterface $d): string
    {
        return \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    /** Same filters with another date window (used for pagination beyond the 10 000 record limit and splitting). */
    public function withRange(\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        return new self(
            $this->subjectType,
            $this->dateType,
            $from,
            $to,
            $this->restrictToPermanentStorageHwmDate,
            $this->ksefNumber,
            $this->invoiceNumber,
            $this->sellerNip,
            $this->buyerIdentifier,
            $this->amount,
            $this->currencyCodes,
            $this->invoicingMode,
            $this->isSelfInvoicing,
            $this->formType,
            $this->invoiceTypes,
            $this->hasAttachment,
        );
    }

    /** @throws ConfigurationException when the range is invalid or longer than 100 days (UTC) */
    public function assertValidRange(?\DateTimeInterface $now = null): void
    {
        $from = $this->from->getTimestamp();
        $to = ($this->to ?? $now ?? new \DateTimeImmutable())->getTimestamp();
        if ($to < $from) {
            throw new ConfigurationException('dateRange.to must not be earlier than dateRange.from.');
        }
        if ($to - $from > self::MAX_RANGE_DAYS * 86400) {
            throw new ConfigurationException('dateRange longer than 100 days; use InvoiceQueryService::iterate() which splits it.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $range = ['dateType' => $this->dateType->value, 'from' => self::utc($this->from)];
        if ($this->to !== null) {
            $range['to'] = self::utc($this->to);
        }
        if ($this->restrictToPermanentStorageHwmDate !== null) {
            $range['restrictToPermanentStorageHwmDate'] = $this->restrictToPermanentStorageHwmDate;
        }
        $out = ['subjectType' => $this->subjectType->value, 'dateRange' => $range];
        $optional = [
            'ksefNumber' => $this->ksefNumber,
            'invoiceNumber' => $this->invoiceNumber,
            'sellerNip' => $this->sellerNip,
            'buyerIdentifier' => $this->buyerIdentifier,
            'amount' => $this->amount,
            'currencyCodes' => $this->currencyCodes === [] ? null : $this->currencyCodes,
            'invoicingMode' => $this->invoicingMode,
            'isSelfInvoicing' => $this->isSelfInvoicing,
            'formType' => $this->formType,
            'invoiceTypes' => $this->invoiceTypes === [] ? null : $this->invoiceTypes,
            'hasAttachment' => $this->hasAttachment,
        ];
        foreach ($optional as $k => $v) {
            if ($v !== null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
