<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Http\HttpRequest;

/**
 * UPO (official receipt) download through the API endpoints. The downloadUrl values found in status
 * responses point to other hosts and are deliberately never followed (contract rule 6).
 */
final class UpoService
{
    public function __construct(private readonly AuthorizedClient $api)
    {
    }

    /** GET /sessions/{ref}/invoices/{invoiceRef}/upo */
    public function invoiceUpo(string $sessionReferenceNumber, string $invoiceReferenceNumber): DownloadedDocument
    {
        return $this->get('/sessions/' . rawurlencode($sessionReferenceNumber) . '/invoices/' . rawurlencode($invoiceReferenceNumber) . '/upo');
    }

    /** GET /sessions/{ref}/invoices/ksef/{ksefNumber}/upo */
    public function invoiceUpoByKsefNumber(string $sessionReferenceNumber, string $ksefNumber): DownloadedDocument
    {
        return $this->get('/sessions/' . rawurlencode($sessionReferenceNumber) . '/invoices/ksef/' . rawurlencode($ksefNumber) . '/upo');
    }

    /** GET /sessions/{ref}/upo/{upoRef}: one page of the collective session UPO (see SessionStatus::$upoPages). */
    public function sessionUpo(string $sessionReferenceNumber, string $upoReferenceNumber): DownloadedDocument
    {
        return $this->get('/sessions/' . rawurlencode($sessionReferenceNumber) . '/upo/' . rawurlencode($upoReferenceNumber));
    }

    private function get(string $path): DownloadedDocument
    {
        return InvoiceService::document($this->api->send(new HttpRequest('GET', $path, accept: 'application/xml', idempotent: true)));
    }
}
