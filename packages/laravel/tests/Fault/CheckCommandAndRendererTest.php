<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Laravel\Tests\Fault;

use Illuminate\Support\Facades\Artisan;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;
use LuroWeb\KsefFoundry\Laravel\Exceptions\KsefExceptionRenderer;
use LuroWeb\KsefFoundry\Laravel\Support\RedactingLogger;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Laravel\Tests\Support\KsefLikeKeys;
use LuroWeb\KsefFoundry\Laravel\Tests\TestCase;

final class CheckCommandAndRendererTest extends TestCase
{
    private ?FaultServer $keep = null;

    private function limitsBody(): string
    {
        return (string) json_encode([
            'onlineSession' => ['maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 3, 'maxInvoices' => 10000],
            'batchSession' => ['maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 3, 'maxInvoices' => 10000],
        ]);
    }

    public function testCheckCommandReportsKeysAuthAndLimitsWithoutSecrets(): void
    {
        $keys = new KsefLikeKeys();
        $this->keep = new FaultServer([
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]],
            'GET /v2/limits/context' => [['status' => 200, 'body' => $this->limitsBody()]],
        ]);
        $this->useServer($this->keep);
        $this->seedTokens();

        $code = Artisan::call('ksef:check');
        $out = Artisan::output();
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Environment: CUSTOM', $out);
        self::assertStringContainsString('Public key KsefTokenEncryption: RSA-2048', $out);
        self::assertStringContainsString('Public key SymmetricKeyEncryption', $out);
        self::assertStringContainsString('Authentication: OK', $out);
        self::assertStringContainsString('max 10000 invoices', $out);
        self::assertStringContainsString(substr($this->nip, -4), $out);
        self::assertStringNotContainsString($this->nip, $out, 'identifier is masked');
        foreach ([self::SECRET_TOKEN, self::ACCESS, self::REFRESH, 'Bearer'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
    }

    public function testCheckCommandNoAuthSkipsLoginAndLimits(): void
    {
        $keys = new KsefLikeKeys();
        $this->keep = new FaultServer(['GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]]]);
        $this->useServer($this->keep);

        self::assertSame(0, Artisan::call('ksef:check', ['--no-auth' => true]));
        self::assertStringContainsString('OK (public keys only)', Artisan::output());
        self::assertSame(0, $this->keep->count('GET', '/v2/limits/context'));
    }

    public function testCheckCommandFailsCleanlyOnBadConfigAndOnApiError(): void
    {
        config(['ksef.connections.default.token' => null]);
        app(\LuroWeb\KsefFoundry\Laravel\KsefManager::class)->purge();
        self::assertSame(1, Artisan::call('ksef:check'));
        self::assertStringContainsString('FAILED: ConfigurationException', Artisan::output());

        config(['ksef.connections.default.token' => self::SECRET_TOKEN]);
        $keys = new KsefLikeKeys();
        $this->keep = new FaultServer([
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $keys->listJson()]],
            'GET /v2/limits/context' => [['status' => 403, 'body' => '{"title":"Forbidden","status":403,"detail":"no permission"}', 'headers' => ['Content-Type' => 'application/problem+json']]],
        ]);
        $this->useServer($this->keep);
        $this->seedTokens();
        self::assertSame(1, Artisan::call('ksef:check'));
        $out = Artisan::output();
        self::assertStringContainsString('FAILED: AuthorizationException', $out);
        self::assertStringNotContainsString(self::SECRET_TOKEN, $out);
    }

    public function testRendererMapsStatusesAndExposesOnlySafeFields(): void
    {
        $r = KsefExceptionRenderer::render(new RateLimitException('Too many requests', 429, [], [], 'trace-1', ['token' => self::ACCESS], 37));
        self::assertSame(429, $r->getStatusCode());
        self::assertSame('37', $r->headers->get('Retry-After'));
        $json = (string) $r->getContent();
        self::assertStringContainsString('"retryAfterSeconds":37', $json);
        self::assertStringNotContainsString(self::ACCESS, $json);

        $f = KsefExceptionRenderer::render(new AuthorizationException('Forbidden', 403, [21405], ['perm'], 't', []));
        self::assertSame(403, $f->getStatusCode());

        $rej = KsefExceptionRenderer::render(new InvoiceRejectedException(440, 'Duplikat', ['d'], 'inv', 'ses'));
        self::assertSame(422, $rej->getStatusCode());
        self::assertStringContainsString('"ksefStatus":440', (string) $rej->getContent());

        $u = KsefExceptionRenderer::render(new UnknownOutcomeException('unknown', 'HASH', 'SES'));
        self::assertSame(409, $u->getStatusCode());
        self::assertStringContainsString('HASH', (string) $u->getContent());

        $c = KsefExceptionRenderer::render(new ConfigurationException('secret path /etc/keys/private.pem'));
        self::assertSame(500, $c->getStatusCode());
        self::assertStringNotContainsString('/etc/keys', (string) $c->getContent());
    }

    public function testRendererDoesNotForwardUpstreamDetailsOrSecurityByDefault(): void
    {
        $nip = '5260250274';
        $problem = ['security' => ['requiredAnyOfPermissions' => ['InvoiceWrite'], 'subject' => $nip]];
        $e = new AuthorizationException('Forbidden for NIP ' . $nip, 403, [21405], ['taxpayer ' . $nip . ' lacks permission'], 'trace-9', $problem);

        $default = (string) KsefExceptionRenderer::render($e)->getContent();
        self::assertStringNotContainsString($nip, $default);
        self::assertStringNotContainsString('details', $default);
        self::assertStringNotContainsString('security', $default);
        self::assertStringContainsString('"traceId":"trace-9"', $default);

        config(['ksef.exceptions.expose_details' => true]);
        $open = (string) KsefExceptionRenderer::render($e)->getContent();
        self::assertStringContainsString($nip, $open);
        self::assertStringContainsString('requiredAnyOfPermissions', $open);
        self::assertStringContainsString('"details"', $open);

        $rej = new InvoiceRejectedException(440, 'Duplikat faktury', ['seller ' . $nip], 'i', 's');
        self::assertStringContainsString('Duplikat faktury', (string) KsefExceptionRenderer::render($rej)->getContent());
        config(['ksef.exceptions.expose_details' => false]);
        $closed = (string) KsefExceptionRenderer::render($rej)->getContent();
        self::assertStringNotContainsString('Duplikat', $closed);
        self::assertStringNotContainsString($nip, $closed);
        self::assertStringContainsString('"ksefStatus":440', $closed);
        self::assertStringContainsString($nip, (string) KsefExceptionRenderer::render($rej, true)->getContent(), 'explicit argument wins over config');
    }

    public function testRedactingLoggerScrubsMessageAndContextBeforeTheLaravelChannel(): void
    {
        $logger = new RedactingLogger(app('log')->channel('ksef-test'));
        $logger->warning('call failed Authorization: Bearer ' . self::ACCESS, ['authorization' => 'Bearer ' . self::ACCESS, 'note' => 'x ' . self::ACCESS, 'token' => self::SECRET_TOKEN]);
        $log = $this->logContents();
        self::assertStringContainsString('call failed', $log);
        self::assertStringNotContainsString(self::ACCESS, $log);
        self::assertStringNotContainsString(self::SECRET_TOKEN, $log);
        self::assertStringContainsString('[REDACTED]', $log);
    }
}
