<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Live;

use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Crypto\PublicKeyUsage;
use LuroWeb\KsefFoundry\Exception\InvoiceRejectedException;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Query\DateType;
use LuroWeb\KsefFoundry\Query\InvoiceQueryFilters;
use LuroWeb\KsefFoundry\Query\SubjectType;
use LuroWeb\KsefFoundry\Service\TokenPermission;
use LuroWeb\KsefFoundry\Service\TokenStatus;
use LuroWeb\KsefFoundry\Session\InvoiceStatus;
use LuroWeb\KsefFoundry\Tests\Support\LiveFixture;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * LIVE TEST scenario (contract section 9) against https://api-test.ksef.mf.gov.pl. Opt-in: KSEF_LIVE_TEST=1.
 * Synthetic NIP/PESEL only. Steps are chained with #[Depends]; a failed step skips the dependants.
 */
final class LiveScenarioTest extends TestCase
{
    private static ?LiveFixture $fx = null;
    private static ?KsefClient $tokenClient = null;
    private static ?string $tokenRef = null;
    private static ?string $invoiceNumber = null;
    private static ?string $ksefNumber = null;
    private static ?string $sessionRef = null;

    protected function setUp(): void
    {
        if (!LiveFixture::enabled()) {
            $this->markTestSkipped('SKIPPED (live TEST disabled: set KSEF_LIVE_TEST=1)');
        }
        if (LiveFixture::inMaintenanceWindow()) {
            $this->markTestSkipped('KSeF TEST maintenance window 16:00-18:00 Europe/Warsaw');
        }
    }

    private static function fx(): LiveFixture
    {
        return self::$fx ??= new LiveFixture();
    }

    private static function log(string $line): void
    {
        fwrite(STDERR, '[live] ' . $line . "\n");
    }

    public function testChallengeAndPublicKeys(): void
    {
        $fx = self::fx();
        $client = $fx->certClient;
        $enc = $client->publicKeys()->get(PublicKeyUsage::KsefTokenEncryption);
        $sym = $client->publicKeys()->get(PublicKeyUsage::SymmetricKeyEncryption);
        self::assertSame(44, strlen($enc->publicKeyId));
        self::assertNotSame($enc->publicKeyId, $sym->publicKeyId);
        self::log('public keys OK (ids verified against SPKI hash)');

        $challenge = (new \LuroWeb\KsefFoundry\Auth\AuthApi($client->transport()))->challenge();
        self::assertMatchesRegularExpression('/^\d{8}-CR-[A-F0-9]{10}-[A-F0-9]{10}-[A-F0-9]{2}$/', $challenge->challenge);
        self::assertGreaterThan(0, $challenge->timestampMs);
        self::log('challenge OK');
    }

    #[Depends('testChallengeAndPublicKeys')]
    public function testXadesLoginWithSelfSignedCertificate(): void
    {
        $fx = self::fx();
        self::log(sprintf('synthetic taxpayer NIP=%s (random, checksum-valid)', $fx->nip));
        $fx->provisionAndLogin();
        $token = $fx->certClient->auth()->getAccessToken($fx->certificate);
        self::assertGreaterThan(100, strlen($token->reveal()));
        self::assertStringNotContainsString($token->reveal(), (string) json_encode($token));
        self::log('XAdES (RSA) login OK');
    }

    #[Depends('testXadesLoginWithSelfSignedCertificate')]
    public function testCreateKsefTokenAndLoginWithIt(): void
    {
        $fx = self::fx();
        $tokens = $fx->certClient->tokens();
        $created = $tokens->create([TokenPermission::InvoiceRead, TokenPermission::InvoiceWrite], 'ksef-foundry php live token');
        self::$tokenRef = $created->referenceNumber;
        $info = $tokens->awaitActive($created->referenceNumber, 60);
        self::assertSame(TokenStatus::Active, $info->status);
        self::log('POST /tokens OK, status Active');

        $creds = new KsefTokenCredentials($created->token, $fx->certificate->context());
        self::$tokenClient = KsefClient::create($creds, $fx->environment);
        $fx->loginEventually(self::$tokenClient, 60);
        $jwt = self::$tokenClient->auth()->getAccessToken($creds);
        self::assertGreaterThan(100, strlen($jwt->reveal()));
        self::log('KSeF token login OK (RSA-OAEP-SHA256 verified live)');
    }

    #[Depends('testCreateKsefTokenAndLoginWithIt')]
    public function testRefreshAndTenantIsolation(): void
    {
        $fx = self::fx();
        $client = self::$tokenClient ?? self::fail('token client missing');
        $before = $client->auth()->getAccessToken($client->credentials());
        $after = $client->auth()->getAccessToken($client->credentials(), true); // forced: uses the refresh token
        self::assertGreaterThan(100, strlen($after->reveal()));
        self::log('POST /auth/token/refresh OK (same=' . ($before->reveal() === $after->reveal() ? 'yes' : 'no') . ')');
        self::assertNotSame(
            $client->auth()->tenantKey($client->credentials()),
            $fx->certClient->auth()->tenantKey($fx->certificate),
        );
    }

    #[Depends('testCreateKsefTokenAndLoginWithIt')]
    public function testQueriesLimitsAndSessions(): void
    {
        $client = self::$tokenClient ?? self::fail('token client missing');

        $ctx = $client->limits()->context();
        self::assertGreaterThan(0, $ctx->onlineSession->maxInvoices);
        $rates = $client->limits()->rateLimits();
        self::assertNotNull($rates->group('invoiceSend'));
        $client->limits()->subject();
        self::log(sprintf('limits OK (online maxInvoices=%d)', $ctx->onlineSession->maxInvoices));

        $sessions = iterator_to_array($client->authSessions()->iterate(10), false);
        self::assertNotEmpty($sessions);
        self::assertTrue(self::anyCurrent($sessions));
        self::log('GET /auth/sessions OK (' . count($sessions) . ')');

        $tokens = iterator_to_array($client->tokens()->iterate([], 10), false);
        self::assertNotEmpty($tokens);
        self::log('GET /tokens OK (' . count($tokens) . ')');

        $filters = new InvoiceQueryFilters(
            SubjectType::Subject1,
            DateType::Issue,
            new \DateTimeImmutable('-30 days'),
            new \DateTimeImmutable('now'),
        );
        $page = $client->query()->queryMetadata($filters, 0, 10);
        self::assertSame([], $page->invoices);
        self::assertFalse($page->hasMore);
        self::assertSame([], iterator_to_array($client->query()->iterate($filters->withRange(new \DateTimeImmutable('-250 days'), null)), false));
        self::log('POST /invoices/query/metadata OK (empty, incl. 250-day split)');
    }

    #[Depends('testCreateKsefTokenAndLoginWithIt')]
    public function testOnlineSessionOpenStatusClose(): void
    {
        $client = self::$tokenClient ?? self::fail('token client missing');
        $sessions = $client->sessions();
        $session = $sessions->openOnline();
        self::assertSame(36, strlen($session->referenceNumber));
        self::log('POST /sessions/online OK (RSA-OAEP session key accepted)');
        $status = $sessions->getStatus($session->referenceNumber);
        self::assertSame(100, $status->status->code);
        self::assertSame([], iterator_to_array($sessions->iterateSessionInvoices($session->referenceNumber, 10), false));
        $sessions->close($session);
        $closed = $sessions->getStatus($session->referenceNumber);
        self::log(sprintf('close OK, session status after close = %d (%s)', $closed->status->code, $closed->status->description));
        self::assertGreaterThanOrEqual(100, $closed->status->code);
    }

    #[Depends('testXadesLoginWithSelfSignedCertificate')]
    public function testXadesLoginWithEcdsaCertificate(): void
    {
        $ecFx = new LiveFixture(true);
        $ecFx->provisionAndLogin();
        self::assertGreaterThan(100, strlen($ecFx->certClient->auth()->getAccessToken($ecFx->certificate)->reveal()));
        self::log('XAdES (ECDSA P-256, R||S) login OK');
    }

    /**
     * Sends Fa3InvoiceBuilder::sample() for the synthetic seller NIP with a random invoice number.
     */
    #[Depends('testQueriesLimitsAndSessions')]
    public function testSendInvoiceEndToEnd(): void
    {
        $xml = $this->fixtureInvoice();
        $client = self::$tokenClient ?? self::fail('token client missing');
        $fx = self::fx();
        $number = self::$invoiceNumber;

        $result = $client->sender()->submit($xml);
        self::assertSame(InvoiceStatus::ACCEPTED, $result->status->status->code);
        self::assertNotSame('', $result->ksefNumber);
        self::$ksefNumber = $result->ksefNumber;
        self::$sessionRef = $result->sessionReferenceNumber;
        self::assertStringStartsWith($fx->nip, $result->ksefNumber);
        self::log(sprintf('invoice ACCEPTED (status 200), ksefNumber=%s, session closed=%s', $result->ksefNumber, $result->sessionClosed ? 'yes' : 'no'));

        // reconcile finds it by hash
        $found = $client->sessions()->reconcile($result->sessionReferenceNumber, $result->invoiceHash);
        self::assertNotNull($found);
        self::assertSame($result->ksefNumber, $found->ksefNumber);

        // download by KSeF number: bytes and hash header match what we sent
        $doc = $client->invoices()->download($result->ksefNumber);
        self::assertTrue($doc->hashVerified, 'x-ms-meta-hash must match the downloaded bytes');
        self::assertSame($result->invoiceHash, $doc->sha256Base64);
        self::assertStringContainsString($number, $doc->xml);
        self::log('GET /invoices/ksef/{n} OK, hash verified, bytes identical=' . ($doc->xml === $xml ? 'yes' : 'no'));

        // UPO of the invoice
        $upo = $client->upo()->invoiceUpo($result->sessionReferenceNumber, $result->invoiceReferenceNumber);
        self::assertStringContainsString($result->ksefNumber, $upo->xml);
        self::log('invoice UPO OK');
    }

    #[Depends('testSendInvoiceEndToEnd')]
    public function testDuplicateIsRejectedWith440AndMetadataQueryFindsInvoice(): void
    {
        $client = self::$tokenClient ?? self::fail('token client missing');
        $xml = $this->fixtureInvoice();
        try {
            $client->sender()->submit($xml);
            self::fail('Duplicate must be rejected');
        } catch (InvoiceRejectedException $e) {
            self::assertSame(440, $e->statusCode());
            self::log('duplicate rejected with 440 as documented (InvoiceRejectedException)');
        }

        $filters = new InvoiceQueryFilters(
            SubjectType::Subject1,
            DateType::PermanentStorage,
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('+1 day'),
        );
        $deadline = time() + 90;
        $seen = false;
        while (time() < $deadline && !$seen) {
            foreach ($client->query()->iterate($filters, 10) as $m) {
                if ($m->ksefNumber === self::$ksefNumber) {
                    $seen = true;
                    self::assertSame($client->credentials()->context()->value, $m->sellerNip);
                    self::assertSame('145.68', $m->grossAmount);
                    break;
                }
            }
            if (!$seen) {
                sleep(5);
            }
        }
        self::assertTrue($seen, 'invoice must appear in metadata query within 90s');
        self::log('metadata query found the invoice');
    }

    #[Depends('testSendInvoiceEndToEnd')]
    public function testSessionUpoAfterClose(): void
    {
        $client = self::$tokenClient ?? self::fail('token client missing');
        $sessions = $client->sessions();
        $deadline = time() + 90;
        $status = $sessions->getStatus(self::$sessionRef ?? '');
        while ($status->upoPages === [] && time() < $deadline) {
            sleep(3);
            $status = $sessions->getStatus(self::$sessionRef ?? '');
        }
        self::assertNotEmpty($status->upoPages, 'collective UPO must be available after close');
        $upo = $client->upo()->sessionUpo(self::$sessionRef ?? '', $status->upoPages[0]->referenceNumber);
        self::assertStringContainsString('<', $upo->xml);
        self::log(sprintf('session UPO OK (session status %d)', $status->status->code));
    }

    private function fixtureInvoice(): string
    {
        self::$invoiceNumber = self::$invoiceNumber ?? 'FV/' . date('Y') . '/' . bin2hex(random_bytes(5));

        return Fa3InvoiceBuilder::sampleXml(self::fx()->nip, self::$invoiceNumber);
    }

    #[Depends('testSessionUpoAfterClose')]
    public function testCleanupRevokeTokenAndSession(): void
    {
        $fx = self::fx();
        $fx->certClient->tokens()->revoke(self::$tokenRef ?? self::fail('no token ref'));
        $client = self::$tokenClient ?? self::fail('token client missing');
        $client->authSessions()->revokeCurrent();
        $client->auth()->forget($client->credentials());
        $fx->certClient->testData()->removePerson($fx->nip);
        self::log('cleanup OK (token revoked, auth session revoked, synthetic person removed)');
        $this->addToAssertionCount(1);
    }

    /** @param list<\LuroWeb\KsefFoundry\Service\AuthSession> $sessions */
    private static function anyCurrent(array $sessions): bool
    {
        foreach ($sessions as $s) {
            if ($s->isCurrent) {
                return true;
            }
        }

        return false;
    }
}
