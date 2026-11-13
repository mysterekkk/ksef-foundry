<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Unit;

use LuroWeb\KsefFoundry\Exception\ApiException;
use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\GoneException;
use LuroWeb\KsefFoundry\Exception\NotFoundException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Exception\ServerException;
use LuroWeb\KsefFoundry\Exception\SessionException;
use LuroWeb\KsefFoundry\Exception\ValidationException;
use LuroWeb\KsefFoundry\Http\ErrorMapper;
use LuroWeb\KsefFoundry\Http\HttpResponse;
use LuroWeb\KsefFoundry\Http\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryAndErrorMappingTest extends TestCase
{
    public function testRetryAfterSecondsAndHttpDate(): void
    {
        self::assertSame(30, RetryPolicy::parseRetryAfter('30'));
        self::assertSame(0, RetryPolicy::parseRetryAfter('0'));
        self::assertNull(RetryPolicy::parseRetryAfter(null));
        self::assertNull(RetryPolicy::parseRetryAfter('soon'));
        $now = 1_800_000_000;
        self::assertSame(90, RetryPolicy::parseRetryAfter(gmdate('D, d M Y H:i:s', $now + 90) . ' GMT', $now));
        self::assertSame(0, RetryPolicy::parseRetryAfter(gmdate('D, d M Y H:i:s', $now - 90) . ' GMT', $now));
    }

    public function testRetryMatrixMatchesContract(): void
    {
        $p = new RetryPolicy();
        foreach ([429, 502, 503, 504] as $s) {
            self::assertTrue($p->shouldRetryStatus($s, true), "idempotent $s");
        }
        self::assertTrue($p->shouldRetryStatus(429, false), '429 means not processed, retry allowed');
        foreach ([500, 502, 503, 504, 408] as $s) {
            self::assertFalse($p->shouldRetryStatus($s, false), "non-idempotent $s must never retry");
        }
        foreach ([400, 401, 403, 404, 500] as $s) {
            self::assertFalse($p->shouldRetryStatus($s, true), "idempotent $s");
        }
        self::assertTrue($p->shouldRetryNetworkError(true));
        self::assertFalse($p->shouldRetryNetworkError(false));
    }

    public function testBackoffMathsAreExponentialBoundedAndHonourRetryAfter(): void
    {
        $p = new RetryPolicy(baseDelaySeconds: 0.5, maxDelaySeconds: 4.0, maxRetryAfterSeconds: 10, jitter: false);
        self::assertSame([0.5, 1.0, 2.0, 4.0, 4.0], [$p->delaySeconds(1), $p->delaySeconds(2), $p->delaySeconds(3), $p->delaySeconds(4), $p->delaySeconds(9)]);
        self::assertSame(7.0, $p->delaySeconds(1, 7));
        self::assertSame(10.0, $p->delaySeconds(1, 3600), 'Retry-After is capped');
        $j = new RetryPolicy(baseDelaySeconds: 1.0, jitter: true);
        self::assertSame(0.5, $j->delaySeconds(1, null, 0.0));
        self::assertSame(1.0, $j->delaySeconds(1, null, 1.0));
        for ($i = 0; $i < 50; ++$i) {
            $d = $j->delaySeconds(2);
            self::assertGreaterThanOrEqual(1.0, $d);
            self::assertLessThanOrEqual(2.0, $d);
        }
    }

    /** @param array<string, list<string>> $headers */
    private function map(int $status, string $body, array $headers = []): ApiException
    {
        return (new ErrorMapper())->map(new HttpResponse($status, $headers, $body));
    }

    public function testProblemJson400IsValidationException(): void
    {
        $e = $this->map(400, (string) json_encode([
            'title' => 'Bad Request', 'status' => 400, 'instance' => '/x', 'detail' => 'Żądanie jest nieprawidłowe.',
            'errors' => [['code' => 21405, 'description' => 'Błąd walidacji danych wejściowych.', 'details' => ['pole X']], ['code' => 21001, 'description' => 'd']],
            'timestamp' => '2026-01-01T00:00:00Z', 'traceId' => 'trace-1',
        ]));
        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame(400, $e->statusCode());
        self::assertSame(21405, $e->ksefCode());
        self::assertSame([21405, 21001], $e->ksefCodes());
        self::assertSame(['pole X'], $e->details());
        self::assertSame('trace-1', $e->traceId());
        self::assertFalse($e->retryable());
        self::assertSame('validation', $e->category());
    }

    public function testLegacyExceptionResponseIsParsedToo(): void
    {
        $e = $this->map(400, (string) json_encode(['exception' => ['exceptionDetailList' => [['exceptionCode' => 21470, 'exceptionDescription' => 'Klucz', 'details' => ['k']]], 'serviceCode' => 's']]));
        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame(21470, $e->ksefCode());
        self::assertTrue($e->hasKsefCode(21470));
    }

    public function testDifferentStatusesAreNotTreatedAlike(): void
    {
        self::assertInstanceOf(AuthenticationException::class, $this->map(401, '{"title":"Unauthorized","status":401,"detail":"Wymagane jest uwierzytelnienie."}'));
        $f = $this->map(403, '{"title":"Forbidden","status":403,"detail":"d","reasonCode":"missing-permissions","security":{"requiredAnyOfPermissions":["InvoiceWrite"]}}');
        self::assertInstanceOf(AuthorizationException::class, $f);
        self::assertSame('missing-permissions', $f->reasonCode());
        self::assertSame(['InvoiceWrite'], $f->problem()['security']['requiredAnyOfPermissions']);
        self::assertInstanceOf(NotFoundException::class, $this->map(404, ''));
        self::assertInstanceOf(GoneException::class, $this->map(410, '{"title":"Gone","status":410,"detail":"x"}'));
        $s = $this->map(503, 'upstream down');
        self::assertInstanceOf(ServerException::class, $s);
        self::assertTrue($s->retryable());
        $five = $this->map(500, '');
        self::assertInstanceOf(ServerException::class, $five);
        self::assertFalse($five->retryable());
        self::assertInstanceOf(ApiException::class, $this->map(418, ''));
    }

    public function testRateLimitBothShapesWithRetryAfter(): void
    {
        $legacy = $this->map(429, '{"status":{"code":429,"description":"Too Many Requests","details":["Spróbuj ponownie po 30 sekundach."]}}', ['retry-after' => ['30']]);
        self::assertInstanceOf(RateLimitException::class, $legacy);
        self::assertSame(30, $legacy->retryAfterSeconds());
        self::assertTrue($legacy->retryable());
        $problem = $this->map(429, '{"title":"Too Many Requests","status":429,"detail":"limit","instance":"/x","timestamp":"t","traceId":"t1"}', ['retry-after' => ['5']]);
        self::assertInstanceOf(RateLimitException::class, $problem);
        self::assertSame(5, $problem->retryAfterSeconds());
        self::assertSame('t1', $problem->traceId());
    }

    public function testSessionRelatedCodesStayValidationExceptionsLikeTypeScript(): void
    {
        // Parity with the TS SDK: only sendInvoice wraps 21184 into SessionException; the mapper never does.
        foreach ([21173, 21180, 21184] as $code) {
            $e = $this->map(400, (string) json_encode(['errors' => [['code' => $code, 'description' => 'x']]]));
            self::assertInstanceOf(ValidationException::class, $e);
            self::assertSame($code, $e->ksefCode());
        }
    }

    public function testCategoriesMatchTheTypeScriptSdk(): void
    {
        $expected = [
            \LuroWeb\KsefFoundry\Exception\KsefException::class => 'ksef',
            \LuroWeb\KsefFoundry\Exception\ConfigurationException::class => 'configuration',
            \LuroWeb\KsefFoundry\Exception\NetworkException::class => 'network',
            \LuroWeb\KsefFoundry\Exception\TimeoutException::class => 'timeout',
            \LuroWeb\KsefFoundry\Exception\EncryptionException::class => 'encryption',
            \LuroWeb\KsefFoundry\Exception\CertificateException::class => 'certificate',
            \LuroWeb\KsefFoundry\Exception\XmlValidationException::class => 'xml_validation',
            \LuroWeb\KsefFoundry\Exception\SessionException::class => 'session',
        ];
        foreach ($expected as $class => $category) {
            self::assertSame($category, (new $class('m'))->category(), $class);
        }
        self::assertSame('invoice_rejected', (new \LuroWeb\KsefFoundry\Exception\InvoiceRejectedException(440, 'd'))->category());
        self::assertSame('unknown_outcome', (new \LuroWeb\KsefFoundry\Exception\UnknownOutcomeException('m'))->category());
        self::assertTrue((new \LuroWeb\KsefFoundry\Exception\NetworkException('m'))->retryable());
        $cats = [400 => 'validation', 401 => 'authentication', 403 => 'authorization', 404 => 'not_found', 410 => 'gone', 429 => 'rate_limit', 500 => 'server', 418 => 'api'];
        foreach ($cats as $status => $category) {
            self::assertSame($category, $this->map($status, '')->category(), (string) $status);
        }
    }

    public function testDiagnosticsAreSecretFreeAndBodiesAreNotCopied(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.c2lnbmF0dXJl';
        $e = $this->map(400, (string) json_encode(['errors' => [['code' => 21001, 'description' => 'bad ' . $jwt, 'details' => ['Bearer ' . $jwt]]], 'accessToken' => 'LEAK']));
        $dump = (string) json_encode($e->diagnostics()) . $e->getMessage();
        self::assertStringNotContainsString($jwt, $dump);
        self::assertStringNotContainsString('LEAK', $dump);
        $html = $this->map(502, '<html>' . str_repeat('A', 5000) . '</html>');
        self::assertLessThan(800, strlen((string) json_encode($html->diagnostics())));
    }
}
