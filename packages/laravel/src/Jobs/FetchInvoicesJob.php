<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Laravel\Contracts\InvoiceSink;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\Support\JobSupport;
use LuroWeb\KsefFoundry\Query\DateType;
use LuroWeb\KsefFoundry\Query\InvoiceQueryFilters;
use LuroWeb\KsefFoundry\Query\SortOrder;
use LuroWeb\KsefFoundry\Query\SubjectType;

/**
 * Incremental metadata fetch (DateType::PermanentStorage, ascending, restricted to the HWM date as KSeF
 * documents). The cursor (max permanentStorageDate seen) lives in YOUR InvoiceSink; nothing is persisted here.
 * Invoices at the cursor boundary can be delivered again: make InvoiceSink::accept() idempotent by KSeF number.
 */
final class FetchInvoicesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 900;

    /** @param string $subjectType a SubjectType value: Subject1 (seller), Subject2 (buyer), Subject3, SubjectAuthorized */
    public function __construct(
        public readonly string $ksefConnection = 'default',
        public readonly string $subjectType = 'Subject2',
        public readonly ?string $from = null,
    ) {
        SubjectType::from($subjectType);
        $queue = config('ksef.jobs.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->ksefConnection . '|' . $this->subjectType;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(KsefManager $manager, InvoiceSink $sink): void
    {
        $client = $manager->connection($this->ksefConnection);
        $start = $this->from !== null
            ? new \DateTimeImmutable($this->from)
            : ($sink->cursor($this->ksefConnection)
                ?? (new \DateTimeImmutable())->modify('-' . JobSupport::int('fetch_lookback_days', 30) . ' days'));
        $filters = new InvoiceQueryFilters(
            SubjectType::from($this->subjectType),
            DateType::PermanentStorage,
            $start,
            restrictToPermanentStorageHwmDate: true,
        );
        $max = null;
        $unsaved = 0;
        try {
            foreach ($client->query()->iterate($filters, 100, SortOrder::Asc) as $invoice) {
                $sink->accept($this->ksefConnection, $invoice);
                if ($max === null || $invoice->permanentStorageDate > $max) {
                    $max = $invoice->permanentStorageDate;
                }
                if (++$unsaved >= 100) {
                    $sink->saveCursor($this->ksefConnection, $max);
                    $unsaved = 0;
                }
            }
        } catch (RateLimitException $e) {
            if ($max !== null && $unsaved > 0) {
                $sink->saveCursor($this->ksefConnection, $max);
            }
            $this->release(JobSupport::releaseDelay($e->retryAfterSeconds(), 60));

            return;
        }
        if ($max !== null && $unsaved > 0) {
            $sink->saveCursor($this->ksefConnection, $max);
        }
    }

    public function failed(\Throwable $e): void
    {
        JobSupport::logger()->error('ksef.job.fetch_failed', [
            'connection' => $this->ksefConnection,
            'failure' => JobSupport::describe($e),
        ]);
    }
}
