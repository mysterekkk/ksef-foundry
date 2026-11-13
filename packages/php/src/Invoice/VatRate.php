<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * FA(3) P_12 (XSD TStawkaPodatku) tokens and their summary bucket (P_13_n / P_14_n).
 *
 * Mapping verified against the XSD annotations of schemat_FA3_v1-0E.xsd:
 *  23/22 -> P_13_1/P_14_1 ; 8/7 -> P_13_2/P_14_2 ; 5 -> P_13_3/P_14_3 ;
 *  4/3 (taxi flat rate) -> P_13_4/P_14_4 ; "0 KR" -> P_13_6_1 ; "0 WDT" -> P_13_6_2 ;
 *  "0 EX" -> P_13_6_3 ; zw -> P_13_7 ; "np I" -> P_13_8 ; "np II" -> P_13_9 ; oo -> P_13_10.
 */
enum VatRate: string
{
    case R23 = '23';
    case R22 = '22';
    case R8 = '8';
    case R7 = '7';
    case R5 = '5';
    case R4 = '4';
    case R3 = '3';
    case ZERO_KR = '0 KR';
    case ZERO_WDT = '0 WDT';
    case ZERO_EX = '0 EX';
    case ZW = 'zw';
    case OO = 'oo';
    case NP_I = 'np I';
    case NP_II = 'np II';

    /** Bucket suffix used in P_13_<suffix> (and P_14_<suffix> when {@see self::hasVatAmount()}). */
    public function bucket(): string
    {
        return match ($this) {
            self::R23, self::R22 => '1',
            self::R8, self::R7 => '2',
            self::R5 => '3',
            self::R4, self::R3 => '4',
            self::ZERO_KR => '6_1',
            self::ZERO_WDT => '6_2',
            self::ZERO_EX => '6_3',
            self::ZW => '7',
            self::NP_I => '8',
            self::NP_II => '9',
            self::OO => '10',
        };
    }

    public function percent(): ?Decimal
    {
        return match ($this) {
            self::R23, self::R22, self::R8, self::R7, self::R5, self::R4, self::R3 => Decimal::of($this->value),
            default => null,
        };
    }

    /** True for rates that have a P_14_n VAT amount. */
    public function hasVatAmount(): bool
    {
        return $this->percent() !== null;
    }

    /** Whether the builder offers this rate (others are rejected, see docs/dev/fa3-scope.md). */
    public function isSupported(): bool
    {
        return match ($this) {
            self::R4, self::R3, self::OO => false,
            default => true,
        };
    }

    public static function parse(string $token): self
    {
        $r = self::tryFrom($token);
        if ($r === null) {
            throw new InvoiceBuilderException(sprintf(
                'Unknown VAT rate token "%s". Allowed P_12 tokens: %s.',
                $token,
                implode(', ', array_map(static fn (self $c): string => $c->value, self::cases())),
            ));
        }
        if (!$r->isSupported()) {
            throw new InvoiceBuilderException(
                sprintf(
                    'VAT rate "%s" is a valid P_12 token but is not supported by this builder '
                    . '(see docs/dev/fa3-scope.md).',
                    $token,
                ),
            );
        }
        return $r;
    }
}
