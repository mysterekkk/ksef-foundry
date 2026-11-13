<?php

declare(strict_types=1);

use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;

require __DIR__ . '/bootstrap.php';

[$client, $nip, $cleanup] = connect();
try {
    $number = 'FV/EX/' . time(); // must be unique per seller (KSeF rejects duplicates with 440)
    $xml = Fa3InvoiceBuilder::sampleXml($nip, $number);

    try {
        // open session -> encrypt -> send -> close -> poll until a FINAL status. HTTP 202 is NOT acceptance.
        $result = $client->sender()->submit($xml);
        echo "Accepted. KSeF number: {$result->ksefNumber}\n";

        $upo = $client->upo()->invoiceUpo($result->sessionReferenceNumber, $result->invoiceReferenceNumber);
        echo 'UPO size: ' . strlen($upo->xml) . "\n";

        $doc = $client->invoices()->download($result->ksefNumber);
        echo 'Downloaded XML hash verified: ' . var_export($doc->hashVerified, true) . "\n";
    } catch (InvoiceRejectedException $e) {
        fwrite(STDERR, 'KSeF rejected the invoice: ' . json_encode($e->diagnostics()) . "\n");
    } catch (UnknownOutcomeException $e) {
        // The request may or may not have been processed. NEVER resend blindly:
        // reconcile first ($client->sessions()->reconcile($session, $e->invoiceHash())).
        fwrite(STDERR, 'Unknown outcome; reconcile before resending. hash = ' . $e->invoiceHash() . "\n");
    }
} finally {
    $cleanup();
}
