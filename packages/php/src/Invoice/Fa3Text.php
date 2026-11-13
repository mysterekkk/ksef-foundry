<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Input sanitising for FA(3) fields. Strings are either accepted (and normalised exactly as
 * the XSD whitespace facet would) or rejected; they are never silently altered otherwise.
 *
 * @internal
 */
final class Fa3Text
{
    /** XSD xsd:token semantics: collapse whitespace, trim; length counted in characters. */
    public static function token(mixed $v, int $max, string $field, int $min = 1): string
    {
        $s = self::raw($v, $field);
        $s = trim((string) preg_replace('/[ \t\r\n]+/', ' ', $s), ' ');
        self::length($s, $min, $max, $field);
        return $s;
    }

    /** XSD xsd:string semantics (newlines kept). */
    public static function text(mixed $v, int $max, string $field, int $min = 1): string
    {
        $s = self::raw($v, $field);
        self::length($s, $min, $max, $field);
        return $s;
    }

    public static function date(mixed $v, string $field, string $min, string $max): string
    {
        if (
            !is_string($v)
            || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new InvoiceBuilderException(sprintf('%s must be a real date in YYYY-MM-DD format.', $field));
        }
        if ($v < $min || $v > $max) {
            throw new InvoiceBuilderException(
                sprintf('%s %s is outside the XSD range %s..%s.', $field, $v, $min, $max),
            );
        }
        return $v;
    }

    private static function raw(mixed $v, string $field): string
    {
        if (!is_string($v)) {
            throw new InvoiceBuilderException(sprintf('%s must be a string, got %s.', $field, get_debug_type($v)));
        }
        if (preg_match('//u', $v) !== 1) {
            throw new InvoiceBuilderException(sprintf('%s is not valid UTF-8.', $field));
        }
        if (preg_match(XmlValidator::forbiddenCharsPattern(), $v, $m) === 1) {
            throw new InvoiceBuilderException(
                sprintf(
                    '%s contains forbidden character U+%04X (not allowed in XML 1.0 / KSeF).',
                    $field,
                    mb_ord($m[0], 'UTF-8'),
                ),
            );
        }
        return $v;
    }

    private static function length(string $s, int $min, int $max, string $field): void
    {
        $n = mb_strlen($s, 'UTF-8');
        if ($n < $min) {
            throw new InvoiceBuilderException(sprintf('%s must not be empty.', $field));
        }
        if ($n > $max) {
            throw new InvoiceBuilderException(sprintf('%s is %d characters; XSD maximum is %d.', $field, $n, $max));
        }
    }
}
