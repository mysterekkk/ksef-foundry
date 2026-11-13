<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Invoice;

use LuroWeb\KsefFoundry\Invoice\Decimal;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Invoice\InvoiceBuilderException;
use LuroWeb\KsefFoundry\Invoice\Money;
use LuroWeb\KsefFoundry\Invoice\VatRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyMathTest extends TestCase
{
    /** @return array<string, array{string, int, string}> */
    public static function roundingTable(): array
    {
        return [
            'half up' => ['2.345', 2, '2.35'],
            'below half' => ['2.344999', 2, '2.34'],
            'exact' => ['2.30', 2, '2.30'],
            'negative half away from zero' => ['-2.345', 2, '-2.35'],
            'tiny negative becomes 0.00' => ['-0.001', 2, '0.00'],
            '0.005' => ['0.005', 2, '0.01'],
            '0.004' => ['0.004', 2, '0.00'],
            'carry' => ['99.995', 2, '100.00'],
            'integer' => ['7', 2, '7.00'],
            'six digits' => ['1.0000005', 6, '1.000001'],
        ];
    }

    #[DataProvider('roundingTable')]
    public function testRoundHalfUp(string $in, int $scale, string $out): void
    {
        self::assertSame($out, Decimal::of($in)->toFixed($scale));
    }

    public function testFloatsAreRejectedEverywhere(): void
    {
        $this->expectException(InvoiceBuilderException::class);
        Decimal::of(1.5);
    }

    public function testFloatRejectedByMoneyAndBuilder(): void
    {
        $b = Fa3InvoiceBuilder::create();
        $calls = [
            fn () => (new \ReflectionMethod(Money::class, 'of'))->invoke(null, 0.1),
            fn () => (new \ReflectionMethod($b, 'addLine'))->invoke($b, 'x', 1.0, '1', '23'),
            fn () => (new \ReflectionMethod($b, 'addLine'))->invoke($b, 'x', 1, 9.99, '23'),
        ];
        foreach ($calls as $f) {
            try {
                $f();
                self::fail('float accepted');
            } catch (InvoiceBuilderException | \TypeError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return list<array{string}> */
    public static function badDecimals(): array
    {
        return [[''], [' 1'], ['1,5'], ['1e3'], ['01'], ['.5'], ['1.'], ['+1'], ['--1'], ['NaN'], ["1\n"], ['0x10']];
    }

    #[DataProvider('badDecimals')]
    public function testMalformedDecimalStringsRejected(string $s): void
    {
        $this->expectException(InvoiceBuilderException::class);
        Decimal::of($s);
    }

    public function testMoneyExactRejectsSubGroszPrecision(): void
    {
        self::assertSame('1.20', Money::exact('1.2')->toString());
        $this->expectException(InvoiceBuilderException::class);
        Money::exact('1.001');
    }

    public function testArithmetic(): void
    {
        self::assertSame('0.3', Decimal::of('0.1')->add('0.2')->toString());
        self::assertSame('33.3', Decimal::of('0.333')->mul('100')->toString());
        self::assertSame('-0.1', Decimal::of('0.1')->sub('0.2')->toString());
        self::assertSame(-1, Decimal::of('0.1')->compare('0.10001'));
    }

    public function testRateBucketMapping(): void
    {
        $map = [
            '23' => '1',
            '22' => '1',
            '8' => '2',
            '7' => '2',
            '5' => '3',
            '0 KR' => '6_1',
            '0 WDT' => '6_2',
            '0 EX' => '6_3',
            'zw' => '7',
            'np I' => '8',
            'np II' => '9',
        ];
        foreach ($map as $tok => $bucket) {
            self::assertSame($bucket, VatRate::parse((string) $tok)->bucket(), (string) $tok);
        }
        foreach (['4', '3', 'oo'] as $tok) {
            try {
                VatRate::parse((string) $tok);
                self::fail("$tok should be unsupported");
            } catch (InvoiceBuilderException $e) {
                self::assertStringContainsString('not supported', $e->getMessage());
            }
        }
    }

    public function testMixingRatesInOneBucketIsRejected(): void
    {
        $b = Fa3InvoiceBuilder::create()->addLine('a', 1, '10', '23')->addLine('b', 1, '10', '22');
        $this->expectException(InvoiceBuilderException::class);
        $b->totals();
    }

    /**
     * Property test: random invoices; builder totals vs an independent oracle written with
     * integer arithmetic on grosze (no bcmath, no Decimal class).
     */
    public function testRandomInvoicesMatchIntegerCentsOracle(): void
    {
        mt_srand(20261113);
        // token, percent (null = no VAT amount), bucket
        $choices = [
            ['23', 23, '1'],
            ['8', 8, '2'],
            ['5', 5, '3'],
            ['0 KR', null, '6_1'],
            ['zw', null, '7'],
            ['np I', null, '8'],
        ];
        for ($iter = 0; $iter < 1500; $iter++) {
            $b = Fa3InvoiceBuilder::create();
            $sumNet = [];
            $pct = [];
            $n = mt_rand(1, 25);
            for ($i = 0; $i < $n; $i++) {
                $qMilli = mt_rand(1, 20000);          // quantity in thousandths
                $pCents = mt_rand(0, 500000);         // unit price in grosze
                [$tok, $rate, $bk] = $choices[mt_rand(0, count($choices) - 1)];
                $qStr = intdiv($qMilli, 1000) . '.' . str_pad((string) ($qMilli % 1000), 3, '0', STR_PAD_LEFT);
                $pStr = intdiv($pCents, 100) . '.' . str_pad((string) ($pCents % 100), 2, '0', STR_PAD_LEFT);
                $b->addLine("L$i", $qStr, $pStr, $tok);
                $netCents = intdiv(2 * $qMilli * $pCents + 1000, 2000);   // half-up of q*p/1000
                $sumNet[$bk] = ($sumNet[$bk] ?? 0) + $netCents;
                $pct[$bk] = $rate;
            }
            $t = $b->totals();
            $gross = 0;
            $expectFields = [];
            foreach ($sumNet as $bk => $net) {
                $expectFields['P_13_' . $bk] = self::fmt($net);
                $gross += $net;
                $rate = $pct[$bk] ?? null;
                if ($rate !== null) {
                    $vat = intdiv(2 * $net * $rate + 100, 200);   // half-up of net*rate/100
                    $expectFields['P_14_' . $bk] = self::fmt($vat);
                    $gross += $vat;
                }
            }
            $expectFields['P_15'] = self::fmt($gross);
            $got = $t->toArray()['fields'];
            ksort($got);
            ksort($expectFields);
            self::assertSame($expectFields, $got, "iteration $iter");

            // identities
            self::assertTrue($t->gross->equals($t->net()->add($t->vat())));
        }
    }

    private static function fmt(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
