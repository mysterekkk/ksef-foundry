<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Unit;

use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Context\ContextIdentifierType;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Http\HttpTransport;
use LuroWeb\KsefFoundry\Http\Psr7\Factory;
use LuroWeb\KsefFoundry\Http\CurlHttpClient;
use LuroWeb\KsefFoundry\Service\TestData;
use LuroWeb\KsefFoundry\Session\FormCode;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentAndContextTest extends TestCase
{
    public function testBaseUrls(): void
    {
        self::assertSame('https://api-test.ksef.mf.gov.pl/v2', Environment::test()->baseUrl);
        self::assertSame('https://api-demo.ksef.mf.gov.pl/v2', Environment::demo()->baseUrl);
        self::assertSame('https://api.ksef.mf.gov.pl/v2', Environment::production()->baseUrl);
        self::assertTrue(Environment::test()->isTest());
        self::assertTrue(Environment::production()->isProduction());
    }

    /** @return array<string, array{string, bool}> */
    public static function customUrls(): array
    {
        return [
            'https any host' => ['https://example.org/v2', true],
            'http loopback ipv4' => ['http://127.0.0.1:8080/v2', true],
            'http localhost' => ['http://localhost/v2', true],
            'http ipv6 loopback' => ['http://[::1]:9000/v2', true],
            'http remote' => ['http://example.org/v2', false],
            'ftp' => ['ftp://127.0.0.1/', false],
            'credentials' => ['https://user:pw@example.org/v2', false],
            'query' => ['https://example.org/v2?x=1', false],
            'relative' => ['/v2', false],
        ];
    }

    #[DataProvider('customUrls')]
    public function testCustomEnvironmentRules(string $url, bool $ok): void
    {
        if (!$ok) {
            $this->expectException(ConfigurationException::class);
        }
        $env = Environment::custom($url);
        self::assertSame('CUSTOM', $env->name);
        self::assertFalse($env->isTest());
    }

    public function testTestDataRefusesEveryNonTestEnvironment(): void
    {
        $f = new Factory();
        foreach ([Environment::demo(), Environment::production(), Environment::custom('http://127.0.0.1:1/v2')] as $env) {
            try {
                new TestData(new HttpTransport($env, new CurlHttpClient(), $f, $f));
                self::fail('TestData must refuse ' . $env->name);
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        new TestData(new HttpTransport(Environment::test(), new CurlHttpClient(), $f, $f));
    }

    public function testContextIdentifiers(): void
    {
        self::assertSame(['type' => 'Nip', 'value' => '7980332920'], ContextIdentifier::nip('7980332920')->toArray());
        self::assertSame('InternalId', ContextIdentifier::internalId('7980332920-12345')->type->value);
        self::assertSame('NipVatUe', ContextIdentifier::nipVatUe('7980332920-DE123456789')->type->value);
        self::assertSame(['Nip', 'InternalId', 'NipVatUe', 'PeppolId'], array_map(static fn ($c) => $c->value, ContextIdentifierType::cases()));
        self::assertTrue(ContextIdentifier::nip('7980332920')->equals(ContextIdentifier::nip('7980332920')));
        self::assertFalse(ContextIdentifier::nip('7980332920')->equals(ContextIdentifier::nip('5260250274')));
        $this->expectException(ConfigurationException::class);
        ContextIdentifier::nip('0123456789');
    }

    public function testFormCodeFa3(): void
    {
        self::assertSame(['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'], FormCode::fa3()->toArray());
    }

    public function testSyntheticIdentitiesAreChecksumValid(): void
    {
        self::assertTrue(SyntheticIdentity::isValidNip('7980332920'), 'official example NIP');
        self::assertTrue(SyntheticIdentity::isValidPesel('30112206276'), 'official example PESEL');
        for ($i = 0; $i < 200; ++$i) {
            self::assertTrue(SyntheticIdentity::isValidNip(SyntheticIdentity::nip()));
            self::assertTrue(SyntheticIdentity::isValidPesel(SyntheticIdentity::pesel()));
        }
    }
}
