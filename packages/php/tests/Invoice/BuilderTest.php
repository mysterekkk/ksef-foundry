<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Invoice;

use LuroWeb\KsefFoundry\Invoice\Fa3Address;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Invoice\InvoiceBuilderException;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    private function base(): Fa3InvoiceBuilder
    {
        return Fa3InvoiceBuilder::create()
            ->seller('5260250274', 'Seller', new Fa3Address('ul. A 1'))
            ->buyerNip('1234563218', 'Buyer', new Fa3Address('ul. B 2'))
            ->number('FV/1')->issueDate('2026-01-15')->createdAt('2026-01-15T10:00:00Z');
    }

    /** @param callable(): mixed $f */
    private function rejects(callable $f, string $fragment): void
    {
        try {
            $f();
            self::fail("expected rejection containing '$fragment'");
        } catch (InvoiceBuilderException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($d)) {
            throw new \UnexpectedValueException('not an object');
        }
        return $d;
    }

    public function testMissingPiecesAreDemandedBeforeXml(): void
    {
        $this->rejects(fn () => Fa3InvoiceBuilder::create()->build(), 'Required field');
        $this->rejects(fn () => $this->base()->build(), 'At least one line');
        $noCreatedAt = Fa3InvoiceBuilder::create()
            ->seller('5260250274', 'S', new Fa3Address('a'))
            ->buyerNoId('B', new Fa3Address('b'))
            ->number('1')
            ->issueDate('2026-01-15')
            ->addLine('x', 1, '1', '23');
        $this->rejects(fn () => $noCreatedAt->build(), 'createdAt');
    }

    public function testUnsupportedVariantsAreRejected(): void
    {
        $this->rejects(fn () => $this->base()->currency('EUR'), 'not supported');
        foreach (['oo', '4', '3'] as $r) {
            $this->rejects(fn () => $this->base()->addLine('x', 1, '1', $r), 'not supported');
        }
        $this->rejects(fn () => $this->base()->addLine('x', 1, '1', '24'), 'Unknown VAT rate');
        $this->rejects(fn () => $this->base()->addLine('x', 1, '-1', '23'), '>= 0');
        $this->rejects(fn () => $this->base()->addLine('x', 0, '1', '23'), '> 0');
        $this->rejects(fn () => Fa3InvoiceBuilder::fromArray(['rodzajFaktury' => 'KOR']), 'unknown key');
    }

    public function testConditionalRules(): void
    {
        $this->rejects(fn () => $this->base()->addLine('x', 1, '1', 'zw')->build(), 'exemptionBasis');
        $this->rejects(
            fn () => $this->base()->addLine('x', 1, '1', '23')->exemptionBasis('art. 43')->build(),
            'no line uses',
        );
        $this->rejects(fn () => $this->base()->addLine('x', 1, '1', '0 WDT')->build(), 'EU VAT buyer');
        $xml = $this->base()->addLine('x', 1, '1', 'zw')->exemptionBasis('art. 43 ust. 1')->build();
        self::assertStringContainsString('<P_19>1</P_19>', $xml);
        self::assertStringContainsString('<P_19A>art. 43 ust. 1</P_19A>', $xml);
    }

    public function testFieldValidation(): void
    {
        $b = $this->base();
        $this->rejects(fn () => $b->seller('123456789', 'S', new Fa3Address('a')), 'TNrNIP');
        $this->rejects(fn () => $b->seller('0012345678', 'S', new Fa3Address('a')), 'TNrNIP');
        $this->rejects(fn () => $b->issueDate('2026-02-30'), 'real date');
        $this->rejects(fn () => $b->issueDate('15-01-2026'), 'YYYY-MM-DD');
        $this->rejects(fn () => $b->issueDate('2051-01-01'), 'outside');
        $this->rejects(fn () => $b->number(str_repeat('a', 257)), '257 characters');
        $this->rejects(fn () => $b->number('   '), 'empty');
        $this->rejects(fn () => $b->createdAt('2025-08-31T23:59:59Z'), 'outside');
        $this->rejects(fn () => $b->createdAt('2026-01-15 10:00:00'), 'UTC');
        $this->rejects(fn () => $b->buyerEu('XX', '1', 'n', new Fa3Address('a')), 'EU VAT prefix');
        $this->rejects(fn () => $b->buyerEu('DE', 'abc', 'n', new Fa3Address('a')), 'euVatNumber');
        $this->rejects(fn () => $b->paymentMethod(8), '1..7');
        $this->rejects(fn () => $b->bankAccount('123'), '10..34');
        $this->rejects(fn () => $b->addLine('x', '1.1234567', '1', '23'), 'TIlosci');
        $this->rejects(fn () => $b->addLine('x', '1', '1.123456789', '23'), 'TKwotowy2');
        $this->rejects(fn () => $b->addLine('x', 1, '1', '23', null, '1.001'), 'fractional');
    }

    public function testFromArrayIsStrictAndRejectsFloats(): void
    {
        $json = (string) file_get_contents(FixturesTest::dir() . '/01-single-line-23.input.json');
        self::assertStringContainsString('"quantity": "1"', $json);
        $withFloat = str_replace('"quantity": "1"', '"quantity": 1.5', $json);
        $this->rejects(fn () => Fa3InvoiceBuilder::fromArray(self::decode($withFloat)), 'floats are rejected');
        $withExtra = str_replace('"vatRate": "23"', '"vatRate": "23", "extra": 1', $json);
        $this->rejects(fn () => Fa3InvoiceBuilder::fromArray(self::decode($withExtra)), 'unknown key');
    }

    public function testWhitespaceInTokenFieldsIsCollapsedLikeTheXsdDoes(): void
    {
        $xml = $this->base()->addLine("  a \t\n  b  ", 1, '1', '23')->build();
        self::assertStringContainsString('<P_7>a b</P_7>', $xml);
    }

    public function testSampleIsValidUniqueAndDated(): void
    {
        $xml = Fa3InvoiceBuilder::sampleXml('5260250274', 'LIVE/' . bin2hex(random_bytes(4)));
        self::assertTrue((new XmlValidator())->validateFa3($xml)->isValid());
        self::assertStringContainsString('<P_1>' . gmdate('Y-m-d') . '</P_1>', $xml);
        $fixed = Fa3InvoiceBuilder::sampleXml('5260250274', 'X/1', new \DateTimeImmutable('2026-03-01T12:00:00+02:00'));
        self::assertStringContainsString('<DataWytworzeniaFa>2026-03-01T10:00:00Z</DataWytworzeniaFa>', $fixed);
    }

    public function testNipChecksumHelper(): void
    {
        self::assertTrue(Fa3InvoiceBuilder::isValidNipChecksum('5260250274'));
        self::assertTrue(Fa3InvoiceBuilder::isValidNipChecksum('1234563218'));
        self::assertFalse(Fa3InvoiceBuilder::isValidNipChecksum('1234563219'));
        self::assertFalse(Fa3InvoiceBuilder::isValidNipChecksum('123'));
    }

    public function testAllFixtureNipsHaveValidChecksum(): void
    {
        foreach (glob(FixturesTest::dir() . '/*.input.json') ?: [] as $f) {
            $m = Fa3InvoiceBuilder::fromArray(FixturesTest::readJson($f))->model();
            self::assertTrue(Fa3InvoiceBuilder::isValidNipChecksum($m['seller']['nip']), $f);
            if ($m['buyer']['nip'] !== null) {
                self::assertTrue(Fa3InvoiceBuilder::isValidNipChecksum($m['buyer']['nip']), $f);
            }
        }
    }
}
