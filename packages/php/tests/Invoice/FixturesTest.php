<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Invoice;

use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    public static function dir(): string
    {
        return dirname(__DIR__, 4) . '/fixtures/fa3';
    }

    /** @return array<array-key, mixed> */
    public static function readJson(string $path): array
    {
        $d = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($d)) {
            throw new \UnexpectedValueException($path . ' is not a JSON object');
        }
        return $d;
    }

    /** @return array<string, string> the "fields" map of <name>.expected.totals.json */
    public static function totalsFields(string $name): array
    {
        $fields = self::readJson(self::dir() . "/$name.expected.totals.json")['fields'] ?? null;
        if (!is_array($fields)) {
            throw new \UnexpectedValueException('totals.json without fields');
        }
        $out = [];
        foreach ($fields as $k => $v) {
            if (!is_string($v)) {
                throw new \UnexpectedValueException('totals field must be a string');
            }
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /** @return array<string, array{string}> */
    public static function validCases(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.input.json') ?: [] as $f) {
            $n = basename($f, '.input.json');
            $out[$n] = [$n];
        }
        return $out;
    }

    /** @return array<string, array{string}> */
    public static function invalidCases(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/invalid/*.xml') ?: [] as $f) {
            $n = basename($f, '.xml');
            $out[$n] = [$n];
        }
        return $out;
    }

    public function testAtLeastEightValidAndEightInvalidFixtures(): void
    {
        self::assertGreaterThanOrEqual(8, count(self::validCases()));
        self::assertGreaterThanOrEqual(8, count(self::invalidCases()));
    }

    #[DataProvider('validCases')]
    public function testExpectedXmlIsValidAgainstRealXsd(string $name): void
    {
        $xml = (string) file_get_contents(self::dir() . "/$name.expected.xml");
        $r = (new XmlValidator())->validateFa3($xml);
        self::assertTrue($r->isValid(), $r->summary());
    }

    #[DataProvider('validCases')]
    public function testBuilderOutputIsByteIdenticalAndC14nEqual(string $name): void
    {
        $model = self::readJson(self::dir() . "/$name.input.json");
        $b = Fa3InvoiceBuilder::fromArray($model);
        $xml = $b->build();
        $expected = (string) file_get_contents(self::dir() . "/$name.expected.xml");
        self::assertSame($expected, $xml, 'byte-for-byte');

        $a = new \DOMDocument();
        $a->loadXML($xml, LIBXML_NONET);
        $e = new \DOMDocument();
        $e->loadXML($expected, LIBXML_NONET);
        self::assertSame($e->C14N(), $a->C14N());

        $totals = self::readJson(self::dir() . "/$name.expected.totals.json");
        self::assertSame($totals, $b->totals()->toArray());
    }

    #[DataProvider('validCases')]
    public function testTotalsInExpectedXmlMatchTotalsJson(string $name): void
    {
        $doc = new \DOMDocument();
        $doc->load(self::dir() . "/$name.expected.xml", LIBXML_NONET);
        $fields = self::totalsFields($name);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('f', 'http://crd.gov.pl/wzor/2025/06/25/13775/');
        foreach ($fields as $field => $value) {
            self::assertSame($value, $xp->evaluate("string(//f:Fa/f:$field)"), (string) $field);
        }
        // and no P_13/P_14/P_15 element is present that totals.json does not know about
        $n = $xp->query(
            '//f:Fa/*[starts-with(local-name(), "P_13") or starts-with(local-name(), "P_14") or local-name()="P_15"]',
        );
        self::assertSame(count($fields), $n === false ? -1 : $n->length);
    }

    #[DataProvider('invalidCases')]
    public function testInvalidFixtureFailsForDocumentedReason(string $name): void
    {
        $xml = (string) file_get_contents(self::dir() . "/invalid/$name.xml");
        $spec = (string) file_get_contents(self::dir() . "/invalid/$name.expected-errors.txt");
        if (preg_match('/^code=([a-z0-9-]+)\./m', $spec, $m) !== 1) {
            self::fail('expected-errors.txt must start with code=<rule>.');
        }
        $r = (new XmlValidator())->validateFa3($xml);
        self::assertFalse($r->isValid(), "$name must be rejected");
        self::assertContains($m[1], $r->codes(), $r->summary());
        if (preg_match('/^contains=(.+)$/m', $spec, $c) === 1) {
            self::assertStringContainsString($c[1], $r->summary(50));
        }
        self::assertGreaterThan(0, count($r->errors()));
    }

    public function testOversizeFixtureRule(): void
    {
        $xml = (string) file_get_contents(self::dir() . '/01-single-line-23.expected.xml');
        $pos = strpos($xml, '?>') + 2;
        $big = substr($xml, 0, $pos) . '<!--' . str_repeat('a', 3_000_001) . '-->' . substr($xml, $pos);
        $r = (new XmlValidator())->validateFa3($big);
        self::assertSame(['size'], $r->codes());
        self::assertTrue((new XmlValidator(maxBytes: strlen($big) + 10))->validateFa3($big)->isValid());
    }

    public function testBundledSchemaCopiesAreByteIdenticalToResolvedSchemas(): void
    {
        $a = dirname(self::dir(), 2) . '/schemas/xsd/FA/resolved';
        $b = XmlValidator::bundledSchemaDir();
        $files = [
            'schemat_FA3_v1-0E.xsd',
            'bazowe/StrukturyDanych_v10-0E.xsd',
            'bazowe/ElementarneTypyDanych_v10-0E.xsd',
            'bazowe/KodyKrajow_v10-0E.xsd',
            'NOTICE.txt',
            'SHA256SUMS.txt',
        ];
        foreach ($files as $f) {
            self::assertFileExists("$a/$f");
            self::assertFileExists("$b/$f");
            self::assertSame(hash_file('sha256', "$a/$f"), hash_file('sha256', "$b/$f"), $f);
        }
    }

    public function testResolvedSchemasOnlyDifferFromOfficialInSchemaLocation(): void
    {
        $root = dirname(self::dir(), 2) . '/schemas/xsd/FA';
        $files = [
            'schemat_FA3_v1-0E.xsd', 'bazowe/StrukturyDanych_v10-0E.xsd',
            'bazowe/ElementarneTypyDanych_v10-0E.xsd', 'bazowe/KodyKrajow_v10-0E.xsd',
        ];
        foreach ($files as $f) {
            $src = (string) file_get_contents("$root/$f");
            $res = (string) file_get_contents("$root/resolved/$f");
            $norm = static fn (
                string $s,
            ): string => (string) preg_replace('/schemaLocation="[^"]*"/', 'schemaLocation=""', $s);
            self::assertSame($norm($src), $norm($res), $f);
            self::assertDoesNotMatchRegularExpression(
                '#schemaLocation="https?://#',
                $res,
                "$f must not reference the network",
            );
            self::assertDoesNotMatchRegularExpression(
                '#schemaLocation="https?://#',
                (string) file_get_contents(XmlValidator::bundledSchemaDir() . "/$f"),
            );
        }
    }
}
