<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Fault;

use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Exception\RateLimitException;
use LuroWeb\KsefFoundry\Exception\SessionException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Exception\UnknownOutcomeException;
use LuroWeb\KsefFoundry\Session\SendOptions;
use LuroWeb\KsefFoundry\Session\SubmitOptions;
use LuroWeb\KsefFoundry\Tests\Support\FaultClient;
use LuroWeb\KsefFoundry\Tests\Support\FaultServer;
use LuroWeb\KsefFoundry\Tests\Support\KsefLikeKeys;
use PHPUnit\Framework\TestCase;

/** fault-injection: duplicate-safety of the send lifecycle against a REAL misbehaving local server. */
final class SendLifecycleFaultTest extends TestCase
{
    private const SESSION = '20260101-SO-ABCDEF0123-ABCDEF0123-AB';
    private const INVOICE = '20260101-EE-ABCDEF0123-ABCDEF0123-CD';
    private const KSEF = '7980332920-20260101-ABCDEF012345-EF';
    private const XML = '<Faktura xmlns="urn:test"><P_2>FV/1</P_2></Faktura>';

    private KsefLikeKeys $keys;

    protected function setUp(): void
    {
        $this->keys = new KsefLikeKeys();
    }

    private function hash(): string
    {
        return base64_encode(hash('sha256', self::XML, true));
    }

    /** @param array<string, list<array<string, mixed>>> $extra */
    private function server(array $extra): FaultServer
    {
        $base = [
            'GET /v2/security/public-key-certificates' => [['status' => 200, 'body' => $this->keys->listJson()]],
            'POST /v2/sessions/online' => [['status' => 201, 'body' => json_encode(['referenceNumber' => self::SESSION, 'validUntil' => gmdate('c', time() + 3600)])]],
            'POST /v2/sessions/online/' . self::SESSION . '/close' => [['status' => 204, 'body' => '']],
        ];

        return new FaultServer($extra + $base);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function invoiceStatus(int $code, string $desc = 'x', array $extra = []): array
    {
        return ['status' => 200, 'body' => json_encode([
            'ordinalNumber' => 1, 'referenceNumber' => self::INVOICE, 'invoiceHash' => $this->hash(), 'invoicingDate' => '2026-01-01T10:00:00+00:00',
            'ksefNumber' => $code === 200 ? self::KSEF : null, 'status' => ['code' => $code, 'description' => $desc] + $extra,
        ])];
    }

    private function noValidate(): SubmitOptions
    {
        return new SubmitOptions(send: new SendOptions(validate: false), reconcileDelaySeconds: 0.0, awaitTimeoutSeconds: 30.0);
    }

    public function testHappyPathNeedsStatus200NotJust202(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->invoiceStatus(100, 'queued'), $this->invoiceStatus(150, 'processing'), $this->invoiceStatus(200, 'ok')],
        ]);
        $c = new FaultClient($srv);
        $r = $c->client->sender()->submit(self::XML, $this->noValidate());
        self::assertSame(self::KSEF, $r->ksefNumber);
        self::assertTrue($r->sessionClosed);
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'));
        self::assertSame(3, $srv->count('GET', '/v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE), 'polled until 200');
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/close'));
    }

    public function testRejectedInvoiceRaisesWithStatusAndDetails(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->invoiceStatus(440, 'Duplikat faktury', ['details' => ['dup']])],
        ]);
        $c = new FaultClient($srv);
        try {
            $c->client->sender()->submit(self::XML, $this->noValidate());
            self::fail();
        } catch (InvoiceRejectedException $e) {
            self::assertSame(440, $e->statusCode());
            self::assertSame(['dup'], $e->details());
            self::assertSame(self::INVOICE, $e->invoiceReferenceNumber());
        }
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/close'), 'session is closed even on rejection');
    }

    public function testAwaitInvoiceTimesOutInsteadOfClaimingSuccess(): void
    {
        $srv = $this->server([
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->invoiceStatus(150, 'processing')],
        ]);
        $c = new FaultClient($srv, true, null, 900, static function (float $s): void {
            usleep(50_000);
        });
        $this->expectException(TimeoutException::class);
        $c->client->sessions()->awaitInvoice(self::SESSION, self::INVOICE, 0.4, 0.05);
    }

    public function testAmbiguousSendThrowsUnknownOutcomeWithHashAndNeverResends(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['action' => 'reset']],
        ]);
        $c = new FaultClient($srv);
        $s = $c->client->sessions();
        $session = $s->openOnline();
        try {
            $s->sendInvoice($session, self::XML, new SendOptions(validate: false));
            self::fail();
        } catch (UnknownOutcomeException $e) {
            self::assertSame($this->hash(), $e->invoiceHash());
            self::assertSame(self::SESSION, $e->sessionReferenceNumber());
            self::assertArrayHasKey('invoiceHash', $e->diagnostics());
            self::assertStringNotContainsString(base64_encode(self::XML), (string) json_encode($e->diagnostics()));
        }
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'));
    }

    public function testServerErrorOnSendIsAlsoAmbiguous(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 503, 'body' => ''], ['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
        ]);
        $c = new FaultClient($srv);
        $session = $c->client->sessions()->openOnline();
        $this->expectException(UnknownOutcomeException::class);
        try {
            $c->client->sessions()->sendInvoice($session, self::XML, new SendOptions(validate: false));
        } finally {
            self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'));
        }
    }

    public function testSenderReconcilesAndFindsInvoiceWithoutResending(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['action' => 'reset']],
            'GET /v2/sessions/' . self::SESSION . '/invoices' => [['status' => 200, 'body' => json_encode(['invoices' => [
                json_decode($this->invoiceStatus(100)['body'], true),
            ]])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->invoiceStatus(200)],
        ]);
        $c = new FaultClient($srv);
        $r = $c->client->sender()->submit(self::XML, $this->noValidate());
        self::assertSame(self::KSEF, $r->ksefNumber);
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'), 'reconcile found it: NO duplicate send');
        self::assertSame(1, $srv->count('GET', '/v2/sessions/' . self::SESSION . '/invoices'));
    }

    public function testSenderRethrowsWhenAbsentUnlessResendIsExplicitlyEnabled(): void
    {
        $script = [
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['action' => 'reset'], ['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices' => [['status' => 200, 'body' => '{"invoices":[]}']],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->invoiceStatus(200)],
        ];
        $srv = $this->server($script);
        $c = new FaultClient($srv);
        try {
            $c->client->sender()->submit(self::XML, $this->noValidate());
            self::fail('absent invoice + default options: caller decides');
        } catch (UnknownOutcomeException) {
            self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'));
        }

        $srv2 = $this->server($script);
        $c2 = new FaultClient($srv2);
        $opts = new SubmitOptions(send: new SendOptions(validate: false), resendIfAbsent: true, reconcileDelaySeconds: 0.0);
        $r = $c2->client->sender()->submit(self::XML, $opts);
        self::assertSame(self::KSEF, $r->ksefNumber);
        self::assertSame(2, $srv2->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'), 'resend only after reconcile proved absence');
    }

    public function testSessionUnavailable21184IsSessionException(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 400, 'body' => json_encode(['errors' => [['code' => 21184, 'description' => 'Sesja tymczasowo niedostępna.']]])]],
        ]);
        $c = new FaultClient($srv);
        $session = $c->client->sessions()->openOnline();
        try {
            $c->client->sessions()->sendInvoice($session, self::XML, new SendOptions(validate: false));
            self::fail();
        } catch (SessionException $e) {
            self::assertSame(21184, $e->ksefCode());
            self::assertSame([21184], $e->ksefCodes());
            self::assertSame(400, $e->statusCode());
            self::assertSame(self::SESSION, $e->sessionReferenceNumber());
            self::assertInstanceOf(\LuroWeb\KsefFoundry\Exception\ValidationException::class, $e->getPrevious());
        }
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online/' . self::SESSION . '/invoices'));
    }

    public function testRateLimitedSendIsRetriedAfterRetryAfter(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [
                ['status' => 429, 'headers' => ['Retry-After' => '3'], 'body' => '{"status":{"code":429,"description":"Too Many Requests","details":[]}}'],
                ['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])],
            ],
        ]);
        $c = new FaultClient($srv);
        $session = $c->client->sessions()->openOnline();
        $sent = $c->client->sessions()->sendInvoice($session, self::XML, new SendOptions(validate: false));
        self::assertSame(self::INVOICE, $sent->referenceNumber);
        self::assertSame([3.0], $c->sleeps);
    }

    public function testPersistent429IsRateLimitNotUnknownOutcome(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 429, 'headers' => ['Retry-After' => '1'], 'body' => '{}']],
        ]);
        $c = new FaultClient($srv);
        $session = $c->client->sessions()->openOnline();
        $this->expectException(RateLimitException::class);
        $c->client->sessions()->sendInvoice($session, self::XML, new SendOptions(validate: false));
    }

    public function testOpenSessionRefetchesKeysOnceOnError21470(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online' => [
                ['status' => 400, 'body' => json_encode(['errors' => [['code' => 21470, 'description' => 'Klucz nieznany']]])],
                ['status' => 201, 'body' => json_encode(['referenceNumber' => self::SESSION, 'validUntil' => gmdate('c', time() + 3600)])],
            ],
        ]);
        $c = new FaultClient($srv);
        $s = $c->client->sessions()->openOnline();
        self::assertSame(self::SESSION, $s->referenceNumber);
        self::assertSame(2, $srv->count('POST', '/v2/sessions/online'));
        self::assertSame(2, $srv->count('GET', '/v2/security/public-key-certificates'), 'keys refetched exactly once');
        $bodies = array_values(array_filter($srv->requests(), static fn (array $r): bool => $r['path'] === '/v2/sessions/online'));
        $first = json_decode((string) $bodies[0]['body'], true);
        self::assertSame($this->keys->publicKeyId, $first['encryption']['publicKeyId']);
        self::assertSame(16, strlen((string) base64_decode($first['encryption']['initializationVector'], true)));
        // the encrypted session key decrypts to 32 bytes with the matching private key
        $cipher = (string) base64_decode($first['encryption']['encryptedSymmetricKey'], true);
        self::assertSame(32, strlen($c->client->crypto()->rsaOaepSha256Decrypt($cipher, $this->keys->privateKeyPem)));
    }

    public function testPersistent21470IsNotRetriedMoreThanOnce(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online' => [['status' => 400, 'body' => json_encode(['errors' => [['code' => 21470, 'description' => 'k']]])]],
        ]);
        $c = new FaultClient($srv);
        try {
            $c->client->sessions()->openOnline();
            self::fail();
        } catch (\LuroWeb\KsefFoundry\Exception\ValidationException $e) {
            self::assertSame(21470, $e->ksefCode());
        }
        self::assertSame(2, $srv->count('POST', '/v2/sessions/online'));
    }

    public function testSentPayloadMatchesDocumentedEncryptedInvoiceContract(): void
    {
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
        ]);
        $c = new FaultClient($srv);
        $session = $c->client->sessions()->openOnline();
        $c->client->sessions()->sendInvoice($session, self::XML, new SendOptions(validate: false));
        $req = array_values(array_filter($srv->requests(), static fn (array $r): bool => str_ends_with((string) $r['path'], '/invoices')))[0];
        $body = json_decode((string) $req['body'], true);
        $cipher = (string) base64_decode($body['encryptedInvoiceContent'], true);
        self::assertSame(strlen(self::XML), $body['invoiceSize']);
        self::assertSame($this->hash(), $body['invoiceHash']);
        self::assertSame(strlen($cipher), $body['encryptedInvoiceSize']);
        self::assertSame(base64_encode(hash('sha256', $cipher, true)), $body['encryptedInvoiceHash']);
        self::assertSame(self::XML, $c->client->crypto()->aes256CbcDecrypt($cipher, $session->key()));
        self::assertStringNotContainsString('Faktura', (string) $req['body'], 'plain XML never travels');
    }

    public function testInvalidXmlNeverOpensASession(): void
    {
        $srv = $this->server([]);
        $c = new FaultClient($srv);
        $bad = [
            'not xsd valid' => self::XML,
            'not well formed' => '<a>',
            'doctype' => '<!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>',
            'bom' => "ï»¿<a/>",
            'empty' => '',
        ];
        foreach ($bad as $name => $xml) {
            try {
                $c->client->sender()->submit($xml);
                self::fail($name . ' must be rejected');
            } catch (\LuroWeb\KsefFoundry\Exception\XmlValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $srv->requests(), 'no request at all (no session open/close, no key fetch) for invalid XML');
        self::assertSame(0, $srv->count('POST', '/v2/sessions/online'));
    }

    public function testValidXmlIsValidatedOnceAndPassesThrough(): void
    {
        $xml = \LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder::sampleXml('7980332920', 'FV/FAULT/1');
        $srv = $this->server([
            'POST /v2/sessions/online/' . self::SESSION . '/invoices' => [['status' => 202, 'body' => json_encode(['referenceNumber' => self::INVOICE])]],
            'GET /v2/sessions/' . self::SESSION . '/invoices/' . self::INVOICE => [$this->invoiceStatus(200)],
        ]);
        $c = new FaultClient($srv);
        $r = $c->client->sender()->submit($xml);
        self::assertSame(self::KSEF, $r->ksefNumber);
        self::assertSame(1, $srv->count('POST', '/v2/sessions/online'));
    }
}
