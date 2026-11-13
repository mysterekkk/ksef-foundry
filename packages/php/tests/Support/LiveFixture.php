<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Support;

use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;

/**
 * Provisions a SYNTHETIC taxpayer on the KSeF TEST environment (never real NIP/PESEL) and shares it between
 * the live test steps. TEST only: the environment is hard-coded and asserted.
 */
final class LiveFixture
{
    public readonly Environment $environment;
    public readonly string $nip;
    public readonly string $pesel;
    public readonly CertificateCredentials $certificate;
    public readonly KsefClient $certClient;

    public function __construct(bool $ec = false)
    {
        $this->environment = Environment::test();
        if ($this->environment->baseUrl !== 'https://api-test.ksef.mf.gov.pl/v2') {
            throw new \LogicException('Live tests run against TEST only.');
        }
        $this->nip = SyntheticIdentity::nip();
        $this->pesel = SyntheticIdentity::pesel();
        $cert = (new TestCertificateFactory())->personWithPesel($this->pesel, 'Jan', 'Syntetyczny', $ec);
        $this->certificate = new CertificateCredentials(
            $cert->certificatePem,
            $cert->privateKeyPem,
            ContextIdentifier::nip($this->nip),
        );
        $this->certClient = KsefClient::create($this->certificate, $this->environment);
    }

    /** POST /testdata/person, then log in retrying while KSeF has not yet applied the permissions (status 415). */
    public function provisionAndLogin(): void
    {
        $this->certClient->testData()->createPerson($this->nip, $this->pesel, 'ksef-foundry php sdk live test (synthetic)');
        $this->loginEventually($this->certClient);
    }

    public function loginEventually(KsefClient $client, int $maxSeconds = 90): void
    {
        $deadline = time() + $maxSeconds;
        while (true) {
            try {
                $client->auth()->getAccessToken($client->credentials());

                return;
            } catch (AuthorizationException $e) {
                // auth status 415 = no permissions assigned yet (TEST applies them with a delay)
                if ($e->authStatusCode() !== 415 || time() >= $deadline) {
                    throw $e;
                }
                sleep(3);
            }
        }
    }

    public static function enabled(): bool
    {
        return getenv('KSEF_LIVE_TEST') === '1';
    }

    /** KSeF TEST maintenance window 16:00-18:00 Europe/Warsaw. */
    public static function inMaintenanceWindow(): bool
    {
        $h = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('G');

        return $h >= 16 && $h < 18;
    }
}
