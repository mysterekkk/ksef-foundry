<?php

declare(strict_types=1);

use LuroWeb\KsefFoundry\Invoice\Fa3Address;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\Invoice\InvoiceValidationException;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;

require __DIR__ . '/../vendor/autoload.php';

// 1) Build an FA(3) invoice. Amounts are exact decimals (strings), never floats.
$builder = Fa3InvoiceBuilder::create()
    ->seller(SyntheticIdentity::nip(), 'Przykładowa Sp. z o.o.', new Fa3Address('ul. Testowa 1', '00-001 Warszawa'))
    ->buyerNoId('Jan Kowalski', new Fa3Address('ul. Próbna 2', '00-002 Warszawa'))
    ->number('FV/2026/11/1')
    ->issueDate(gmdate('Y-m-d'))
    ->createdAt(new DateTimeImmutable())
    ->systemInfo('KSeF Foundry example')
    ->addLine('Usługa konsultingowa', 3, '33.33', '23', 'szt.')
    ->addLine('Książka', 1, '49.90', '5', 'szt.')
    ->paymentMethod(6);

echo 'Totals: ' . json_encode($builder->totals()->toArray()) . "\n";
$xml = $builder->build(); // self-validates against the official FA(3) XSD
echo substr($xml, 0, 160) . "...\n";

// 2) Validate any existing XML without the builder.
$validator = new XmlValidator();
echo 'valid: ' . var_export($validator->validateFa3($xml)->isValid(), true) . "\n";

// 3) Invalid XML produces precise issues.
$broken = preg_replace('#<P_2>[^<]*</P_2>#', '', $xml) ?? $xml;
$result = $validator->validateFa3($broken);
echo 'broken valid: ' . var_export($result->isValid(), true) . "\n";
foreach ($result->errors() as $issue) {
    echo " line {$issue->line}: [{$issue->code}] " . substr($issue->message, 0, 140) . "\n";
}

try {
    $validator->assertValid($broken);
} catch (InvoiceValidationException $e) {
    echo 'assertValid threw with ' . count($e->issues()) . " issue(s)\n";
}
