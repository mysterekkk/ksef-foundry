<?php
declare(strict_types=1);

/**
 * Regenerates fixtures/fa3/<name>.expected.xml and <name>.expected.totals.json from <name>.input.json
 * using the PHP builder, validating each result against the official XSD.
 * Usage: php fixtures/fa3/generate.php
 * Review generated files for tax correctness before committing: they become the contract
 * the TypeScript port must reproduce byte-for-byte.
 */

require __DIR__ . '/../../packages/php/vendor/autoload.php';

use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;

foreach (glob(__DIR__ . '/*.input.json') ?: [] as $in) {
    $name = basename($in, '.input.json');
    $model = json_decode((string) file_get_contents($in), true, 512, JSON_THROW_ON_ERROR);
    $b = Fa3InvoiceBuilder::fromArray($model);
    $xml = $b->build(); // throws if the XSD rejects it
    file_put_contents(__DIR__ . "/$name.expected.xml", $xml);
    file_put_contents(
        __DIR__ . "/$name.expected.totals.json",
        json_encode($b->totals()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    );
    echo "ok $name\n";
}
