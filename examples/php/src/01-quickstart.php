<?php

declare(strict_types=1);

use LuroWeb\KsefFoundry\Query\DateType;
use LuroWeb\KsefFoundry\Query\InvoiceQueryFilters;
use LuroWeb\KsefFoundry\Query\SubjectType;

require __DIR__ . '/bootstrap.php';

[$client, $nip, $cleanup] = connect();
try {
    echo "Context NIP (synthetic): {$nip}\n";

    $limits = $client->limits()->context();
    echo 'Context limits: ' . substr(json_encode($limits, JSON_UNESCAPED_UNICODE) ?: '', 0, 200) . "...\n";

    // Metadata of invoices where we are the seller (empty for a fresh taxpayer).
    $filters = new InvoiceQueryFilters(SubjectType::Subject1, DateType::Issue, new DateTimeImmutable('-30 days'));
    $count = 0;
    foreach ($client->query()->iterate($filters) as $meta) {
        $count++;
        echo $meta->ksefNumber . "\n";
    }
    echo "Found {$count} invoice(s).\n";
} finally {
    $cleanup();
}
