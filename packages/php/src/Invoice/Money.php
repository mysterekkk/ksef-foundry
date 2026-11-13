<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Amount with exactly 2 fractional digits (XSD TKwotowy: fractionDigits 2).
 * Construction rounds half-up; use {@see self::exact()} to reject sub-grosz precision.
 */
final class Money implements \JsonSerializable, \Stringable
{
    private function __construct(private readonly Decimal $amount)
    {
    }

    /** @param int|string|Decimal $v */
    public static function of(mixed $v): self
    {
        return new self(Decimal::of($v)->round(2));
    }

    /** @param int|string|Decimal $v */
    public static function exact(mixed $v): self
    {
        $d = Decimal::of($v);
        if ($d->significantScale() > 2) {
            throw new InvoiceBuilderException(
                sprintf('Amount "%s" has more than 2 fractional digits.', $d->toString()),
            );
        }
        return new self($d->round(2));
    }

    public function decimal(): Decimal
    {
        return $this->amount;
    }

    public function add(self $o): self
    {
        return new self($this->amount->add($o->amount));
    }

    public function sub(self $o): self
    {
        return new self($this->amount->sub($o->amount));
    }

    public function equals(self $o): bool
    {
        return $this->amount->equals($o->amount);
    }

    public function toString(): string
    {
        return $this->amount->toFixed(2);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
