# KSeF Foundry v0.1.0 (early release)

First public release of KSeF Foundry: open-source SDKs for Poland's National e-Invoice System (KSeF 2.0) for **PHP / Laravel** and **TypeScript / NestJS**, MIT licensed.

This is an independent project, not affiliated with the Polish Ministry of Finance. Other KSeF libraries exist (the Ministry's official C# and Java clients, and independent PHP and TypeScript clients). KSeF Foundry is not the first. Its aim is consistent behaviour across two ecosystems, with framework adapters and an explicit compatibility model against the official API.

## Install status: read this first

The packages are **prepared** for Packagist and npm but **have not been published yet**. `composer require` and `npm install` from the public registries will not work for now.

| Package | Role |
|---|---|
| `luroweb/ksef-foundry` | PHP SDK (PHP 8.2+, PSR-17/18) |
| `luroweb/laravel-ksef-foundry` | Laravel 12 adapter |
| `@luroweb/ksef-foundry` | TypeScript SDK (Node 22+, ESM) |
| `@luroweb/nestjs-ksef-foundry` | NestJS 11 adapter |

For now, install from a clone: a Composer `path` repository for PHP, an npm workspace or `npm pack` tarball for TypeScript. Steps are in [docs/en/quick-start.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/en/quick-start.md).

## What is in v0.1.0

- KSeF token authentication and certificate authentication (XAdES-BES, RSA and ECDSA).
- JWT cache, refresh and re-login, with taxpayer context isolation.
- KSeF token management (`/tokens`).
- Interactive session: AES encryption of the invoice, RSA-OAEP (SHA-256) encryption of the key, FA(3) send up to the final status.
- KSeF number, invoice download with hash check, invoice and session UPO.
- Duplicate detection (440) and reconciliation by invoice hash after an ambiguous outcome. The SDK does not blindly resend.
- Metadata queries with pagination, limits, authentication sessions.
- FA(3) builder for a standard VAT invoice in PLN, with exact decimal arithmetic, and offline XSD validation against the official schema. The TypeScript output is byte-identical to PHP.
- Laravel adapter: service provider, facade, token cache, queue jobs, `ksef:check`. NestJS adapter: `KsefModule.forRoot/forRootAsync`, exception filter, health indicator.
- Secure defaults: TEST is the default environment, TLS cannot be disabled, secrets are kept out of logs and exceptions, XML is parsed offline without entity expansion.

## What is verified, and how

- **Verified live on KSeF TEST** (`api-test.ksef.mf.gov.pl`, contract 2.9.0, checked 2026-11-13): authentication (token, and self-signed certificate with RSA and ECDSA), token management, session open/close, encrypted FA(3) send up to status 200, invoice download with hash match, UPO, duplicate detection (440), metadata queries, limits, sessions.
- **Verified only against a real local HTTP server with fault injection** (not proof of KSeF compatibility): 429 with `Retry-After`, 5xx, timeouts, connection resets, the download retry when an invoice is not yet available beyond 15 seconds, and several response shapes (for example failed invoices, KSeF-number UPO in PHP).
- **Never touched:** DEMO and PRODUCTION. No test or example uses them.
- **Unverified:** qualified certificates, KSeF Authentication certificates issued by KSeF, `certificateFingerprint`, other context types, platforms other than Windows with Node 24.
- UPO signatures are not cryptographically verified. The UPO returned by TEST also does not pass `upo-v4-3.xsd` for two known reasons, documented in `docs/dev/findings-live-test.md`.

Project rule: no mocks for logic that can run for real. Cryptography, XSD and the KSeF TEST API are tested for real. Live tests are opt-in (`KSEF_LIVE_TEST=1`), run against TEST only, and a run that did not happen is reported as `SKIPPED`, never as a pass.

Test results for this release: see the test report linked by the maintainer in the release description (`docs/dev/test-report.md`).

## What is NOT implemented

- Batch sessions, package exports, permission management, KSeF certificate enrollment, offline modes, QR codes, Peppol, collective identifiers.
- `GET /sessions` (session list) in PHP.
- Correcting invoices (KOR), advance invoices (ZAL/ROZ), simplified invoices (UPR), foreign currency, split payment, reverse charge, margin scheme, OSS, attachments, third parties (Podmiot3) and other variants. Full list: [docs/dev/fa3-scope.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/dev/fa3-scope.md). Unsupported constructs are rejected, never silently emitted. XSD validity does not guarantee KSeF acceptance.
- Only FA(3) is supported (no FA(2)).

## Try the examples

All examples use TEST and a synthetic taxpayer (random NIP/PESEL, self-signed certificate). No secrets or real data are needed.

```bash
# PHP
cd examples/php
composer install
composer quickstart      # limits and a metadata query
composer build-validate  # build and validate FA(3), offline
composer send            # send a sample invoice to TEST
composer errors          # error handling (local demo server)

# TypeScript (from the repository root)
npm install
npm run build -w @luroweb/ksef-foundry
npm run 03:send -w @luroweb/ksef-foundry-example-typescript
```

The TEST environment is shared and can lag: permissions created through `/testdata/person` may take a few seconds to apply, and a download right after status 200 may need a short retry. The SDK handles both, but expect the occasional slow run.

## Known limitations

- The legal status of KSeF tokens after 31 Dec 2026 is unsettled (the Ministry announces they stay; the regulation text still states that date). For new production integrations a certificate is the safer path, but certificates other than self-signed TEST ones are unverified here. See [docs/api-compatibility.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/api-compatibility.md).
- Version 0.x: the public API may change.
- Contract version checked: KSeF API 2.9.0. A weekly workflow detects drift and opens an issue. It never updates the SDK automatically.

## Security and contributing

- Report vulnerabilities as described in [SECURITY.md](https://github.com/mysterekkk/ksef-foundry/blob/main/SECURITY.md). Do not open public issues for vulnerabilities.
- Contributions: [CONTRIBUTING.md](https://github.com/mysterekkk/ksef-foundry/blob/main/CONTRIBUTING.md).
- Questions and bug reports: [GitHub Issues](https://github.com/mysterekkk/ksef-foundry/issues).

Built and maintained by LuroWeb (author: [mysterekkk](https://github.com/mysterekkk)). The library is free (MIT); optional commercial integration work is described in [docs/commercial-integrations.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/commercial-integrations.md).
