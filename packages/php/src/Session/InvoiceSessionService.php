<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Crypto\Crypto;
use LuroWeb\KsefFoundry\Crypto\PublicKeyProvider;
use LuroWeb\KsefFoundry\Crypto\PublicKeyUsage;
use LuroWeb\KsefFoundry\Crypto\SessionKey;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Exception\NetworkException;
use LuroWeb\KsefFoundry\Exception\ServerException;
use LuroWeb\KsefFoundry\Exception\SessionException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;
use LuroWeb\KsefFoundry\Exception\ValidationException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use LuroWeb\KsefFoundry\Support\Arr;
use LuroWeb\KsefFoundry\Support\Json;
use LuroWeb\KsefFoundry\Support\PageGuard;

/**
 * Interactive (online) session: open, send, close, status, failed invoices, reconciliation.
 *
 * Duplicate safety (contract section 6): open/send/close are never auto-retried after an ambiguous
 * failure (only after HTTP 429). An ambiguous failure on send raises UnknownOutcomeException carrying
 * the invoice hash; call reconcile() before ever resending.
 */
final class InvoiceSessionService
{
    private readonly \Closure $sleeper;

    /**
     * @param (\Closure(float): void)|null $sleeper
     */
    public function __construct(
        private readonly AuthorizedClient $api,
        private readonly PublicKeyProvider $keys,
        private readonly Crypto $crypto = new Crypto(),
        ?\Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
    }

    /** POST /sessions/online */
    public function openOnline(?FormCode $formCode = null): OnlineSession
    {
        $formCode ??= FormCode::fa3();
        $key = SessionKey::generate();
        for ($attempt = 1;; ++$attempt) {
            $pub = $this->keys->get(PublicKeyUsage::SymmetricKeyEncryption);
            $body = [
                'formCode' => $formCode->toArray(),
                'encryption' => [
                    'encryptedSymmetricKey' => $this->crypto->encryptSessionKey($key, $pub),
                    'initializationVector' => base64_encode($key->iv()),
                    'publicKeyId' => $pub->publicKeyId,
                ],
            ];
            try {
                $r = $this->api->send(new HttpRequest('POST', '/sessions/online', body: Json::encode($body)));
                $a = $r->json();

                return new OnlineSession(Arr::string($a, 'referenceNumber'), Arr::date($a, 'validUntil'), $formCode, $key);
            } catch (ApiException $e) {
                if ($attempt === 1 && $e->hasKsefCode(21470)) {
                    $this->keys->refresh(); // rotated key: refetch once, retry once
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Runs the pre-send validation (no network, no session): size, UTF-8/BOM/DOCTYPE/PI/forbidden characters and XSD.
     * With SendOptions::$validate false only the safety checks (no XSD) run.
     *
     * @throws \LuroWeb\KsefFoundry\Exception\XmlValidationException
     */
    public function validateXml(#[\SensitiveParameter] string $xml, ?FormCode $formCode = null, ?SendOptions $options = null): void
    {
        $options ??= new SendOptions();
        if (!$options->validate) {
            // DOCTYPE / processing instructions / BOM / forbidden characters are rejected even with validate=false
            // (KSeF rejects them too); only the XSD step is skipped.
            ($options->validator ?? new XmlValidator())->assertSafe($xml);

            return;
        }
        ($options->validator ?? $this->defaultValidator($formCode ?? FormCode::fa3()))->assertValid($xml);
    }

    /**
     * POST /sessions/online/{ref}/invoices. Returns when KSeF QUEUED the invoice (HTTP 202); this is not acceptance.
     *
     * @throws UnknownOutcomeException on timeout / connection reset / 5xx: reconcile before resending
     */
    public function sendInvoice(OnlineSession $session, #[\SensitiveParameter] string $xml, ?SendOptions $options = null): SentInvoice
    {
        $options ??= new SendOptions();
        $this->validateXml($xml, $session->formCode, $options);
        if ($session->validUntil->getTimestamp() <= time()) {
            throw new SessionException('The online session has expired locally (validUntil in the past).');
        }
        $enc = $this->crypto->encryptInvoice($xml, $session->key());
        $body = $enc->toArray();
        if ($options->offlineMode) {
            $body['offlineMode'] = true;
        }
        if ($options->hashOfCorrectedInvoice !== null) {
            $body['hashOfCorrectedInvoice'] = $options->hashOfCorrectedInvoice;
        }
        try {
            $r = $this->api->send(new HttpRequest(
                'POST',
                '/sessions/online/' . rawurlencode($session->referenceNumber) . '/invoices',
                body: Json::encode($body),
            ));
        } catch (ValidationException $e) {
            if ($e->hasKsefCode(21184)) {
                throw new SessionException(
                    'Session temporarily unavailable (21184); open a new session',
                    $e,
                    $e->statusCode(),
                    $e->ksefCodes(),
                    $session->referenceNumber,
                );
            }

            throw $e;
        } catch (NetworkException | ServerException $e) {
            throw new UnknownOutcomeException(
                'The outcome of sending the invoice is unknown (' . $e::class . '). Reconcile by invoice hash before any resend.',
                $enc->invoiceHash,
                $session->referenceNumber,
                $e,
            );
        }
        try {
            $ref = Arr::string($r->json(), 'referenceNumber');
        } catch (KsefException $e) {
            throw new UnknownOutcomeException(
                'KSeF answered 2xx but the answer could not be read; the invoice may have been queued. Reconcile before resending.',
                $enc->invoiceHash,
                $session->referenceNumber,
                $e,
            );
        }

        return new SentInvoice($ref, $enc->invoiceHash, $session->referenceNumber);
    }

    /** POST /sessions/online/{ref}/close (204). Starts generation of the collective UPO. */
    public function close(OnlineSession|string $session): void
    {
        $ref = $session instanceof OnlineSession ? $session->referenceNumber : $session;
        $this->api->send(new HttpRequest('POST', '/sessions/online/' . rawurlencode($ref) . '/close'));
    }

    /** GET /sessions/{ref} */
    public function getStatus(string $sessionReferenceNumber): SessionStatus
    {
        $r = $this->api->send(new HttpRequest('GET', '/sessions/' . rawurlencode($sessionReferenceNumber), idempotent: true));

        return SessionStatus::fromArray($r->json());
    }

    /** GET /sessions/{ref}/invoices/{invoiceRef} */
    public function getInvoiceStatus(string $sessionReferenceNumber, string $invoiceReferenceNumber): InvoiceStatus
    {
        $r = $this->api->send(new HttpRequest(
            'GET',
            '/sessions/' . rawurlencode($sessionReferenceNumber) . '/invoices/' . rawurlencode($invoiceReferenceNumber),
            idempotent: true,
        ));

        return InvoiceStatus::fromArray($r->json());
    }

    /** GET /sessions/{ref}/invoices (one page; pageSize 10..1000) */
    public function listSessionInvoices(string $sessionReferenceNumber, ?int $pageSize = null, ?string $continuationToken = null): InvoicePage
    {
        return $this->page('/sessions/' . rawurlencode($sessionReferenceNumber) . '/invoices', $pageSize, $continuationToken);
    }

    /** GET /sessions/{ref}/invoices/failed (one page) */
    public function listFailedInvoices(string $sessionReferenceNumber, ?int $pageSize = null, ?string $continuationToken = null): InvoicePage
    {
        return $this->page('/sessions/' . rawurlencode($sessionReferenceNumber) . '/invoices/failed', $pageSize, $continuationToken);
    }

    /**
     * Lazily walks every invoice of a session (continuation-token pagination).
     *
     * @return \Generator<int, InvoiceStatus>
     */
    public function iterateSessionInvoices(string $sessionReferenceNumber, ?int $pageSize = null): \Generator
    {
        $token = null;
        $guard = new PageGuard();
        do {
            $page = $this->listSessionInvoices($sessionReferenceNumber, $pageSize, $token);
            yield from $page->invoices;
            $token = $page->continuationToken;
            $guard->advance($token);
        } while ($token !== null);
    }

    /**
     * Looks the invoice up in the session by hash. null means KSeF has no such invoice in this session
     * (only then may the caller resend).
     */
    public function reconcile(OnlineSession|string $session, string $invoiceHash): ?InvoiceStatus
    {
        $ref = $session instanceof OnlineSession ? $session->referenceNumber : $session;
        foreach ($this->iterateSessionInvoices($ref) as $inv) {
            if (hash_equals($inv->invoiceHash, $invoiceHash)) {
                return $inv;
            }
        }

        return null;
    }

    /**
     * Polls (back-off 1s..5s) until the invoice is accepted (status 200) or rejected (>= 400).
     *
     * @throws InvoiceRejectedException status >= 400 (e.g. 440 duplicate)
     * @throws TimeoutException deadline reached while still processing
     */
    public function awaitInvoice(
        string $sessionReferenceNumber,
        string $invoiceReferenceNumber,
        float $timeoutSeconds = 120.0,
        float $initialIntervalSeconds = 1.0,
    ): InvoiceStatus {
        $deadline = microtime(true) + $timeoutSeconds;
        $interval = $initialIntervalSeconds;
        while (true) {
            $status = $this->getInvoiceStatus($sessionReferenceNumber, $invoiceReferenceNumber);
            if ($status->isAccepted()) {
                return $status;
            }
            if ($status->isRejected()) {
                throw new InvoiceRejectedException(
                    $status->status->code,
                    $status->status->description,
                    $status->status->details,
                    $invoiceReferenceNumber,
                    $sessionReferenceNumber,
                    $status->status->extensions,
                );
            }
            if (microtime(true) + $interval >= $deadline) {
                throw new TimeoutException(sprintf(
                    'Invoice still processing after %.0fs (status %d).',
                    $timeoutSeconds,
                    $status->status->code
                ));
            }
            ($this->sleeper)($interval);
            $interval = min(5.0, $interval * 1.5);
        }
    }

    private function page(string $path, ?int $pageSize, ?string $token): InvoicePage
    {
        $headers = $token !== null ? ['x-continuation-token' => $token] : [];
        $r = $this->api->send(new HttpRequest('GET', $path, query: ['pageSize' => $pageSize], idempotent: true, headers: $headers));

        return InvoicePage::fromArray($r->json());
    }

    private function defaultValidator(FormCode $formCode): XmlValidator
    {
        if ($formCode->systemCode !== 'FA (3)') {
            throw new ConfigurationException(
                'Only FA (3) has a bundled schema: pass SendOptions::$validator or disable validation for other forms.'
            );
        }

        return new XmlValidator();
    }
}
