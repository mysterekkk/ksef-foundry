<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Contract;

use LuroWeb\KsefFoundry\Auth\AuthInit;
use LuroWeb\KsefFoundry\Auth\AuthStatus;
use LuroWeb\KsefFoundry\Context\ContextIdentifierType;
use LuroWeb\KsefFoundry\Crypto\Crypto;
use LuroWeb\KsefFoundry\Crypto\PublicKeyUsage;
use LuroWeb\KsefFoundry\Crypto\SessionKey;
use LuroWeb\KsefFoundry\Query\DateType;
use LuroWeb\KsefFoundry\Query\InvoiceMetadataPage;
use LuroWeb\KsefFoundry\Query\InvoiceQueryFilters;
use LuroWeb\KsefFoundry\Query\SortOrder;
use LuroWeb\KsefFoundry\Query\SubjectType;
use LuroWeb\KsefFoundry\Service\AuthSessionPage;
use LuroWeb\KsefFoundry\Service\ContextLimits;
use LuroWeb\KsefFoundry\Service\KsefTokenInfo;
use LuroWeb\KsefFoundry\Service\KsefTokenPage;
use LuroWeb\KsefFoundry\Service\RateLimits;
use LuroWeb\KsefFoundry\Service\SubjectLimits;
use LuroWeb\KsefFoundry\Service\TokenPermission;
use LuroWeb\KsefFoundry\Service\TokenStatus;
use LuroWeb\KsefFoundry\Session\FormCode;
use LuroWeb\KsefFoundry\Session\InvoicePage;
use LuroWeb\KsefFoundry\Session\InvoiceStatus;
use LuroWeb\KsefFoundry\Session\SessionStatus;
use LuroWeb\KsefFoundry\Tests\Support\KsefLikeKeys;
use LuroWeb\KsefFoundry\Tests\Support\OpenApi;
use LuroWeb\KsefFoundry\Crypto\PublicKeyProvider;
use PHPUnit\Framework\TestCase;

/**
 * Official OpenAPI examples (schemas/openapi/test.json) decoded into our DTOs, and our request bodies validated
 * against the official request schemas and enums.
 */
final class OpenApiContractTest extends TestCase
{
    private OpenApi $api;

    protected function setUp(): void
    {
        $api = OpenApi::load();
        if ($api === null) {
            self::markTestSkipped('schemas/openapi/test.json not available outside the monorepo');
        }
        $this->api = $api;
    }

    /** @return array<mixed> */
    private function ex(string $m, string $p, string $s = '200'): array
    {
        return $this->api->responseExample($m, $p, $s) ?? self::fail("no example for $m $p $s");
    }

    public function testResponseExamplesAreValidAgainstTheirOwnSchemas(): void
    {
        // Sanity check of the mini validator itself: official examples must satisfy official schemas.
        $cases = [
            ['post', '/auth/token/redeem', '200', 'AuthenticationTokensResponse'],
            ['post', '/auth/ksef-token', '202', 'AuthenticationInitResponse'],
            ['get', '/sessions/{referenceNumber}', '200', 'SessionStatusResponse'],
            ['get', '/tokens', '200', 'QueryTokensResponse'],
            ['post', '/invoices/query/metadata', '200', 'QueryInvoicesMetadataResponse'],
            ['get', '/limits/context', '200', 'EffectiveContextLimits'],
        ];
        foreach ($cases as [$m, $p, $s, $schema]) {
            self::assertSame([], $this->api->validate($this->ex($m, $p, $s), $this->api->schema($schema)), "$m $p");
        }
    }

    public function testAuthResponses(): void
    {
        $init = AuthInit::fromArray($this->ex('post', '/auth/ksef-token', '202'));
        self::assertSame(36, strlen($init->referenceNumber));
        self::assertNotSame('', $init->authenticationToken->reveal());
        self::assertSame('[REDACTED]', (string) $init->authenticationToken);

        $examples = $this->api->responseExamples('get', '/auth/{referenceNumber}', '200');
        self::assertNotEmpty($examples);
        $seen = [];
        foreach ($examples as $name => $ex) {
            $st = AuthStatus::fromArray($ex);
            self::assertNotSame('', $st->description, $name);
            self::assertContains($st->authenticationMethod, $this->api->enumValues('AuthenticationMethod'));
            self::assertSame($st->code === 100, $st->isInProgress() && $st->code === 100);
            self::assertSame($st->code >= 400, !$st->isInProgress() && !$st->isSuccess());
            $seen[$st->code] = true;
        }
        self::assertArrayHasKey(100, $seen);
        self::assertArrayHasKey(200, $seen);
        self::assertArrayHasKey(400, $seen);

        $tokens = $this->ex('post', '/auth/token/redeem');
        self::assertArrayHasKey('accessToken', $tokens);
        self::assertArrayHasKey('validUntil', $tokens['accessToken']);
    }

    public function testSessionAndInvoiceResponses(): void
    {
        $s = SessionStatus::fromArray($this->ex('get', '/sessions/{referenceNumber}'));
        self::assertGreaterThan(0, $s->status->code);
        self::assertNotNull($s->invoiceCount);

        $one = InvoiceStatus::fromArray($this->ex('get', '/sessions/{referenceNumber}/invoices/{invoiceReferenceNumber}'));
        self::assertSame(36, strlen($one->referenceNumber));
        // The official example for this endpoint omits invoiceHash although the schema marks it required.
        self::assertSame(440, $one->status->code);
        self::assertSame('20250626-SO-2F14610000-242991F8C9-B4', $one->status->extensions['originalSessionReferenceNumber'] ?? null);

        $page = InvoicePage::fromArray($this->ex('get', '/sessions/{referenceNumber}/invoices'));
        self::assertNotEmpty($page->invoices);
        $failed = InvoicePage::fromArray($this->ex('get', '/sessions/{referenceNumber}/invoices/failed'));
        self::assertNotEmpty($failed->invoices);
        foreach ([...$page->invoices, ...$failed->invoices] as $inv) {
            self::assertInstanceOf(InvoiceStatus::class, $inv);
            self::assertSame($inv->status->code === 200, $inv->isAccepted());
            self::assertSame($inv->status->code >= 400, $inv->isRejected());
        }
    }

    public function testQueryTokensLimitsAuthSessions(): void
    {
        $meta = InvoiceMetadataPage::fromArray($this->ex('post', '/invoices/query/metadata'));
        self::assertNotEmpty($meta->invoices);
        foreach ($meta->invoices as $m) {
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $m->grossAmount, 'amounts are exact 2-decimal strings');
            self::assertSame(10, strlen($m->sellerNip));
            self::assertInstanceOf(\DateTimeImmutable::class, $m->permanentStorageDate);
            self::assertSame('FA', $m->formCode->value);
            self::assertEquals($m->invoicingDate, $m->dateFor(DateType::Invoicing));
        }

        $tokens = KsefTokenPage::fromArray($this->ex('get', '/tokens'));
        self::assertNotEmpty($tokens->tokens);
        self::assertInstanceOf(TokenStatus::class, $tokens->tokens[0]->status);
        $one = KsefTokenInfo::fromArray($this->ex('get', '/tokens/{referenceNumber}'));
        self::assertNotSame('', $one->referenceNumber);

        $ctx = ContextLimits::fromArray($this->ex('get', '/limits/context'));
        self::assertGreaterThan(0, $ctx->onlineSession->maxInvoices);
        $rates = RateLimits::fromArray($this->ex('get', '/rate-limits'));
        self::assertNotNull($rates->group('invoiceSend'));
        SubjectLimits::fromArray($this->ex('get', '/limits/subject'));

        $auth = AuthSessionPage::fromArray($this->ex('get', '/auth/sessions'));
        self::assertNotEmpty($auth->items);
    }

    public function testPublicKeyExampleShapeIsUnderstood(): void
    {
        $ex = $this->api->responseExample('get', '/security/public-key-certificates', '200');
        self::assertNotNull($ex);
        foreach ($ex as $entry) {
            self::assertIsArray($entry);
            self::assertContains($entry['usage'][0], $this->api->enumValues('PublicKeyCertificateUsage'));
            self::assertSame(44, strlen($entry['publicKeyId']));
        }
        self::assertSame(
            $this->api->enumValues('PublicKeyCertificateUsage'),
            array_map(static fn (PublicKeyUsage $u): string => $u->value, PublicKeyUsage::cases()),
        );
    }

    public function testEnumsMatchTheOpenApi(): void
    {
        self::assertEqualsCanonicalizing($this->api->enumValues('AuthenticationContextIdentifierType'), array_map(static fn ($c) => $c->value, ContextIdentifierType::cases()));
        self::assertEqualsCanonicalizing($this->api->enumValues('TokenPermissionType'), array_map(static fn ($c) => $c->value, TokenPermission::cases()));
        self::assertEqualsCanonicalizing($this->api->enumValues('AuthenticationTokenStatus'), array_map(static fn ($c) => $c->value, TokenStatus::cases()));
        self::assertEqualsCanonicalizing($this->api->enumValues('InvoiceQuerySubjectType'), array_map(static fn ($c) => $c->value, SubjectType::cases()));
        self::assertEqualsCanonicalizing($this->api->enumValues('InvoiceQueryDateType'), array_map(static fn ($c) => $c->value, DateType::cases()));
        self::assertEqualsCanonicalizing($this->api->enumValues('SortOrder'), array_map(static fn ($c) => $c->value, SortOrder::cases()));
    }

    public function testOurRequestBodiesSatisfyOfficialRequestSchemas(): void
    {
        $keys = new KsefLikeKeys();
        $pub = PublicKeyProvider::parseEntry(json_decode($keys->listJson(['SymmetricKeyEncryption']), true)[0]);
        $crypto = new Crypto();
        $sk = SessionKey::generate();

        $open = [
            'formCode' => FormCode::fa3()->toArray(),
            'encryption' => [
                'encryptedSymmetricKey' => $crypto->encryptSessionKey($sk, $pub),
                'initializationVector' => base64_encode($sk->iv()),
                'publicKeyId' => $pub->publicKeyId,
            ],
        ];
        self::assertSame([], $this->api->validate($open, $this->api->schema('OpenOnlineSessionRequest')));
        self::assertSame([], $this->api->validate($this->api->requestExample('post', '/sessions/online'), $this->api->schema('OpenOnlineSessionRequest')), 'official example');

        $send = $crypto->encryptInvoice('<Faktura/>', $sk)->toArray();
        self::assertSame([], $this->api->validate($send, $this->api->schema('SendInvoiceRequest')));

        $tokenReq = ['permissions' => ['InvoiceRead', 'InvoiceWrite'], 'description' => 'ksef-foundry token'];
        self::assertSame([], $this->api->validate($tokenReq, $this->api->schema('GenerateTokenRequest')));

        $init = [
            'challenge' => '20250604-CR-461EA5B000-537A6BA15D-D7',
            'contextIdentifier' => ['type' => 'Nip', 'value' => '7980332920'],
            'encryptedToken' => $crypto->encryptKsefToken('T', 1, $pub),
            'publicKeyId' => $pub->publicKeyId,
        ];
        self::assertSame([], $this->api->validate($init, $this->api->schema('InitTokenAuthenticationRequest')));

        $filters = new InvoiceQueryFilters(
            SubjectType::Subject2,
            DateType::PermanentStorage,
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2026-02-01T00:00:00+01:00'),
            true,
            invoiceTypes: ['Vat', 'Kor'],
            currencyCodes: ['PLN'],
            sellerNip: '7980332920',
        );
        $body = $filters->toArray();
        self::assertSame([], $this->api->validate($body, $this->api->schema('InvoiceQueryFilters')));
        self::assertSame('2025-12-31T23:00:00+00:00', $body['dateRange']['from'], 'dates are converted to UTC explicitly');
        self::assertSame([], $this->api->validate($this->api->requestExample('post', '/invoices/query/metadata'), $this->api->schema('InvoiceQueryFilters')), 'official example');
    }
}
