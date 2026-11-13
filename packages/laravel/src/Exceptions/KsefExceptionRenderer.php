<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Exceptions;

use Illuminate\Http\JsonResponse;
use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Exception\NetworkException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;
use LuroWeb\KsefFoundry\Exception\XmlValidationException;

/**
 * Maps SDK exceptions to JSON responses with SAFE fields only (no raw problem body, no secrets).
 * Usage in bootstrap/app.php: ->withExceptions(fn ($e) => $e->render(KsefExceptionRenderer::callback()))
 *
 * Mapping: 429 -> 429 + Retry-After; 403 -> 403 (KSeF permissions); auth failure -> 502 (our credentials
 * are the problem, not the caller's); network -> 504/502; rejected invoice -> 422; unknown outcome -> 409;
 * XML validation -> 422; configuration -> 500.
 */
final class KsefExceptionRenderer
{
    /** @return \Closure(KsefException): JsonResponse */
    public static function callback(): \Closure
    {
        return static fn (KsefException $e): JsonResponse => self::render($e);
    }

    /**
     * @param bool|null $exposeDetails null = config('ksef.exceptions.expose_details', false). When false (default) upstream
     *                                 text, `details` and `security` (may contain taxpayer identifiers) are NOT forwarded.
     */
    public static function render(KsefException $e, ?bool $exposeDetails = null): JsonResponse
    {
        $expose = $exposeDetails ?? (bool) config('ksef.exceptions.expose_details', false);
        [$status, $headers] = self::status($e);
        $body = ['error' => ['type' => (new \ReflectionClass($e))->getShortName(), 'message' => self::message($e, $expose)]];
        if ($e instanceof ApiException) {
            $body['error']['ksefCode'] = $e->ksefCode();
            $body['error']['traceId'] = $e->traceId();
            if ($expose) {
                $body['error']['details'] = $e->details();
                $security = $e->problem()['security'] ?? null;
                if ($security !== null) {
                    $body['error']['security'] = $security;
                }
            }
        }
        if ($e instanceof RateLimitException && $e->retryAfterSeconds() !== null) {
            $body['error']['retryAfterSeconds'] = $e->retryAfterSeconds();
        }
        if ($e instanceof InvoiceRejectedException) {
            $body['error']['ksefStatus'] = $e->statusCode();
            if ($expose) {
                $body['error']['description'] = $e->description();
                $body['error']['details'] = $e->details();
            }
        }
        if ($e instanceof UnknownOutcomeException) {
            $body['error']['invoiceHash'] = $e->invoiceHash();
            $body['error']['sessionReferenceNumber'] = $e->sessionReferenceNumber();
        }

        return new JsonResponse($body, $status, $headers);
    }

    /** @return array{int, array<string, string>} */
    private static function status(KsefException $e): array
    {
        return match (true) {
            $e instanceof RateLimitException => [429, $e->retryAfterSeconds() !== null ? ['Retry-After' => (string) $e->retryAfterSeconds()] : []],
            $e instanceof AuthorizationException => [403, []],
            $e instanceof AuthenticationException => [502, []],
            $e instanceof InvoiceRejectedException, $e instanceof XmlValidationException => [422, []],
            $e instanceof UnknownOutcomeException => [409, []],
            $e instanceof NetworkException => [504, []],
            $e instanceof ConfigurationException => [500, []],
            $e instanceof ApiException => [502, []],
            default => [500, []],
        };
    }

    private static function message(KsefException $e, bool $expose): string
    {
        if ($expose && !($e instanceof ConfigurationException)) {
            $m = $e->diagnostics()['message'] ?? null;
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }

        return match (true) {
            $e instanceof ConfigurationException => 'KSeF integration is not configured correctly.',
            $e instanceof AuthenticationException => 'KSeF authentication failed.',
            $e instanceof AuthorizationException => 'KSeF denied the request (missing permissions).',
            $e instanceof RateLimitException => 'KSeF rate limit exceeded; retry later.',
            $e instanceof InvoiceRejectedException => 'The invoice was rejected by KSeF.',
            $e instanceof UnknownOutcomeException => 'The outcome of sending the invoice is unknown; reconcile before resending.',
            $e instanceof XmlValidationException => 'The invoice XML is not valid.',
            $e instanceof NetworkException => 'KSeF is unreachable.',
            default => 'KSeF request failed.',
        };
    }
}
