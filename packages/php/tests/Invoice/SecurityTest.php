<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Invoice;

use LuroWeb\KsefFoundry\Invoice\Fa3Address;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Invoice\InvoiceBuilderException;
use LuroWeb\KsefFoundry\Invoice\InvoiceValidationException;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private function valid(): string
    {
        return (string) file_get_contents(FixturesTest::dir() . '/01-single-line-23.expected.xml');
    }

    public function testBillionLaughsIsRejectedWithoutMemoryBlowUp(): void
    {
        $dtd = '<!ENTITY l0 "ha">';
        for ($i = 1; $i <= 12; $i++) {
            $dtd .= sprintf('<!ENTITY l%d "%s">', $i, str_repeat('&l' . ($i - 1) . ';', 10));
        }
        $xml = str_replace('<Faktura', "<!DOCTYPE Faktura [$dtd]>\n<Faktura", $this->valid());
        $xml = str_replace('Usługa programistyczna', '&l12;', $xml);

        gc_collect_cycles();
        $before = memory_get_usage();
        $t = microtime(true);
        $r = (new XmlValidator())->validateFa3($xml);
        $elapsed = microtime(true) - $t;
        $delta = memory_get_peak_usage() - $before;

        self::assertSame(['doctype'], $r->codes());
        self::assertLessThan(2.0, $elapsed);
        self::assertLessThan(8 * 1024 * 1024, $delta, 'peak memory delta');
    }

    public function testXxeWithNetworkAndFileEntitiesIsRejectedBeforeParsing(): void
    {
        $uris = [
            'http://127.0.0.1:9/x', 'file:///etc/passwd',
            'php://filter/convert.base64-encode/resource=/etc/passwd', 'expect://id',
        ];
        foreach ($uris as $uri) {
            $xml = str_replace(
                '<Faktura',
                "<!DOCTYPE Faktura [<!ENTITY x SYSTEM \"$uri\">]>\n<Faktura",
                $this->valid(),
            );
            $xml = str_replace('Usługa programistyczna', '&x;', $xml);
            $r = (new XmlValidator())->validateFa3($xml);
            self::assertSame(['doctype'], $r->codes(), $uri);
        }
        // parameter entities and external DTD subset
        $xml = str_replace(
            '<Faktura',
            '<!DOCTYPE Faktura SYSTEM "http://127.0.0.1:9/evil.dtd">' . "\n<Faktura",
            $this->valid(),
        );
        self::assertSame(['doctype'], (new XmlValidator())->validateFa3($xml)->codes());
    }

    public function testValidationNeverRequestsExternalResources(): void
    {
        $seen = [];
        libxml_set_external_entity_loader(static function (?string $public, string $system, array $ctx) use (&$seen) {
            $seen[] = $system;
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $system) === 1 && !str_starts_with($system, 'file://')) {
                return null; // refuse anything remote
            }
            $path = str_starts_with($system, "file://") ? substr($system, 7) : $system;
            if (!is_file($path)) {
                $dir = XmlValidator::bundledSchemaDir();
                $path = is_file("$dir/$system") ? "$dir/$system" : "$dir/bazowe/" . basename($system);
            }
            $h = @fopen($path, 'rb');
            return $h === false ? null : $h;
        });
        try {
            $r = (new XmlValidator())->validateFa3($this->valid());
        } finally {
            libxml_set_external_entity_loader(null);
        }
        self::assertTrue($r->isValid(), $r->summary());
        foreach ($seen as $s) {
            self::assertDoesNotMatchRegularExpression('#^https?://#i', $s, "network resolution attempted: $s");
        }
        self::assertGreaterThanOrEqual(
            2,
            count($seen),
            'schema imports were resolved through the loader (local files)',
        );
    }

    public function testNegativeControlUnresolvedOfficialSchemaWouldHitTheNetwork(): void
    {
        $seen = [];
        libxml_set_external_entity_loader(static function (?string $p, string $system, array $c) use (&$seen) {
            $seen[] = $system;
            if (str_starts_with($system, "http")) {
                return null;
            }
            $h = @fopen($system, "rb");
            return $h === false ? null : $h;
        });
        $orig = dirname(FixturesTest::dir(), 2) . "/schemas/xsd/FA/schemat_FA3_v1-0E.xsd";
        try {
            @(new XmlValidator(schemaPath: $orig))->validateFa3($this->valid());
        } finally {
            libxml_set_external_entity_loader(null);
        }
        self::assertNotEmpty(
            array_filter($seen, static fn (string $s): bool => str_starts_with($s, "http://crd.gov.pl/")),
            "the detector works: official schema requests remote import (refused here)",
        );
    }

    public function testNoGlobalLibxmlStateLeak(): void
    {
        libxml_use_internal_errors(false);
        (new XmlValidator())->validateFa3('<not-xml');
        self::assertFalse(libxml_use_internal_errors(), 'internal errors flag restored');
        self::assertSame([], libxml_get_errors());

        libxml_use_internal_errors(true);
        (new XmlValidator())->validateFa3($this->valid());
        self::assertTrue(libxml_use_internal_errors());
        libxml_use_internal_errors(false);
    }

    public function testSizeLimitIsConfigurable(): void
    {
        $xml = $this->valid();
        self::assertSame(['size'], (new XmlValidator(maxBytes: 1000))->validateFa3($xml)->codes());
        self::assertTrue((new XmlValidator(maxBytes: 100000))->validateFa3($xml)->isValid());
    }

    public function testAssertValidThrowsWithIssues(): void
    {
        try {
            (new XmlValidator())->assertValid('<?xml version="1.0" encoding="UTF-8"?><a/>');
            self::fail('no exception');
        } catch (InvoiceValidationException $e) {
            self::assertNotEmpty($e->issues());
            self::assertSame('xsd', $e->issues()[0]->code);
        }
    }

    public function testMalformedAndEmptyInputs(): void
    {
        $v = new XmlValidator();
        foreach (['', '   ', '<a>', 'plain text', "<?xml version=\"1.0\"?>\n<a></b>"] as $x) {
            self::assertFalse($v->validateFa3($x)->isValid(), $x);
        }
    }

    /** @return list<array{string}> */
    public static function hostileStrings(): array
    {
        return [
            ['x]]>y'],
            ['</P_7><P_7>injected'],
            ['<![CDATA[boom]]>'],
            ['a & b < c > d " e \' f'],
            ['&amp; &lt;script&gt;'],
            ['<?php echo 1; ?>'],
            ['<!-- c -->'],
            ["line1\nline2\tTab"],
            ['日本語 ąęłóżźćń 😀'],
        ];
    }

    public function testHostileStringsAreEscapedAndNeverChangeStructure(): void
    {
        $baseline = $this->structure($this->buildWith('plain'));
        foreach (self::hostileStrings() as [$s]) {
            $xml = $this->buildWith($s);
            self::assertSame($baseline, $this->structure($xml), 'structure changed for ' . json_encode($s));
            $doc = new \DOMDocument();
            $doc->loadXML($xml, LIBXML_NONET);
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('f', 'http://crd.gov.pl/wzor/2025/06/25/13775/');
            $expect = trim((string) preg_replace('/[ \t\r\n]+/', ' ', $s));
            self::assertSame($expect, $xp->evaluate('string(//f:FaWiersz/f:P_7)'));
            self::assertSame($expect, $xp->evaluate('string(//f:Podmiot2//f:Nazwa)'));
            self::assertSame($expect, $xp->evaluate('string(//f:DodatkowyOpis/f:Wartosc)'));
            self::assertTrue((new XmlValidator())->validateFa3($xml)->isValid());
        }
    }

    /** @return list<array{string}> */
    public static function rejectedStrings(): array
    {
        return [
            ["a\0b"],
            ["a\x01b"],
            ["a\x1Fb"],
            ["bad\xC3\x28utf8"],
            ["lone\xED\xA0\x80surrogate"],
            ["\xF4\x90\x80\x80beyond"],
            ["a\u{0085}b"],
            ["a\u{0090}b"],
            ["a\u{FDD0}b"],
            ["a\u{1FFFE}b"],
            ["a\u{FFFF}b"],
            [''],
        ];
    }

    public function testForbiddenOrBrokenStringsAreRejectedBeforeXml(): void
    {
        $count = 0;
        foreach (self::rejectedStrings() as [$s]) {
            if ($s === "a\u{0085}b") {
                continue; // U+0085 is not in the KSeF forbidden list (86-9F, 7F-84), handled below
            }
            try {
                $this->buildWith($s);
                self::fail('accepted ' . bin2hex($s));
            } catch (InvoiceBuilderException) {
                $count++;
            }
        }
        self::assertGreaterThan(8, $count);
    }

    public function testValidatorRejectsForbiddenRanges(): void
    {
        $xml = $this->valid();
        $forbidden = [
            "\u{007F}", "\u{0084}", "\u{0086}", "\u{009F}", "\u{FDD0}",
            "\u{FDEF}", "\u{1FFFE}", "\u{10FFFF}", "\u{FFFE}",
        ];
        foreach ($forbidden as $ch) {
            $r = (new XmlValidator())->validateFa3(str_replace('Usługa programistyczna', "a{$ch}b", $xml));
            self::assertContains('forbidden-char', $r->codes(), bin2hex($ch));
        }
        // boundary characters that are allowed: U+0085 (NEL), U+00A0, U+FDCF, U+FDF0
        foreach (["\u{0085}", "\u{00A0}", "\u{FDCF}", "\u{FDF0}"] as $ch) {
            $r = (new XmlValidator())->validateFa3(str_replace('Usługa programistyczna', "a{$ch}b", $xml));
            self::assertNotContains('forbidden-char', $r->codes(), bin2hex($ch));
        }
    }

    public function testOneMegabyteInvoiceValidatesQuickly(): void
    {
        $b = Fa3InvoiceBuilder::create()
            ->seller('5260250274', 'S', new Fa3Address('a'))
            ->buyerNoId('B', new Fa3Address('b'))
            ->number('BIG/1')->issueDate('2026-01-15')->createdAt('2026-01-15T10:00:00Z')
            ->withoutSelfValidation();
        for ($i = 1; $i <= 4600; $i++) {
            $b->addLine("Pozycja numer $i ąęł", '1.5', '10.10', $i % 2 === 0 ? '23' : '8', 'szt.');
        }
        $xml = $b->build();
        self::assertGreaterThan(1_000_000, strlen($xml));
        $t = microtime(true);
        $r = (new XmlValidator())->validateFa3($xml);
        $elapsed = microtime(true) - $t;
        self::assertTrue($r->isValid(), $r->summary());
        self::assertLessThan(10.0, $elapsed, 'validation of ~1 MB took ' . round($elapsed, 3) . 's');
    }

    private function buildWith(string $s): string
    {
        $text = $s === '' ? "\0" : $s; // '' is invalid for token fields; treat as rejected input
        if ($s === '') {
            $text = '';
        }
        return Fa3InvoiceBuilder::create()
            ->seller('5260250274', 'Seller', new Fa3Address('ul. A 1'))
            ->buyerNoId($text, new Fa3Address('ul. B 2'))
            ->number('INJ/1')->issueDate('2026-01-15')->createdAt('2026-01-15T10:00:00Z')
            ->addLine($text, 1, '10.00', '23')
            ->additionalInfo('k', $text)
            ->build();
    }

    /** @return list<string> element names in document order (structure fingerprint) */
    private function structure(string $xml): array
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml, LIBXML_NONET);
        $out = [];
        foreach ((new \DOMXPath($doc))->query('//*') ?: [] as $n) {
            $out[] = $n->localName ?? '';
        }
        return $out;
    }
}
