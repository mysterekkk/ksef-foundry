<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\HttpTransport;
use LuroWeb\KsefFoundry\Support\Json;

/**
 * /testdata/* (TEST environment only; refuses every other environment). These endpoints need no token.
 * Use synthetic identifiers only (see Testing\SyntheticIdentity).
 *
 * Note (observed live): permissions created by createPerson() become effective after a short delay;
 * a login attempted immediately may fail with authentication status 415 "no permissions assigned".
 */
final class TestData
{
    public function __construct(private readonly HttpTransport $transport)
    {
        if (!$transport->environment()->isTest()) {
            throw new ConfigurationException('TestData is available on the TEST environment only.');
        }
    }

    /** POST /testdata/person: a natural person who becomes Owner in the context of the given NIP. */
    public function createPerson(string $nip, string $pesel, string $description, bool $isBailiff = false): void
    {
        $this->post('/testdata/person', [
            'nip' => $nip,
            'pesel' => $pesel,
            'description' => $description,
            'isBailiff' => $isBailiff,
        ]);
    }

    /** POST /testdata/person/remove */
    public function removePerson(string $nip): void
    {
        $this->post('/testdata/person/remove', ['nip' => $nip]);
    }

    /**
     * POST /testdata/subject (VAT group / JST / enforcement authority).
     *
     * @param list<array{subjectNip: string, description: string}> $subunits
     */
    public function createSubject(string $subjectNip, string $subjectType, string $description, array $subunits = []): void
    {
        $body = ['subjectNip' => $subjectNip, 'subjectType' => $subjectType, 'description' => $description];
        if ($subunits !== []) {
            $body['subunits'] = $subunits;
        }
        $this->post('/testdata/subject', $body);
    }

    /** POST /testdata/subject/remove */
    public function removeSubject(string $subjectNip): void
    {
        $this->post('/testdata/subject/remove', ['subjectNip' => $subjectNip]);
    }

    /** @param array<mixed> $body */
    private function post(string $path, array $body): void
    {
        $this->transport->send(new HttpRequest('POST', $path, body: Json::encode($body)));
    }
}
