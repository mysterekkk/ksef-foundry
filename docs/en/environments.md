# Environments: TEST, DEMO, PRODUCTION

KSeF provides three environments. The SDK knows all of them, but **uses TEST by default**, and PRODUCTION must be chosen explicitly. This page is a reference, plus the rules to follow.

## Contents

- [URLs and versions](#urls-and-versions)
- [Choosing an environment in code](#choosing-an-environment-in-code)
- [What each environment allows](#what-each-environment-allows)
- [Etiquette for the shared TEST environment](#etiquette-for-the-shared-test-environment)
- [Tokens and certificates are not portable](#tokens-and-certificates-are-not-portable)
- [Custom environment](#custom-environment)
- [Checklist before PRODUCTION](#checklist-before-production)

## URLs and versions

| Environment | Base URL | API version (MF changelog) | Contract verified |
|---|---|---|---|
| TEST (RC) | `https://api-test.ksef.mf.gov.pl/v2` | 2.9.0 | 2026-11-13 |
| DEMO | `https://api-demo.ksef.mf.gov.pl/v2` | 2.9.0 | 2026-11-13 |
| PRODUCTION | `https://api.ksef.mf.gov.pl/v2` | 2.9.0 | 2026-11-13 |

The OpenAPI contract copies live in `schemas/openapi/{test,demo,prod}.json`. The OpenAPI files carry only `v2` in `info.version`; the 2.9.0 number comes from the official MF changelog. TEST has 78 paths (63 shared plus the `/testdata/*` endpoints); DEMO and PRODUCTION have 63 each. We do not assume TEST and PRODUCTION always run the same version: the drift script compares them separately ([Contract updates](contract-updates.md)).

**What was verified in practice:** live tests ran only on **TEST**. DEMO and PRODUCTION have never been used by tests, examples or CI. Compatibility with those two environments follows from the OpenAPI contract, not from live trials.

## Choosing an environment in code

```php
// PHP
use LuroWeb\KsefFoundry\Environment;

KsefClient::create($credentials);                            // TEST (default)
KsefClient::create($credentials, Environment::production()); // PRODUCTION, explicitly
// available: Environment::test(), ::demo(), ::production(), ::custom($baseUrl)
```

```ts
// TypeScript
import { Environment, KsefClient } from '@luroweb/ksef-foundry';

KsefClient.create({ credentials });                                      // TEST (default)
KsefClient.create({ credentials, environment: Environment.PRODUCTION }); // PRODUCTION, explicitly
// available: Environment.TEST, .DEMO, .PRODUCTION, Environment.custom(baseUrl)
```

**Laravel:** `KSEF_ENVIRONMENT` (empty or unset = `test`; `test|demo|production|custom`, case and surrounding spaces are ignored; any other value raises an error). `php artisan ksef:check` warns with "PRODUCTION environment selected." when a connection points at PRODUCTION.

**NestJS:** the `environment` option of `KsefModule.forRoot` (default `Environment.TEST`). The `examples/nestjs` app deliberately rejects anything but `KSEF_ENVIRONMENT=test`.

The `testData` helper (`/testdata/*`) exists for TEST only: in PHP, `$client->testData()` on any other environment throws `ConfigurationException`.

## What each environment allows

Invoice formats allowed by the 2.9.0 contract:

| Environment | FA(2) | FA(3) | FA_PEF(3) | FA_KOR_PEF(3) |
|---|---|---|---|---|
| TEST | yes | yes | yes | yes |
| DEMO | no | yes | yes | yes |
| PRODUCTION | no | yes | yes | yes |

The SDK generates and sends **FA(3)** only (a standard VAT invoice in PLN, see [FA(3) invoices](fa3-invoices.md)). PEF formats and FA(2) are not implemented.

Differences worth knowing:

- **Self-signed certificates** (used by the examples and tests) work on TEST only. DEMO and PRODUCTION need a KSeF-issued Authentication certificate or a qualified one; that was not verified live ([Authentication](authentication.md)).
- **The UPO from TEST does not validate against `upo-v4-3.xsd`**: the receiving entity name reads "Ministerstwo Finansów - środowisko testowe (TE)", and the document contains a `ds:Signature` that the XSD does not declare. The SDK does not cryptographically verify the UPO signature.
- The XSD does not check the NIP checksum, while PRODUCTION does (according to project notes). The builder does not enforce the checksum; it offers `Fa3InvoiceBuilder::isValidNipChecksum()` (PHP) / `Fa3InvoiceBuilder.isValidNipChecksum()` (TypeScript), worth calling before sending to PRODUCTION.

## Etiquette for the shared TEST environment

TEST is shared by all integrators. Behave the way you would want others to behave:

- **Synthetic data.** Random NIP and PESEL (checksum-valid), never real ones. Helpers: `SyntheticIdentity::nip()` / `pesel()` (PHP), `generateTestNip()` / `generateTestPesel()` (TypeScript).
- **Random invoice numbers.** KSeF rejects duplicates (key: seller NIP, invoice type, invoice number; code 440). The examples use `FV/EX/<time>`.
- **Low volume.** Do not run load loops. Open and close sessions sparingly: XML validation happens before a session is opened, so an invalid document does not cost a session.
- **Maintenance window 16:00-18:00 Europe/Warsaw.** The repository's live tests skip themselves then (result SKIPPED). The project treats this as the TEST maintenance window; check current MF announcements, because the schedule may change.
- **Clean up.** The examples call `removePerson` at the end (best effort).
- **Live tests are manual.** The `Live TEST (manual)` workflow runs only on demand ([Testing](testing.md)).

## Tokens and certificates are not portable

A KSeF token and an Authentication certificate are issued **within one environment**. A TEST token does not work on DEMO or PRODUCTION, and vice versa; the same applies to tokens created through `POST /tokens`.

The SDK reinforces this boundary: the JWT cache key (the *tenant key*) contains the environment name and address, the context type and value, the authentication method and a credential fingerprint. Switching environments therefore never reuses someone else's cached JWT. Good practice: keep separate secrets and separate configuration per environment, and never mix them in one unlabeled `.env` file.

## Custom environment

`Environment::custom($baseUrl)` / `Environment.custom(baseUrl)` exists only for fault-injection servers and possible future MF environments. The URL must use `https`; loopback hosts (`127.0.0.1`, `::1`, `localhost`) may use `http`. The URL must not contain credentials (and in PHP no query or fragment either). The environment name is `CUSTOM`. **Do not use it with real data.** In Laravel it requires `base_url` in the connection config.

The SDK calls only URLs derived from the selected environment's base URL and does not follow absolute URLs found in API responses ([Security](security.md)).

## Checklist before PRODUCTION

1. Read [Authentication](authentication.md): for new integrations a KSeF (Authentication) certificate is recommended; the legal status of tokens is unsettled.
2. Remember that login with a qualified or KSeF-issued certificate has not been verified live.
3. Set the environment explicitly and verify it with `php artisan ksef:check` (Laravel) or your own health check ([NestJS](nestjs.md)).
4. Go through [Security](security.md): secrets in a secret manager, a custom `TokenStorage` encrypted at rest, invoice content logging off.
5. Start with a single invoice and watch its status; remember that HTTP 202 on send does not mean acceptance ([Invoice lifecycle](invoice-lifecycle.md)).
6. The FA(3) scope is narrow: check that your invoices fit in it.

---

Built and maintained by LuroWeb.
