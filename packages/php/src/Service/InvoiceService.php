<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\HttpResponse;

/** GET /invoices/ksef/{ksefNumber}: invoice XML + metadata hash header. */
final class InvoiceService
{
    public const KSEF_NUMBER_PATTERN = '/^([1-9](\d[1-9]|[1-9]\d)\d{7})-(20[2-9][0-9]|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-([0-9A-F]{6})-?([0-9A-F]{6})-([0-9A-F]{2})$/';

    private readonly \Closure $sleeper;

    /** @param (\Closure(float): void)|null $sleeper */
    public function __construct(private readonly AuthorizedClient $api, ?\Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (float $s): void {
            usleep((int) round($s * 1_000_000));
        };
    }

    /**
     * Downloads the invoice XML. Right after acceptance KSeF may answer "processed but not yet available"
     * (code 21165; observed live once as a bare HTTP 406): such answers are retried for up to
     * $availabilityTimeoutSeconds (GET is idempotent).
     */
    public function download(string $ksefNumber, float $availabilityTimeoutSeconds = 15.0): DownloadedDocument
    {
        if (preg_match(self::KSEF_NUMBER_PATTERN, $ksefNumber) !== 1) {
            throw new ConfigurationException('Not a valid KSeF number.');
        }
        $deadline = microtime(true) + $availabilityTimeoutSeconds;
        while (true) {
            try {
                $r = $this->api->send(new HttpRequest(
                    'GET',
                    '/invoices/ksef/' . rawurlencode($ksefNumber),
                    accept: 'application/xml',
                    idempotent: true,
                ));

                return self::document($r);
            } catch (ApiException $e) {
                $notYet = $e->hasKsefCode(21165) || $e->statusCode() === 406;
                if (!$notYet || microtime(true) + 1.0 >= $deadline) {
                    throw $e;
                }
                ($this->sleeper)(1.0);
            }
        }
    }

    /** @internal shared by InvoiceService and UpoService */
    public static function document(HttpResponse $r): DownloadedDocument
    {
        $computed = base64_encode(hash('sha256', $r->body, true));
        $declared = $r->header('x-ms-meta-hash');

        return new DownloadedDocument($r->body, $computed, $declared, $declared !== null && hash_equals($declared, $computed));
    }
}
