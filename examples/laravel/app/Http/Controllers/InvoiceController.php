<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LuroWeb\KsefFoundry\Invoice\XmlValidator;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\Jobs\SubmitInvoiceJob;
use LuroWeb\KsefFoundry\Query\DateType;
use LuroWeb\KsefFoundry\Query\InvoiceQueryFilters;
use LuroWeb\KsefFoundry\Query\SubjectType;

/**
 * Demo controller: the KsefClient is injected (default connection from config/env).
 * KSeF failures are rendered by KsefExceptionRenderer (see bootstrap/app.php).
 */
final class InvoiceController
{
    public function __construct(private readonly KsefClient $ksef)
    {
    }

    public function limits(): JsonResponse
    {
        $l = $this->ksef->limits()->context();

        return response()->json([
            'environment' => $this->ksef->environment()->name,
            'onlineSession' => [
                'maxInvoices' => $l->onlineSession->maxInvoices,
                'maxInvoiceSizeInMB' => $l->onlineSession->maxInvoiceSizeInMB,
            ],
        ]);
    }

    /** GET /ksef/invoices?subject=Subject2&page=1&per_page=20&days=30 (one KSeF page per request). */
    public function index(Request $request): JsonResponse
    {
        $subject = SubjectType::tryFrom($request->string('subject', 'Subject2')->toString()) ?? SubjectType::Subject2;
        $page = max(1, $request->integer('page', 1));
        $perPage = min(250, max(10, $request->integer('per_page', 20)));
        $days = min(90, max(1, $request->integer('days', 30)));

        $filters = new InvoiceQueryFilters($subject, DateType::PermanentStorage, new \DateTimeImmutable("-{$days} days"));
        $result = $this->ksef->query()->queryMetadata($filters, $page - 1, $perPage);

        return response()->json([
            'page' => $page,
            'perPage' => $perPage,
            'hasMore' => $result->hasMore,
            'data' => array_map(static fn ($i): array => [
                'ksefNumber' => $i->ksefNumber,
                'invoiceNumber' => $i->invoiceNumber,
                'issueDate' => $i->issueDate,
                'sellerNip' => $i->sellerNip,
                'grossAmount' => $i->grossAmount,
                'currency' => $i->currency,
            ], $result->invoices),
        ]);
    }

    public function show(string $ksefNumber): Response
    {
        $doc = $this->ksef->invoices()->download($ksefNumber);

        return response($doc->xml, 200, ['Content-Type' => 'application/xml', 'X-Hash-Verified' => $doc->hashVerified ? '1' : '0']);
    }

    /** POST /ksef/validate with the raw FA(3) XML as the body. */
    public function validateXml(Request $request): JsonResponse
    {
        $result = (new XmlValidator())->validateFa3($request->getContent());

        return response()->json([
            'valid' => $result->isValid(),
            'issues' => array_map(static fn ($i): array => ['line' => $i->line, 'message' => $i->message], $result->errors()),
        ], $result->isValid() ? 200 : 422);
    }

    /** POST /ksef/invoices with raw FA(3) XML: validated, then queued (SubmitInvoiceJob is duplicate-safe). */
    public function submit(Request $request): JsonResponse
    {
        $xml = $request->getContent();
        $result = (new XmlValidator())->validateFa3($xml);
        if (!$result->isValid()) {
            return response()->json(['valid' => false, 'summary' => $result->summary()], 422);
        }
        SubmitInvoiceJob::dispatch($xml);

        return response()->json(['queued' => true, 'invoiceHash' => base64_encode(hash('sha256', $xml, true))], 202);
    }

    public function status(string $session, string $invoice): JsonResponse
    {
        $s = $this->ksef->sessions()->getInvoiceStatus($session, $invoice);

        return response()->json([
            'accepted' => $s->isAccepted(),
            'rejected' => $s->isRejected(),
            'statusCode' => $s->status->code,
            'description' => $s->status->description,
            'ksefNumber' => $s->ksefNumber,
        ]);
    }

    public function upo(string $session, string $invoice): Response
    {
        $doc = $this->ksef->upo()->invoiceUpo($session, $invoice);

        return response($doc->xml, 200, ['Content-Type' => 'application/xml']);
    }
}
