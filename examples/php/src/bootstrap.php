<?php

declare(strict_types=1);

use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\Exception\AuthorizationException;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Support\SensitiveString;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Returns [KsefClient, nip, cleanup] for the TEST environment.
 *  - With KSEF_TOKEN + KSEF_CONTEXT_NIP: uses your own KSeF token (a TEST token!).
 *  - Without: creates a SYNTHETIC taxpayer (random NIP/PESEL, self-signed certificate) in TEST.
 *    No real data and no secrets are involved. The environment is always TEST.
 *
 * @return array{0: KsefClient, 1: string, 2: \Closure(): void}
 */
function connect(): array
{
    $token = getenv('KSEF_TOKEN');
    $contextNip = getenv('KSEF_CONTEXT_NIP');
    if (is_string($token) && $token !== '' && is_string($contextNip) && $contextNip !== '') {
        $client = KsefClient::create(
            new KsefTokenCredentials(new SensitiveString($token), ContextIdentifier::nip($contextNip)),
            Environment::test(),
        );

        return [$client, $contextNip, static function (): void {
        }];
    }

    $nip = SyntheticIdentity::nip();
    $pesel = SyntheticIdentity::pesel();
    $cert = (new TestCertificateFactory())->personWithPesel($pesel);
    $client = KsefClient::create(
        new CertificateCredentials($cert->certificatePem, $cert->privateKeyPem, ContextIdentifier::nip($nip)),
        Environment::test(),
    );
    $client->testData()->createPerson($nip, $pesel, 'ksef-foundry example taxpayer');

    // /testdata/person takes a few seconds to take effect: the login may answer auth status 415 until then.
    for ($attempt = 1;; $attempt++) {
        try {
            $client->auth()->getAccessToken($client->credentials());
            break;
        } catch (AuthorizationException $e) {
            if ($attempt >= 8) {
                throw $e;
            }
            sleep(2);
        }
    }

    return [$client, $nip, static function () use ($client, $nip): void {
        try {
            $client->testData()->removePerson($nip);
        } catch (\Throwable) {
            // best effort clean-up of synthetic data
        }
    }];
}
