<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Immutable exact decimal on bcmath strings. Floats are never accepted.
 * Rounding is half-up away from zero (commercial rounding).
 */
final class Decimal implements \JsonSerializable, \Stringable
{
    /** @param numeric-string $value */
    private function __construct(private readonly string $value)
    {
    }

    /** Accepts int|string|Decimal; anything else, floats included, is rejected at runtime (even for non-strict callers). */
    public static function of(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_int($value)) {
            return new self((string) $value);
        }
        if (is_float($value)) {
            throw new InvoiceBuilderException('Floats are not accepted for decimal values; pass a string or int.');
        }
        if (!is_string($value)) {
            throw new InvoiceBuilderException(
                'Decimal must be int|string|Decimal, got ' . get_debug_type($value) . '.',
            );
        }
        if (preg_match('/\A-?(0|[1-9][0-9]*)(\.[0-9]+)?\z/', $value) !== 1 || !is_numeric($value)) {
            throw new InvoiceBuilderException(
                sprintf(
                    'Invalid decimal string "%s" (expected -?digits[.digits], no spaces, no exponent).',
                    self::clip($value),
                ),
            );
        }
        return new self(self::normalizeZero($value));
    }

    /** @param int|string|self $o (typed mixed so that floats are rejected even by non-strict callers) */
    public function add(mixed $o): self
    {
        $o = self::of($o);
        return new self(self::normalizeZero(bcadd($this->value, $o->value, max($this->scale(), $o->scale()))));
    }

    /** @param int|string|self $o */
    public function sub(mixed $o): self
    {
        $o = self::of($o);
        return new self(self::normalizeZero(bcsub($this->value, $o->value, max($this->scale(), $o->scale()))));
    }

    /** Exact product (scale = sum of scales). */
    /** @param int|string|self $o */
    public function mul(mixed $o): self
    {
        $o = self::of($o);
        return new self(self::normalizeZero(bcmul($this->value, $o->value, $this->scale() + $o->scale())));
    }

    /** @param int|string|self $o */
    public function compare(mixed $o): int
    {
        $o = self::of($o);
        return bccomp($this->value, $o->value, max($this->scale(), $o->scale()));
    }

    /** @param int|string|self $o */
    public function equals(mixed $o): bool
    {
        return $this->compare($o) === 0;
    }

    public function isZero(): bool
    {
        return $this->compare('0') === 0;
    }

    public function isNegative(): bool
    {
        return $this->compare('0') < 0;
    }

    public function scale(): int
    {
        $p = strpos($this->value, '.');
        return $p === false ? 0 : strlen($this->value) - $p - 1;
    }

    /** Number of fractional digits after dropping trailing zeros. */
    public function significantScale(): int
    {
        $p = strpos($this->value, '.');
        if ($p === false) {
            return 0;
        }
        return strlen(rtrim(substr($this->value, $p + 1), '0'));
    }

    /** Digits in the integer part (0 for |x| < 1). */
    public function integerDigits(): int
    {
        $v = ltrim($this->value, '-');
        $p = strpos($v, '.');
        $int = $p === false ? $v : substr($v, 0, $p);
        return $int === '0' ? 0 : strlen($int);
    }

    /** Half-up (away from zero) rounding to $scale fractional digits. */
    public function round(int $scale): self
    {
        if ($scale < 0) {
            throw new \InvalidArgumentException('scale must be >= 0');
        }
        if ($this->scale() <= $scale) {
            return $this;
        }
        $half = self::num('0.' . str_repeat('0', $scale) . '5');
        $work = $this->scale();
        $adj = $this->isNegative() ? bcsub($this->value, $half, $work) : bcadd($this->value, $half, $work);
        // bcadd with a smaller scale truncates, which completes the half-up rounding
        return new self(self::normalizeZero(bcadd($adj, '0', $scale)));
    }

    /** Rounded and zero-padded to exactly $scale fractional digits (e.g. "12.30"). */
    public function toFixed(int $scale): string
    {
        $r = $this->round($scale);
        $v = $r->value;
        if ($scale === 0) {
            return $v;
        }
        $have = $r->scale();
        if ($have === 0) {
            $v .= '.';
        }
        return $v . str_repeat('0', $scale - $have);
    }

    /** Canonical string without insignificant trailing zeros. */
    public function toString(): string
    {
        $v = $this->value;
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        return self::normalizeZero(self::num($v));
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * @param numeric-string $v
     * @return numeric-string
     */
    private static function normalizeZero(string $v): string
    {
        if (preg_match('/\A-0(\.0*)?\z/', $v) === 1) {
            return '0';
        }
        return $v;
    }

    /** @return numeric-string */
    private static function num(string $s): string
    {
        if (!is_numeric($s)) {
            throw new \LogicException('not numeric: ' . $s);
        }
        return $s;
    }

    private static function clip(string $s): string
    {
        return mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '', 0, 40);
    }
}
