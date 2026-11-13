<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Fluent builder for the SUPPORTED FA(3) variant: standard VAT invoice (RodzajFaktury = VAT),
 * PLN only, domestic rates 23/22/8/7/5, "0 KR", "0 EX", "0 WDT" (EU buyer only), zw, "np I", "np II".
 * Everything else is rejected with {@see InvoiceBuilderException} (see docs/dev/fa3-scope.md).
 *
 * Money inputs are int|string|Decimal, never float.
 *
 * @phpstan-import-type Addr from Fa3XmlWriter
 * @phpstan-import-type Seller from Fa3XmlWriter
 * @phpstan-import-type Buyer from Fa3XmlWriter
 * @phpstan-import-type Line from Fa3XmlWriter
 * @phpstan-import-type Exemption from Fa3XmlWriter
 * @phpstan-import-type Account from Fa3XmlWriter
 * @phpstan-import-type Model from Fa3XmlWriter
 */
final class Fa3InvoiceBuilder
{
    private const EU = [
        'AT',
        'BE',
        'BG',
        'CY',
        'CZ',
        'DK',
        'EE',
        'FI',
        'FR',
        'DE',
        'EL',
        'HR',
        'HU',
        'IE',
        'IT',
        'LV',
        'LT',
        'LU',
        'MT',
        'NL',
        'PL',
        'PT',
        'RO',
        'SK',
        'SI',
        'ES',
        'SE',
        'XI',
    ];

    /** @var ?Seller */
    private ?array $seller = null;
    /** @var ?Buyer */
    private ?array $buyer = null;
    private ?string $number = null;
    private ?string $issueDate = null;
    private ?string $issuePlace = null;
    private ?string $saleDate = null;
    private ?string $createdAt = null;
    private ?string $systemInfo = null;
    /** @var list<Line> */
    private array $lines = [];
    /** @var ?Exemption */
    private ?array $exemption = null;
    private ?string $paidDate = null;
    private ?int $paymentMethod = null;
    /** @var list<string> */
    private array $dueDates = [];
    /** @var list<Account> */
    private array $accounts = [];
    /** @var list<array{string, string}> */
    private array $info = [];
    private ?string $footer = null;

    private bool $selfValidate = true;

    public static function create(): self
    {
        return new self();
    }

    /** Disable the final XSD self-check inside build() (not recommended). */
    public function withoutSelfValidation(): self
    {
        $this->selfValidate = false;
        return $this;
    }

    public function seller(string $nip, string $name, Fa3Address $address): self
    {
        $this->seller = [
            'nip' => self::nip($nip, 'seller.nip'),
            'name' => Fa3Text::token($name, 512, 'seller.name'),
            'address' => self::addr($address, 'seller.address'),
        ];
        return $this;
    }

    public function buyerNip(string $nip, string $name, Fa3Address $address): self
    {
        return $this->setBuyer('nip', $name, $address, ['nip' => self::nip($nip, 'buyer.nip')]);
    }

    /**
     * EU VAT buyer: $prefix is one of TKodyKrajowUE (e.g. "DE", note "EL" for Greece),
     * the VAT number has up to 12 chars [0-9A-Z+*].
     */
    public function buyerEu(string $prefix, string $vatNumber, string $name, Fa3Address $address): self
    {
        if (!in_array($prefix, self::EU, true)) {
            throw new InvoiceBuilderException(
                sprintf('buyer.euPrefix "%s" is not an EU VAT prefix allowed by the XSD (TKodyKrajowUE).', $prefix),
            );
        }
        if (preg_match('/\A[0-9A-Z+*]{1,12}\z/', $vatNumber) !== 1) {
            throw new InvoiceBuilderException(
                'buyer.euVatNumber must match [0-9A-Z+*]{1,12} (without the country prefix).',
            );
        }
        return $this->setBuyer('eu', $name, $address, ['euPrefix' => $prefix, 'euNumber' => $vatNumber]);
    }

    /** Non-EU / other tax id (NrID, 1..50 chars) with optional issuing country code. */
    public function buyerForeign(?string $countryCode, string $taxId, string $name, Fa3Address $address): self
    {
        if ($countryCode !== null && preg_match('/\A[A-Z]{2}\z/', $countryCode) !== 1) {
            throw new InvoiceBuilderException('buyer.countryCode must be a 2-letter upper-case code.');
        }
        return $this->setBuyer(
            'foreign',
            $name,
            $address,
            ['country' => $countryCode, 'taxId' => Fa3Text::token($taxId, 50, 'buyer.taxId')],
        );
    }

    /** Buyer without any tax identifier (BrakID = 1). */
    public function buyerNoId(string $name, Fa3Address $address): self
    {
        return $this->setBuyer('none', $name, $address, []);
    }

    public function number(string $invoiceNumber): self
    {
        $this->number = Fa3Text::token($invoiceNumber, 256, 'number (P_2)');
        return $this;
    }

    public function issueDate(string $date): self
    {
        $this->issueDate = Fa3Text::date($date, 'issueDate (P_1)', '2006-01-01', '2050-01-01');
        return $this;
    }

    public function issuePlace(string $place): self
    {
        $this->issuePlace = Fa3Text::token($place, 256, 'issuePlace (P_1M)');
        return $this;
    }

    /** P_6: common date of supply, when it differs from the issue date. */
    public function saleDate(string $date): self
    {
        $this->saleDate = Fa3Text::date($date, 'saleDate (P_6)', '2006-01-01', '2050-01-01');
        return $this;
    }

    /** Only "PLN" is supported. */
    public function currency(string $code): self
    {
        if ($code !== 'PLN') {
            throw new InvoiceBuilderException(
                sprintf(
                    'Currency "%s" is not supported: foreign-currency invoices need P_14_nW/KursWalutyZ '
                    . 'semantics that this builder does not implement. Only PLN.',
                    $code,
                ),
            );
        }
        return $this;
    }

    /** DataWytworzeniaFa, UTC, format YYYY-MM-DDTHH:MM:SSZ (XSD minimum 2025-09-01). */
    public function createdAt(string|\DateTimeInterface $at): self
    {
        if ($at instanceof \DateTimeInterface) {
            $at = \DateTimeImmutable::createFromInterface(
                $at,
            )->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $pattern = '/\A(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\dZ\z/';
        if (preg_match($pattern, $at, $x) !== 1 || !checkdate((int) $x[2], (int) $x[3], (int) $x[1])) {
            throw new InvoiceBuilderException('createdAt must be a real UTC timestamp YYYY-MM-DDTHH:MM:SSZ.');
        }
        if ($at < '2025-09-01T00:00:00Z' || $at > '2050-01-01T23:59:59Z') {
            throw new InvoiceBuilderException(
                'createdAt is outside the XSD range 2025-09-01T00:00:00Z..2050-01-01T23:59:59Z.',
            );
        }
        $this->createdAt = $at;
        return $this;
    }

    public function systemInfo(string $name): self
    {
        $this->systemInfo = Fa3Text::token($name, 256, 'systemInfo');
        return $this;
    }

    /**
     * @param int|string|Decimal $quantity P_8B, > 0, max 6 fractional digits
     * @param int|string|Decimal $unitNetPrice P_9A, >= 0, max 8 fractional digits
     * @param int|string|Decimal|null $netValue explicit P_11 (2 digits); default round_half_up(quantity x price, 2)
     */
    public function addLine(
        string $name,
        mixed $quantity,
        mixed $unitNetPrice,
        string|VatRate $vatRate,
        ?string $unit = null,
        mixed $netValue = null,
    ): self {
        $n = count($this->lines) + 1;
        if ($n > 10000) {
            throw new InvoiceBuilderException('FaWiersz is limited to 10000 lines.');
        }
        $f = "lines[$n]";
        $qty = Decimal::of($quantity);
        $price = Decimal::of($unitNetPrice);
        if ($qty->isNegative() || $qty->isZero()) {
            throw new InvoiceBuilderException("$f.quantity must be > 0.");
        }
        if ($price->isNegative()) {
            throw new InvoiceBuilderException(
                "$f.unitNetPrice must be >= 0 (corrections/discount invoices are unsupported).",
            );
        }
        if ($qty->significantScale() > 6 || $qty->integerDigits() > 16) {
            throw new InvoiceBuilderException("$f.quantity exceeds TIlosci (max 16 integer + 6 fractional digits).");
        }
        if ($price->significantScale() > 8 || $price->integerDigits() > 14) {
            throw new InvoiceBuilderException(
                "$f.unitNetPrice exceeds TKwotowy2 (max 14 integer + 8 fractional digits).",
            );
        }
        if ($netValue !== null) {
            $nv = Decimal::of($netValue);
            if ($nv->isNegative() || $nv->significantScale() > 2) {
                throw new InvoiceBuilderException("$f.netValue must be >= 0 with at most 2 fractional digits.");
            }
            $net = Money::exact($nv);
        } else {
            $net = Money::of($qty->mul($price));
        }
        if ($net->decimal()->integerDigits() > 16) {
            throw new InvoiceBuilderException("$f net value exceeds 16 integer digits.");
        }
        $rate = $vatRate instanceof VatRate ? $vatRate : VatRate::parse($vatRate);
        if (!$rate->isSupported()) {
            throw new InvoiceBuilderException(sprintf('VAT rate "%s" is not supported.', $rate->value));
        }
        $this->lines[] = [
            'name' => Fa3Text::token($name, 512, "$f.name (P_7)"),
            'unit' => $unit === null ? null : Fa3Text::token($unit, 256, "$f.unit (P_8A)"),
            'qty' => $qty, 'price' => $price, 'net' => $net, 'rate' => $rate,
        ];
        return $this;
    }

    /**
     * Legal basis for exempt ("zw") lines: required when any line uses "zw".
     * @param string $type statute|directive|other = P_19A (act) / P_19B (directive 2006/112/EC) / P_19C (other)
     */
    public function exemptionBasis(string $text, string $type = 'statute'): self
    {
        $kind = match ($type) {
            'statute', 'directive', 'other' => $type,
            default => throw new InvoiceBuilderException('exemptionBasis type must be statute|directive|other.'),
        };
        $this->exemption = ['type' => $kind, 'text' => Fa3Text::token($text, 256, 'exemptionBasis')];
        return $this;
    }

    /** FormaPlatnosci 1 cash, 2 card, 3 voucher, 4 cheque, 5 credit, 6 transfer, 7 mobile. */
    public function paymentMethod(int $method): self
    {
        if ($method < 1 || $method > 7) {
            throw new InvoiceBuilderException('paymentMethod must be 1..7 (TFormaPlatnosci).');
        }
        $this->paymentMethod = $method;
        return $this;
    }

    /** Marks the invoice as paid (Zaplacono=1, DataZaplaty). */
    public function paid(string $date): self
    {
        $d = Fa3Text::date($date, 'paidDate', '2016-07-01', '2050-01-01');
        $this->paidDate = $d;
        return $this;
    }

    /** TerminPlatnosci/Termin (max 100). */
    public function dueDate(string $date): self
    {
        if (count($this->dueDates) >= 100) {
            throw new InvoiceBuilderException('At most 100 due dates.');
        }
        $this->dueDates[] = Fa3Text::date($date, 'dueDate', '2016-07-01', '2050-01-01');
        return $this;
    }

    public function bankAccount(
        string $number,
        ?string $swift = null,
        ?string $bankName = null,
        ?string $description = null,
    ): self {
        if (count($this->accounts) >= 100) {
            throw new InvoiceBuilderException('At most 100 bank accounts.');
        }
        if (preg_match('/\A[0-9A-Za-z]{10,34}\z/', $number) !== 1) {
            throw new InvoiceBuilderException(
                'bankAccount.number must be 10..34 alphanumeric characters without spaces.',
            );
        }
        if ($swift !== null && preg_match('/\A[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\z/', $swift) !== 1) {
            throw new InvoiceBuilderException('bankAccount.swift must be a valid SWIFT/BIC (8 or 11 chars).');
        }
        $this->accounts[] = [
            'number' => $number, 'swift' => $swift,
            'bankName' => $bankName === null ? null : Fa3Text::token($bankName, 256, 'bankAccount.bankName'),
            'description' => $description === null ? null : Fa3Text::token(
                $description,
                256,
                'bankAccount.description',
            ),
        ];
        return $this;
    }

    /** DodatkowyOpis key/value note (both 1..256 chars). */
    public function additionalInfo(string $key, string $value): self
    {
        if (count($this->info) >= 10000) {
            throw new InvoiceBuilderException('At most 10000 DodatkowyOpis entries.');
        }
        $this->info[] = [
            Fa3Text::token($key, 256, 'additionalInfo.key'),
            Fa3Text::token($value, 256, 'additionalInfo.value'),
        ];
        return $this;
    }

    /** Stopka/Informacje/StopkaFaktury, 1..3500 chars. */
    public function footer(string $text): self
    {
        $this->footer = Fa3Text::text($text, 3500, 'footer');
        return $this;
    }

    /** Exact totals (P_13_n, P_14_n, P_15) for the lines added so far. */
    public function totals(): Fa3Totals
    {
        if ($this->lines === []) {
            throw new InvoiceBuilderException('At least one line is required.');
        }
        return Fa3Totals::calculate(
            array_map(static fn (array $l): array => ['net' => $l['net'], 'rate' => $l['rate']], $this->lines),
        );
    }

    /**
     * @throws InvoiceBuilderException before any XML is produced
     * @throws InvoiceValidationException if the XSD self-check fails
     */
    public function build(): string
    {
        $model = $this->model();
        $totals = $this->totals();
        if ($totals->gross->decimal()->integerDigits() > 16) {
            throw new InvoiceBuilderException('Gross amount exceeds 16 integer digits.');
        }
        $xml = Fa3XmlWriter::write($model, $totals);
        if ($this->selfValidate) {
            (new XmlValidator())->assertValid($xml);
        }
        return $xml;
    }

    /** @param array<array-key, mixed> $model language-neutral model, see fixtures/fa3/README.md */
    public static function fromArray(array $model): self
    {
        return Fa3ModelLoader::load($model);
    }

    /**
     * Renders a complete, schema-valid synthetic invoice for live TEST runs: buyer without tax id,
     * one 23% and one 8% line, issue date = today (UTC) so that P_1 is never later than receipt.
     */
    public static function sample(string $sellerNip, string $invoiceNumber, ?\DateTimeInterface $now = null): self
    {
        $at = \DateTimeImmutable::createFromInterface(
            $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        )->setTimezone(new \DateTimeZone('UTC'));
        return self::create()
            ->seller($sellerNip, 'KSeF Foundry Test Seller', new Fa3Address('ul. Testowa 1', '00-001 Warszawa'))
            ->buyerNoId('Test Buyer', new Fa3Address('ul. Probna 2', '00-002 Warszawa'))
            ->number($invoiceNumber)
            ->issueDate($at->format('Y-m-d'))
            ->createdAt($at)
            ->systemInfo('KSeF Foundry')
            ->addLine('Usluga testowa', 1, '100.00', '23', 'szt.')
            ->addLine('Towar testowy', 2, '10.50', '8', 'szt.')
            ->paymentMethod(6)
            ->dueDate($at->modify('+14 days')->format('Y-m-d'));
    }

    /** Same as {@see self::sample()} but returns the XML string. */
    public static function sampleXml(string $sellerNip, string $invoiceNumber, ?\DateTimeInterface $now = null): string
    {
        return self::sample($sellerNip, $invoiceNumber, $now)->build();
    }

    /** NIP control-sum check (KSeF PROD enforces it; XSD does not). */
    public static function isValidNipChecksum(string $nip): bool
    {
        if (preg_match('/\A\d{10}\z/', $nip) !== 1) {
            return false;
        }
        $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $s = 0;
        for ($i = 0; $i < 9; $i++) {
            $s += $w[$i] * (int) $nip[$i];
        }
        return $s % 11 === (int) $nip[9];
    }

    /**
     * Complete, validated model (what the writer serialises). Throws when required parts are missing.
     *
     * @return Model
     */
    public function model(): array
    {
        $required = [
            'seller' => $this->seller,
            'buyer' => $this->buyer,
            'number' => $this->number,
            'issueDate' => $this->issueDate,
            'createdAt' => $this->createdAt,
        ];
        foreach ($required as $k => $v) {
            if ($v === null) {
                throw new InvoiceBuilderException(sprintf('Required field "%s" is missing.', $k));
            }
        }
        if (
            $this->seller === null || $this->buyer === null || $this->number === null
            || $this->issueDate === null || $this->createdAt === null
        ) {
            throw new InvoiceBuilderException('Required field is missing.');
        }
        if ($this->lines === []) {
            throw new InvoiceBuilderException('At least one line is required.');
        }
        $hasZw = false;
        $hasWdt = false;
        foreach ($this->lines as $l) {
            $hasZw = $hasZw || $l['rate'] === VatRate::ZW;
            $hasWdt = $hasWdt || $l['rate'] === VatRate::ZERO_WDT;
        }
        if ($hasZw && $this->exemption === null) {
            throw new InvoiceBuilderException('Lines with rate "zw" require exemptionBasis() (P_19A/P_19B/P_19C).');
        }
        if (!$hasZw && $this->exemption !== null) {
            throw new InvoiceBuilderException('exemptionBasis() given but no line uses rate "zw".');
        }
        if ($hasWdt && $this->buyer['kind'] !== 'eu') {
            throw new InvoiceBuilderException('Rate "0 WDT" requires an EU VAT buyer (buyerEu()).');
        }
        $payment = null;
        if (
            $this->paidDate !== null || $this->paymentMethod !== null
            || $this->dueDates !== [] || $this->accounts !== []
        ) {
            $payment = [
                'paidDate' => $this->paidDate,
                'dueDates' => $this->dueDates,
                'method' => $this->paymentMethod,
                'accounts' => $this->accounts,
            ];
        }
        return [
            'seller' => $this->seller, 'buyer' => $this->buyer, 'number' => $this->number,
            'issueDate' => $this->issueDate,
            'issuePlace' => $this->issuePlace, 'saleDate' => $this->saleDate, 'createdAt' => $this->createdAt,
            'systemInfo' => $this->systemInfo, 'lines' => $this->lines, 'exemption' => $this->exemption,
            'payment' => $payment, 'info' => $this->info, 'footer' => $this->footer,
        ];
    }

    /**
     * @param 'nip'|'eu'|'foreign'|'none' $kind
     * @param array{nip?: string, euPrefix?: string, euNumber?: string, country?: ?string, taxId?: string} $ids
     */
    private function setBuyer(string $kind, string $name, Fa3Address $address, array $ids): self
    {
        $this->buyer = [
            'kind' => $kind,
            'nip' => $ids['nip'] ?? null,
            'euPrefix' => $ids['euPrefix'] ?? null,
            'euNumber' => $ids['euNumber'] ?? null,
            'country' => $ids['country'] ?? null,
            'taxId' => $ids['taxId'] ?? null,
            'name' => Fa3Text::token($name, 512, 'buyer.name'),
            'address' => self::addr($address, 'buyer.address'),
        ];
        return $this;
    }

    private static function nip(string $nip, string $field): string
    {
        if (preg_match('/\A[1-9]((\d[1-9])|([1-9]\d))\d{7}\z/', $nip) !== 1) {
            throw new InvoiceBuilderException(
                sprintf('%s must be 10 digits matching the XSD TNrNIP pattern (no dashes, no PL prefix).', $field),
            );
        }
        return $nip;
    }

    /** @return Addr */
    private static function addr(Fa3Address $a, string $field): array
    {
        if (preg_match('/\A[A-Z]{2}\z/', $a->country) !== 1) {
            throw new InvoiceBuilderException("$field.country must be a 2-letter upper-case ISO code.");
        }
        return [
            'country' => $a->country,
            'line1' => Fa3Text::token($a->line1, 512, "$field.line1"),
            'line2' => $a->line2 === null ? null : Fa3Text::token($a->line2, 512, "$field.line2"),
        ];
    }
}
