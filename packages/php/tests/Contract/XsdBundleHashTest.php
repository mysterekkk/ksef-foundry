<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Contract;

use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use PHPUnit\Framework\TestCase;

/** The shipped XSD bundle must still match the hashes recorded when it was derived (guards against EOL rewrites). */
final class XsdBundleHashTest extends TestCase
{
    public function testBundledXsdFilesMatchRecordedResolvedHashes(): void
    {
        $dir = XmlValidator::bundledSchemaDir();
        $lines = file($dir . '/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);
        $checked = 0;
        foreach ($lines as $line) {
            if (preg_match('/^resolved\s+([0-9a-f]{64})\s+(\S+)$/', $line, $m) !== 1) {
                continue;
            }
            self::assertSame($m[1], hash_file('sha256', $dir . '/' . $m[2]), $m[2]);
            ++$checked;
        }
        self::assertSame(4, $checked);
    }
}
