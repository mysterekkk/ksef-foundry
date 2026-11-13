<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;

/**
 * High-level submit: open session, send, (reconcile on ambiguity), await final status, close session.
 * Never reports success without invoice status 200.
 */
final class InvoiceSender
{
    private readonly \Closure $sleeper;

    /**
     * @param (\Closure(float): void)|null $sleeper
     */
    public function __construct(private readonly InvoiceSessionService $sessions, ?\Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
    }

    /**
     * @throws \LuroWeb\KsefFoundry\Exception\InvoiceRejectedException final status >= 400
     * @throws UnknownOutcomeException ambiguous send that reconcile could not resolve (or resend disabled)
     */
    public function submit(#[\SensitiveParameter] string $xml, ?SubmitOptions $options = null): SubmissionResult
    {
        $options ??= new SubmitOptions();
        // Validate BEFORE opening a session: invalid XML must never cost a session open/close (rate limits).
        $this->sessions->validateXml($xml, $options->formCode ?? FormCode::fa3(), $options->send);
        $send = new SendOptions(
            false,
            null,
            $options->send->offlineMode,
            $options->send->hashOfCorrectedInvoice,
        ); // already validated above
        $options = new SubmitOptions(
            $options->formCode,
            $send,
            $options->awaitTimeoutSeconds,
            $options->resendIfAbsent,
            $options->reconcileDelaySeconds,
        );
        $session = $this->sessions->openOnline($options->formCode);
        try {
            $sent = $this->sendWithReconcile($session, $xml, $options);
            $status = $this->sessions->awaitInvoice(
                $session->referenceNumber,
                $sent->referenceNumber,
                $options->awaitTimeoutSeconds,
            );
        } catch (\Throwable $e) {
            $this->closeQuietly($session);

            throw $e;
        }
        $closed = $this->closeQuietly($session);

        return new SubmissionResult(
            (string) $status->ksefNumber,
            $sent->referenceNumber,
            $session->referenceNumber,
            $sent->invoiceHash,
            $status->upoDownloadUrl !== null,
            $closed,
            $status,
        );
    }

    private function sendWithReconcile(OnlineSession $session, #[\SensitiveParameter] string $xml, SubmitOptions $options): SentInvoice
    {
        try {
            return $this->sessions->sendInvoice($session, $xml, $options->send);
        } catch (UnknownOutcomeException $unknown) {
            ($this->sleeper)($options->reconcileDelaySeconds);
            $hash = (string) $unknown->invoiceHash();
            $found = $this->sessions->reconcile($session, $hash);
            if ($found !== null) {
                return new SentInvoice($found->referenceNumber, $hash, $session->referenceNumber);
            }
            if ($options->resendIfAbsent) {
                return $this->sessions->sendInvoice($session, $xml, $options->send);
            }

            throw $unknown;
        }
    }

    private function closeQuietly(OnlineSession $session): bool
    {
        try {
            $this->sessions->close($session);

            return true;
        } catch (KsefException) {
            return false;
        }
    }
}
