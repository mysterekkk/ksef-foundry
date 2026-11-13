<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Loads the language-neutral invoice model (fixtures/fa3/README.md) into {@see Fa3InvoiceBuilder}.
 * Unknown keys are rejected (never silently ignored). Decimals must be JSON strings or ints.
 *
 * @internal
 */
final class Fa3ModelLoader
{
    /** @param array<array-key, mixed> $m */
    public static function load(array $m): Fa3InvoiceBuilder
    {
        self::keys(
            $m,
            [
                'seller',
                'buyer',
                'number',
                'issueDate',
                'issuePlace',
                'saleDate',
                'currency',
                'createdAt',
                'systemInfo',
                'lines',
                'exemptionBasis',
                'payment',
                'additionalInfo',
                'footer',
            ],
            'model',
        );
        $b = Fa3InvoiceBuilder::create();

        $s = self::arr($m, 'seller', 'seller');
        self::keys($s, ['nip', 'name', 'address'], 'seller');
        $b->seller(
            self::str($s, 'nip', 'seller'),
            self::str($s, 'name', 'seller'),
            Fa3Address::fromArray(self::arr($s, 'address', 'seller'), 'seller.address'),
        );

        $by = self::arr($m, 'buyer', 'buyer');
        self::keys(
            $by,
            ['idType', 'nip', 'euPrefix', 'euVatNumber', 'countryCode', 'taxId', 'name', 'address'],
            'buyer',
        );
        $addr = Fa3Address::fromArray(self::arr($by, 'address', 'buyer'), 'buyer.address');
        $name = self::str($by, 'name', 'buyer');
        $type = self::str($by, 'idType', 'buyer');
        $allowed = [
            'nip' => ['nip'],
            'eu' => ['euPrefix', 'euVatNumber'],
            'foreign' => ['countryCode', 'taxId'],
            'none' => [],
        ];
        if (!isset($allowed[$type])) {
            throw new InvoiceBuilderException('buyer.idType must be nip|eu|foreign|none.');
        }
        foreach (['nip', 'euPrefix', 'euVatNumber', 'countryCode', 'taxId'] as $k) {
            if (isset($by[$k]) && !in_array($k, $allowed[$type], true)) {
                throw new InvoiceBuilderException(sprintf('buyer.%s is not allowed with idType "%s".', $k, $type));
            }
        }
        match ($type) {
            'nip' => $b->buyerNip(self::str($by, 'nip', 'buyer'), $name, $addr),
            'eu' => $b->buyerEu(
                self::str($by, 'euPrefix', 'buyer'),
                self::str($by, 'euVatNumber', 'buyer'),
                $name,
                $addr,
            ),
            'foreign' => $b->buyerForeign(
                self::optStr($by, 'countryCode', 'buyer'),
                self::str($by, 'taxId', 'buyer'),
                $name,
                $addr,
            ),
            'none' => $b->buyerNoId($name, $addr),
        };

        $b->number(
            self::str($m, 'number', 'model'),
        )->issueDate(self::str($m, 'issueDate', 'model'))->createdAt(self::str($m, 'createdAt', 'model'));
        if (($v = self::optStr($m, 'issuePlace', 'model')) !== null) {
            $b->issuePlace($v);
        }
        if (($v = self::optStr($m, 'saleDate', 'model')) !== null) {
            $b->saleDate($v);
        }
        if (($v = self::optStr($m, 'currency', 'model')) !== null) {
            $b->currency($v);
        }
        if (($v = self::optStr($m, 'systemInfo', 'model')) !== null) {
            $b->systemInfo($v);
        }
        if (($v = self::optStr($m, 'footer', 'model')) !== null) {
            $b->footer($v);
        }

        $lines = $m['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || $lines === []) {
            throw new InvoiceBuilderException('model.lines must be a non-empty list.');
        }
        foreach ($lines as $i => $l) {
            $p = 'lines[' . ($i + 1) . ']';
            if (!is_array($l)) {
                throw new InvoiceBuilderException("$p must be an object.");
            }
            self::keys($l, ['name', 'unit', 'quantity', 'unitNetPrice', 'netValue', 'vatRate'], $p);
            foreach (['quantity', 'unitNetPrice'] as $k) {
                if (!isset($l[$k])) {
                    throw new InvoiceBuilderException("$p.$k is required.");
                }
            }
            $b->addLine(
                self::str($l, 'name', $p),
                self::dec($l['quantity'], "$p.quantity"),
                self::dec($l['unitNetPrice'], "$p.unitNetPrice"),
                self::str($l, 'vatRate', $p),
                self::optStr($l, 'unit', $p),
                isset($l['netValue']) ? self::dec($l['netValue'], "$p.netValue") : null,
            );
        }

        if (isset($m['exemptionBasis'])) {
            $e = self::arr($m, 'exemptionBasis', 'model');
            self::keys($e, ['type', 'text'], 'exemptionBasis');
            $b->exemptionBasis(
                self::str($e, 'text', 'exemptionBasis'),
                self::optStr($e, 'type', 'exemptionBasis') ?? 'statute',
            );
        }
        if (isset($m['payment'])) {
            $p = self::arr($m, 'payment', 'model');
            self::keys($p, ['method', 'paidDate', 'dueDates', 'bankAccounts'], 'payment');
            if (isset($p['method'])) {
                if (!is_int($p['method'])) {
                    throw new InvoiceBuilderException('payment.method must be an integer 1..7.');
                }
                $b->paymentMethod($p['method']);
            }
            if (($v = self::optStr($p, 'paidDate', 'payment')) !== null) {
                $b->paid($v);
            }
            foreach (self::list($p, 'dueDates', 'payment') as $d) {
                $b->dueDate(
                    is_string($d) ? $d : throw new InvoiceBuilderException('payment.dueDates must be strings.'),
                );
            }
            foreach (self::list($p, 'bankAccounts', 'payment') as $a) {
                if (!is_array($a)) {
                    throw new InvoiceBuilderException('payment.bankAccounts[] must be objects.');
                }
                self::keys($a, ['number', 'swift', 'bankName', 'description'], 'bankAccount');
                $b->bankAccount(
                    self::str($a, 'number', 'bankAccount'),
                    self::optStr($a, 'swift', 'bankAccount'),
                    self::optStr($a, 'bankName', 'bankAccount'),
                    self::optStr($a, 'description', 'bankAccount'),
                );
            }
        }
        foreach (self::list($m, 'additionalInfo', 'model') as $kv) {
            if (!is_array($kv)) {
                throw new InvoiceBuilderException('additionalInfo[] must be objects.');
            }
            self::keys($kv, ['key', 'value'], 'additionalInfo');
            $b->additionalInfo(self::str($kv, 'key', 'additionalInfo'), self::str($kv, 'value', 'additionalInfo'));
        }
        return $b;
    }

    /**
     * @param array<array-key, mixed> $a
     * @param list<string> $allowed
     */
    private static function keys(array $a, array $allowed, string $path): void
    {
        $bad = array_diff(array_map('strval', array_keys($a)), $allowed);
        if ($bad !== []) {
            throw new InvoiceBuilderException(sprintf('%s: unknown key(s): %s', $path, implode(', ', $bad)));
        }
    }

    /**
     * @param array<array-key, mixed> $a
     * @return array<array-key, mixed>
     */
    private static function arr(array $a, string $k, string $path): array
    {
        if (!isset($a[$k]) || !is_array($a[$k])) {
            throw new InvoiceBuilderException("$path.$k is required (object).");
        }
        return $a[$k];
    }

    /** @param array<array-key, mixed> $a */
    private static function str(array $a, string $k, string $path): string
    {
        if (!isset($a[$k]) || !is_string($a[$k])) {
            throw new InvoiceBuilderException("$path.$k is required (string).");
        }
        return $a[$k];
    }

    /** @param array<array-key, mixed> $a */
    private static function optStr(array $a, string $k, string $path): ?string
    {
        if (!isset($a[$k])) {
            return null;
        }
        if (!is_string($a[$k])) {
            throw new InvoiceBuilderException("$path.$k must be a string.");
        }
        return $a[$k];
    }

    /**
     * @param array<array-key, mixed> $a
     * @return list<mixed>
     */
    private static function list(array $a, string $k, string $path): array
    {
        if (!isset($a[$k])) {
            return [];
        }
        if (!is_array($a[$k]) || !array_is_list($a[$k])) {
            throw new InvoiceBuilderException("$path.$k must be a list.");
        }
        return $a[$k];
    }

    private static function dec(mixed $v, string $path): Decimal
    {
        if (is_float($v)) {
            throw new InvoiceBuilderException("$path must be a JSON string (floats are rejected), e.g. \"33.33\".");
        }
        return Decimal::of($v);
    }
}
