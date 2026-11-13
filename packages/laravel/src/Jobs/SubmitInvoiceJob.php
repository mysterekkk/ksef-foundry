<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;
use LuroWeb\KsefFoundry\Exception\ValidationException;
use LuroWeb\KsefFoundry\Exception\XmlValidationException;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceAccepted;
use LuroWeb\KsefFoundry\Laravel\Events\InvoiceRejected;
use LuroWeb\KsefFoundry\Laravel\KsefManager;
use LuroWeb\KsefFoundry\Laravel\Support\JobSupport;
use LuroWeb\KsefFoundry\Session\InvoiceSessionService;
use LuroWeb\KsefFoundry\Session\InvoiceStatus;
use LuroWeb\KsefFoundry\Session\SendOptions;

/**
 * Sends one FA(3) invoice duplicate-safely.
 *
 * - Unique per (connection, invoice hash): ShouldBeUnique, so the same XML is never queued twice at once.
 * - A "pending" marker (session reference) is stored BEFORE the send. After an ambiguous failure
 *   (UnknownOutcomeException, a crash, or any error after the send) the job NEVER resends blindly:
 *   it reconciles via InvoiceSessionService::reconcile() first and only resends when KSeF has no such invoice.
 * - 429: released with Retry-After (the request was not processed). Rejected (>= 400): failed, no retry.
 * - Slow processing: handed over to PollInvoiceStatusJob.
 *
 * The queue payload contains the invoice XML (your data, not a secret) and the connection NAME, never credentials.
 */
final class SubmitInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Releases (429, unknown outcome, lock busy) must not burn attempts; real exceptions are bounded by $maxExceptions. */
    public int $tries = 0;

    public int $maxExceptions = 6;

    /**
     * Seconds a single attempt may run (enforced by the worker with pcntl; on Windows only the lock expiry protects).
     * The queue connection's retry_after MUST be larger than this (README).
     */
    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $xml,
        public readonly string $ksefConnection = 'default',
    ) {
        $this->uniqueFor = JobSupport::int('unique_for', 3600);
        $this->timeout = max(10, JobSupport::int('timeout', 90));
        $queue = config('ksef.jobs.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
        $qc = config('ksef.jobs.queue_connection');
        if (is_string($qc) && $qc !== '') {
            $this->onConnection($qc);
        }
    }

    public function invoiceHash(): string
    {
        return base64_encode(hash('sha256', $this->xml, true));
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->ksefConnection . '|' . $this->invoiceHash());
    }

    public function uniqueVia(): Repository
    {
        return JobSupport::cache();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable())->modify('+' . $this->uniqueFor . ' seconds');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(KsefManager $manager, Dispatcher $events): void
    {
        // Two workers must never run the same invoice at once (e.g. an attempt that outlived retry_after): the second
        // would reconcile before the first one's invoice is visible and send a duplicate.
        $cache = JobSupport::cache();
        $store = $cache instanceof \Illuminate\Cache\Repository ? $cache->getStore() : null;
        $lock = $store instanceof LockProvider ? $store->lock('ksef:submit-run:' . $this->uniqueId(), $this->timeout + 30) : null;
        if ($lock !== null && !$lock->get()) {
            $this->release(JobSupport::releaseDelay(15));

            return;
        }
        try {
            $this->run($manager, $events);
        } finally {
            $lock?->release();
        }
    }

    private function run(KsefManager $manager, Dispatcher $events): void
    {
        $client = $manager->connection($this->ksefConnection);
        $sessions = $client->sessions();
        $cache = JobSupport::cache();
        $markerKey = 'ksef:submit:' . $this->uniqueId();
        $hash = $this->invoiceHash();

        try {
            $resolved = $this->resolvePrevious($sessions, $cache, $markerKey, $hash);
            if ($resolved === null) {
                $resolved = $this->sendFresh($sessions, $cache, $markerKey, $hash);
                if ($resolved === null) {
                    return; // ambiguous and not (yet) visible: released, the next attempt reconciles first
                }
            }
            [$sessionRef, $invoiceRef] = $resolved;
            $status = $sessions->awaitInvoice($sessionRef, $invoiceRef, JobSupport::float('await_seconds', 20.0));
            $cache->forget($markerKey);
            $this->closeQuietly($sessions, $sessionRef);
            $this->accepted($events, $hash, $status, $sessionRef);
        } catch (InvoiceRejectedException $e) {
            $cache->forget($markerKey);
            if ($e->sessionReferenceNumber() !== null) {
                $this->closeQuietly($sessions, $e->sessionReferenceNumber());
            }
            $original = $this->ownDuplicateOf($client, $e, $hash);
            if ($original !== null) {
                // status 440 for an invoice whose stored bytes are exactly ours: it was accepted earlier (e.g. by a
                // concurrent attempt). Report it as accepted with the existing KSeF number; never as rejected.
                $events->dispatch(new InvoiceAccepted(
                    $this->ksefConnection,
                    $hash,
                    $original,
                    (string) $e->invoiceReferenceNumber(),
                    (string) ($e->statusExtensions()['originalSessionReferenceNumber'] ?? $e->sessionReferenceNumber()),
                ));

                return;
            }
            $events->dispatch(new InvoiceRejected(
                $this->ksefConnection,
                $hash,
                $e->statusCode(),
                $e->description(),
                $e->details(),
                $e->invoiceReferenceNumber(),
                $e->sessionReferenceNumber(),
            ));
            $this->fail($e);
        } catch (TimeoutException $e) {
            // Still processing at KSeF: poll in a separate job; the invoice is known, so the marker is no longer needed.
            $marker = $this->readMarker($cache, $markerKey);
            if ($marker !== null && isset($marker['invoice'])) {
                $cache->forget($markerKey);
                PollInvoiceStatusJob::dispatch($this->ksefConnection, $marker['session'], $marker['invoice'], $hash)->delay(10);

                return;
            }
            throw $e;
        } catch (RateLimitException $e) {
            $this->release(JobSupport::releaseDelay($e->retryAfterSeconds(), $this->backoff()[min($this->attempts() - 1, 4)]));
        } catch (ValidationException | AuthorizationException | AuthenticationException | ConfigurationException | XmlValidationException $e) {
            $cache->forget($markerKey);
            $this->fail($e); // retrying cannot help; nothing was sent
        }
    }

    public function failed(\Throwable $e): void
    {
        JobSupport::logger()->error('ksef.job.submit_failed', [
            'connection' => $this->ksefConnection,
            'invoiceHash' => $this->invoiceHash(),
        ] + ['failure' => JobSupport::describe($e)]);
    }

    /**
     * A previous attempt may have sent the invoice. Reconcile before anything else.
     *
     * @return array{string, string}|null [session, invoice] when KSeF knows the invoice; null when there is nothing to resume
     */
    private function resolvePrevious(InvoiceSessionService $sessions, Repository $cache, string $markerKey, string $hash): ?array
    {
        $marker = $this->readMarker($cache, $markerKey);
        if ($marker === null) {
            return null;
        }
        if (isset($marker['invoice'])) {
            return [$marker['session'], $marker['invoice']]; // send was acknowledged earlier; only the status is missing
        }
        $found = $sessions->reconcile($marker['session'], $hash);
        if ($found === null) {
            $cache->forget($markerKey); // KSeF has no such invoice in that session: a resend is now allowed

            return null;
        }
        $this->remember($cache, $markerKey, $marker['session'], $found->referenceNumber);

        return [$marker['session'], $found->referenceNumber];
    }

    /** @return array{string, string}|null */
    private function sendFresh(InvoiceSessionService $sessions, Repository $cache, string $markerKey, string $hash): ?array
    {
        $session = $sessions->openOnline();
        $this->remember($cache, $markerKey, $session->referenceNumber, null);
        try {
            $sent = $sessions->sendInvoice($session, $this->xml, new SendOptions(validate: JobSupport::bool('validate_xml', true)));
        } catch (UnknownOutcomeException $unknown) {
            $delay = JobSupport::float('reconcile_delay', 3.0);
            if ($delay > 0) {
                usleep((int) round($delay * 1_000_000));
            }
            $found = $sessions->reconcile($session, (string) ($unknown->invoiceHash() ?? $hash));
            if ($found === null) {
                $this->closeQuietly($sessions, $session->referenceNumber);
                $this->release($this->backoff()[min($this->attempts() - 1, 4)]); // marker stays: next attempt reconciles again
                JobSupport::logger()->warning('ksef.job.submit_unknown_outcome', ['connection' => $this->ksefConnection, 'invoiceHash' => $hash]);

                return null;
            }
            $this->remember($cache, $markerKey, $session->referenceNumber, $found->referenceNumber);

            return [$session->referenceNumber, $found->referenceNumber];
        } catch (ApiException $e) {
            if (!$e instanceof RateLimitException) {
                $cache->forget($markerKey); // a definite 4xx: the request was not processed
            }
            $this->closeQuietly($sessions, $session->referenceNumber);

            throw $e;
        }
        $this->remember($cache, $markerKey, $session->referenceNumber, $sent->referenceNumber);

        return [$session->referenceNumber, $sent->referenceNumber];
    }

    /** KSeF number of the already stored invoice when status 440 refers to byte-identical content of ours; otherwise null. */
    private function ownDuplicateOf(KsefClient $client, InvoiceRejectedException $e, string $hash): ?string
    {
        $original = $e->statusExtensions()['originalKsefNumber'] ?? null;
        if ($e->statusCode() !== 440 || !is_string($original) || $original === '') {
            return null;
        }
        try {
            $doc = $client->invoices()->download($original);
        } catch (KsefException) {
            return null; // cannot prove it: stay conservative and report the rejection
        }

        return hash_equals($hash, $doc->sha256Base64) ? $original : null;
    }

    private function accepted(Dispatcher $events, string $hash, InvoiceStatus $status, string $sessionRef): void
    {
        $events->dispatch(new InvoiceAccepted(
            $this->ksefConnection,
            $hash,
            (string) $status->ksefNumber,
            $status->referenceNumber,
            $sessionRef,
        ));
    }

    private function closeQuietly(InvoiceSessionService $sessions, string $sessionRef): void
    {
        try {
            $sessions->close($sessionRef);
        } catch (KsefException) {
            // best effort; the session expires on its own
        }
    }

    private function remember(Repository $cache, string $key, string $session, ?string $invoice): void
    {
        $cache->put($key, json_encode(['session' => $session, 'invoice' => $invoice], JSON_THROW_ON_ERROR), $this->uniqueFor);
    }

    /** @return array{session: string, invoice?: string}|null */
    private function readMarker(Repository $cache, string $key): ?array
    {
        $raw = $cache->get($key);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['session']) || !is_string($data['session'])) {
            return null;
        }
        $out = ['session' => $data['session']];
        if (isset($data['invoice']) && is_string($data['invoice'])) {
            $out['invoice'] = $data['invoice'];
        }

        return $out;
    }
}
