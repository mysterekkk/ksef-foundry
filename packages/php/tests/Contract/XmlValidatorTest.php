<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Contract;

use LuroWeb\KsefFoundry\Exception\XmlValidationException;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Invoice\InvoiceValidationException;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SDK-level view of the canonical Invoice\XmlValidator (detailed rules are in tests/Invoice). */
final class XmlValidatorTest extends TestCase
{
    public function testBuilderSampleIsAcceptedByTheOfficialXsd(): void
    {
        $xml = Fa3InvoiceBuilder::sampleXml('7980332920', 'FV/CONTRACT/1');
        self::assertTrue((new XmlValidator())->validateFa3($xml)->isValid());
        (new XmlValidator())->assertValid($xml);
    }

    public function testInvalidXmlRaisesTheSdkXmlValidationExceptionWithLineAndColumn(): void
    {
        $xml = str_replace('<KodWaluty>PLN</KodWaluty>', '<KodWaluty>pln</KodWaluty>', Fa3InvoiceBuilder::sampleXml('7980332920', 'FV/C/2'));
        try {
            (new XmlValidator())->assertValid($xml);
            self::fail('must violate the XSD');
        } catch (XmlValidationException $e) {
            self::assertInstanceOf(InvoiceValidationException::class, $e);
            self::assertNotEmpty($e->errors());
            self::assertGreaterThan(0, $e->errors()[0]['line']);
            self::assertStringContainsString('KodWaluty', $e->errors()[0]['message']);
            self::assertNotEmpty($e->issues());
            self::assertArrayHasKey('errors', $e->diagnostics());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function unsafe(): array
    {
        return [
            'doctype' => ['<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>', 'doctype'],
            'external entity' => ['<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>', 'doctype'],
            'processing instruction' => ['<a><?php echo 1; ?></a>', 'processing-instruction'],
            'bom' => ["\xEF\xBB\xBF<a/>", 'bom'],
            'wrong encoding' => ['<?xml version="1.0" encoding="ISO-8859-2"?><a/>', 'encoding'],
            'invalid utf8' => ["<a>\xC3\x28</a>", 'utf8'],
            'discouraged unicode U+0086' => ["<a>x\u{0086}y</a>", 'forbidden-char'],
        ];
    }

    #[DataProvider('unsafe')]
    public function testUnsafeXmlIsRejectedWithStableCodes(string $xml, string $code): void
    {
        $r = (new XmlValidator())->validateFa3($xml);
        self::assertFalse($r->isValid());
        self::assertContains($code, $r->codes());
    }
}
