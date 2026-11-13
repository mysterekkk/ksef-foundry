<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceAccepted;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceRejected;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\Support\JobSupport;

/**
 * Polls GET /sessions/{ref}/invoices/{invoiceRef} until a terminal status: released with a growing delay
 * while KSeF is still processing; InvoiceAccepted on 200, InvoiceRejected + failure on >= 400.
 * Read-only, so retries after network errors are safe.
 */
final class PollInvoiceStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;

    /** Give up (job fails) after this many seconds. */
    public int $maxSeconds = 7200;

    public function __construct(
        public readonly string $ksefConnection,
        public readonly string $sessionReferenceNumber,
        public readonly string $invoiceReferenceNumber,
        public readonly string $invoiceHash = '',
        public readonly ?int $startedAt = null,
    ) {
        $queue = config('ksef.jobs.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable())->setTimestamp(($this->startedAt ?? time()) + $this->maxSeconds);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(KsefManager $manager, Dispatcher $events): void
    {
        $sessions = $manager->connection($this->ksefConnection)->sessions();
        try {
            $status = $sessions->getInvoiceStatus($this->sessionReferenceNumber, $this->invoiceReferenceNumber);
        } catch (RateLimitException $e) {
            $this->release(JobSupport::releaseDelay($e->retryAfterSeconds(), 30));

            return;
        }
        if ($status->isAccepted()) {
            $events->dispatch(new InvoiceAccepted(
                $this->ksefConnection,
                $this->invoiceHash,
                (string) $status->ksefNumber,
                $this->invoiceReferenceNumber,
                $this->sessionReferenceNumber,
            ));

            return;
        }
        if ($status->isRejected()) {
            $events->dispatch(new InvoiceRejected(
                $this->ksefConnection,
                $this->invoiceHash,
                $status->status->code,
                $status->status->description,
                $status->status->details,
                $this->invoiceReferenceNumber,
                $this->sessionReferenceNumber,
            ));
            $this->fail(new InvoiceRejectedException(
                $status->status->code,
                $status->status->description,
                $status->status->details,
                $this->invoiceReferenceNumber,
                $this->sessionReferenceNumber,
            ));

            return;
        }
        $this->release(min(60, 5 * max(1, $this->attempts())));
    }

    public function failed(\Throwable $e): void
    {
        JobSupport::logger()->error('ksef.job.poll_failed', [
            'connection' => $this->ksefConnection,
            'invoiceReferenceNumber' => $this->invoiceReferenceNumber,
            'failure' => JobSupport::describe($e),
        ]);
    }
}
