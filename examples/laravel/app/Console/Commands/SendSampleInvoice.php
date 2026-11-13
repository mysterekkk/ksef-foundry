<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Jobs\SubmitInvoiceJob;

/** Queues a synthetic FA(3) sample invoice. Refuses to run outside the KSeF TEST environment. */
final class SendSampleInvoice extends Command
{
    protected $signature = 'ksef:send-sample';

    protected $description = 'Queue a synthetic sample FA(3) invoice (TEST environment only); run `php artisan queue:work` to process it';

    public function handle(KsefClient $ksef): int
    {
        if (!$ksef->environment()->isTest()) {
            $this->error('Refusing to send a sample invoice: the configured environment is not TEST.');

            return self::FAILURE;
        }
        $nip = $ksef->credentials()->context()->value;
        $xml = Fa3InvoiceBuilder::sampleXml($nip, 'FV/SAMPLE/' . bin2hex(random_bytes(4)));
        SubmitInvoiceJob::dispatch($xml);
        $this->info('Queued. Run: php artisan queue:work --stop-when-empty');

        return self::SUCCESS;
    }
}
