# luroweb/ksef-foundry (PHP core SDK)

Client for Poland's National e-Invoice System **KSeF API 2.0**. PHP >= 8.2, MIT, by LuroWeb.
Status: **v0.1 (pre-release)**. Proven against the public KSeF **TEST** environment (see "Verified scope").

- Authentication with a **KSeF token** or a **certificate (XAdES-BES)**, with JWT cache, refresh, re-login and single-flight.
- Encrypted interactive sessions (RSA-OAEP-SHA256 + AES-256-CBC), duplicate-safe sending (`UnknownOutcomeException` + `reconcile`).
- Invoice download, metadata queries with lazy pagination, UPO, limits, auth sessions, KSeF token management, TEST data.
- Secrets never reach logs or exceptions; TLS verification is always on; redirects are never followed; default environment is TEST.

```
composer require luroweb/ksef-foundry
```

The built-in HTTP client needs `ext-curl`; or inject any PSR-18 client via `KsefClient::builder()->httpClient(...)` (it must not follow redirects and must
have TLS verification and timeouts configured). `->discoverHttpClient()` finds Guzzle and wraps it in a hardened adapter
(no redirects, deadline-bound timeouts, size cap); any other discovered client is rejected at `build()`.

### Security notes

Secret-bearing parameters are marked `#[\SensitiveParameter]`, so stack traces keep no tokens, keys, plaintext or invoice
XML even with `zend.exception_ignore_args=0` (the Docker default). In your own code wrap secrets in `SensitiveString`
immediately and mark your parameters with `#[\SensitiveParameter]`. Exceptions never chain raw HTTP client exceptions or PSR-7
requests. A server-rejected login/token is cached negatively for `AuthOptions::$rejectionBackoffSeconds` (30 s) so a bad
credential cannot make every call hit `/auth/*`.

## Quick start (TEST environment, no real data)

> **WARNING - TEST ONLY.** `TestCertificateFactory` (self-signed certificates) and `SyntheticIdentity` (random NIP/PESEL)
> exist for the KSeF TEST environment and for tests. They must never appear in production code paths, and real
> NIP/PESEL/company data must never be used in tests. DEMO and PRODUCTION reject these certificates.

```php
use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Service\TokenPermission;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;

// 1. A synthetic taxpayer on TEST (random checksum-valid NIP/PESEL, self-signed certificate: TEST ONLY).
$nip = SyntheticIdentity::nip();
$pesel = SyntheticIdentity::pesel();
$cert = (new TestCertificateFactory())->personWithPesel($pesel);
$context = ContextIdentifier::nip($nip);

// Certificate credentials: XAdES login. With a real certificate use
// new CertificateCredentials(file_get_contents('cert.pem'), new SensitiveString($keyPem), $context)
$certClient = KsefClient::create(new CertificateCredentials($cert->certificatePem, $cert->privateKeyPem, $context));
$certClient->testData()->createPerson($nip, $pesel, 'my first KSeF test');
// Permissions can take a few seconds to become effective (until then login throws `AuthorizationException` with `authStatusCode() === 415`; retry a few seconds later).

// 2. Create a long-lived KSeF token (needs the certificate login above) and use token credentials from now on.
$created = $certClient->tokens()->create([TokenPermission::InvoiceRead, TokenPermission::InvoiceWrite], 'my integration');
$certClient->tokens()->awaitActive($created->referenceNumber);
$ksef = KsefClient::create(new KsefTokenCredentials($created->token, $context)); // $created->token is shown only once

// 3. Build, validate and send an FA(3) invoice; submit() returns only once KSeF ACCEPTED it (status 200).
$xml = Fa3InvoiceBuilder::sampleXml($nip, 'FV/2026/' . bin2hex(random_bytes(4)));
$result = $ksef->sender()->submit($xml);
echo $result->ksefNumber;

// 4. Download it back and fetch the official receipt (UPO).
$doc = $ksef->invoices()->download($result->ksefNumber);       // $doc->xml, $doc->hashVerified
$upo = $ksef->upo()->invoiceUpo($result->sessionReferenceNumber, $result->invoiceReferenceNumber);
```

Production must be chosen explicitly: `KsefClient::create($credentials, Environment::production())`.

## FA(3) invoices

`LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder` builds schema-valid standard VAT invoices (PLN, rates
23/22/8/7/5/0 KR/0 EX/0 WDT/zw/np I/np II) with exact decimal arithmetic. **Not supported:** KOR/ZAL/ROZ/UPR, foreign
currency, split payment, reverse charge, margin, OSS, taxi rates, Podmiot3, attachments, FP/TP and the other items listed
in `docs/dev/fa3-scope.md`. XSD validity does not guarantee KSeF acceptance.

```php
use LuroWeb\KsefFoundry\Invoice\Fa3Address;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;

$xml = Fa3InvoiceBuilder::create()
    ->seller('7980332920', 'Seller Sp. z o.o.', new Fa3Address('ul. Prosta 1', '00-001 Warszawa'))
    ->buyerNip('5260250274', 'Buyer S.A.', new Fa3Address('ul. Dluga 5', '80-001 Gdansk'))
    ->number('FV/2026/001')
    ->issueDate('2026-01-15')
    ->createdAt('2026-01-15T10:00:00Z')
    ->systemInfo('My ERP')
    ->addLine('Usluga', 1, '100.00', '23', 'szt.')
    ->build();   // throws InvoiceBuilderException / InvoiceValidationException instead of emitting invalid XML
```

## Validation

```php
use LuroWeb\KsefFoundry\Invoice\XmlValidator;

$result = (new XmlValidator())->validateFa3($xml);   // offline, official FA(3) XSD, no network, no entity expansion
if (!$result->isValid()) {
    foreach ($result->issues as $i) {
        echo "{$i->code} line {$i->line}: {$i->message}\n";   // codes: size, bom, encoding, utf8, forbidden-char, doctype, ...
    }
}
(new XmlValidator())->assertValid($xml);             // throws InvoiceValidationException (a XmlValidationException)
```

`InvoiceSessionService::sendInvoice()` and `InvoiceSender::submit()` run this validator by default
(`SendOptions(validate: true)`); pass `new SendOptions(validator: new XmlValidator(maxBytes: 1_000_000))` for the 1 MB
plain-invoice limit, or `validate: false` to skip.

### Duplicate safety

`InvoiceSender::submit()` / `InvoiceSessionService::sendInvoice()` never retry a send after an ambiguous failure
(timeout, reset, 5xx): they throw `UnknownOutcomeException` carrying the invoice hash. `submit()` then calls
`reconcile()` (lists the session's invoices and compares hashes) and only resends if you set
`SubmitOptions::resendIfAbsent`. KSeF's own duplicate check (status 440) is the last line of defence.

### Querying

```php
use LuroWeb\KsefFoundry\Query\{DateType, InvoiceQueryFilters, SubjectType};

$filters = new InvoiceQueryFilters(SubjectType::Subject2, DateType::PermanentStorage, new DateTimeImmutable('-300 days'));
foreach ($ksef->query()->iterate($filters) as $meta) {   // lazy; splits into <= 100-day windows, follows hasMore/isTruncated
    echo $meta->ksefNumber, ' ', $meta->grossAmount, "\n";   // amounts are exact 2-decimal strings
}
```

## Verified scope (live TEST, 2026-11-13)

Verified end-to-end against `https://api-test.ksef.mf.gov.pl/v2` by `composer test:live`: public keys, challenge,
XAdES login (self-signed personal certificate, RSA-2048 and ECDSA P-256), `POST /tokens`, KSeF-token login, refresh,
limits, auth sessions, token listing, online session open/close, encrypted FA(3) send with status 200, download by KSeF
number, invoice and session UPO, duplicate rejection (440), metadata query. Details and gaps: `docs/dev/findings-live-test.md`
in the monorepo. **Not verified**: qualified certificates/seals, KSeF Authentication certificates, `certificateFingerprint`,
DEMO and PRODUCTION (never touched by tests), batch sessions and everything listed as out of scope in the contract.

## Development

```
composer install
composer check          # phpcs (PSR-12) + phpstan (src: max, tests: 6) + unit/contract/fault/invoice tests
composer test:live      # opt-in, talks to the public TEST environment (KSEF_LIVE_TEST=1)
```

Tests use real crypto, the real XSDs and real HTTP: fault-injection tests run a real local TCP server
(`tests/Fault/raw_server.php`); there are no mock objects.
