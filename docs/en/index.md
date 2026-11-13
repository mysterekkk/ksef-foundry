# KSeF Foundry documentation

KSeF Foundry is an open-source (MIT) SDK for Poland's National e-Invoice System, **KSeF API 2.0**. The repository contains four packages: a PHP core, a Laravel adapter, a TypeScript core and a NestJS adapter. Repository: <https://github.com/mysterekkk/ksef-foundry>.

> Version 0.1 (pre-release). The API contract was verified against KSeF API **2.9.0** (as of 2026-11-13). Live tests ran only on the **TEST** environment with synthetic data. The DEMO and PRODUCTION environments have never been touched by tests or examples.

## Contents

| Guide | Type (Diátaxis) | Audience |
|---|---|---|
| [Quick start](quick-start.md) | tutorial | first run in PHP, Laravel, TypeScript, NestJS |
| [Environments](environments.md) | reference | choosing TEST / DEMO / PRODUCTION |
| [Authentication](authentication.md) | explanation + reference | KSeF token vs certificate, JWT, tenant isolation |
| [FA(3) invoices](fa3-invoices.md) | how-to + reference | building and validating invoices |
| [Invoice lifecycle](invoice-lifecycle.md) | explanation + how-to | sending, statuses, UPO, duplicates, queries |
| [Error handling](error-handling.md) | reference | exceptions, retries, 429, 403 |
| [Security](security.md) | explanation | safe-use rules |
| [Laravel](laravel.md) | how-to | the Laravel adapter |
| [NestJS](nestjs.md) | how-to | the NestJS adapter |
| [Testing](testing.md) | explanation + reference | test layers and the limits of the evidence |
| [Contract updates](contract-updates.md) | how-to | reacting to changes in the Ministry of Finance OpenAPI |

Polish documentation (primary language): [docs/pl](../pl/index.md). Contributor notes: `docs/dev/` (English).

## Packages

| Package | Registry name | Version | Requirements | Role |
|---|---|---|---|---|
| `packages/php` | `luroweb/ksef-foundry` | 0.1.0 (pre-release) | PHP >= 8.2, `ext-bcmath`, `ext-dom`, `ext-json`, `ext-libxml`, `ext-mbstring`, `ext-openssl` | PHP core SDK |
| `packages/laravel` | `luroweb/laravel-ksef-foundry` | 0.1.0 (pre-release) | PHP >= 8.2, Laravel 12 | provider, token cache, queue jobs, `ksef:check` command |
| `packages/typescript` | `@luroweb/ksef-foundry` | 0.1.0 | Node >= 22, ESM | TypeScript / Node core SDK |
| `packages/nestjs` | `@luroweb/nestjs-ksef-foundry` | 0.1.0 | Node >= 22, NestJS `^11` | module, service, exception filter, health |

**Publication:** the npm packages `@luroweb/ksef-foundry` and `@luroweb/nestjs-ksef-foundry` are **published** on npmjs.com (`npm install @luroweb/ksef-foundry`). The Composer packages (`luroweb/ksef-foundry`, `luroweb/laravel-ksef-foundry`) are prepared but **not yet on Packagist**, so `composer require` from a registry does not work yet. For PHP and Laravel, install from a clone of the repository (VCS/path repository; see the [Quick start](quick-start.md)).

## Feature status

Legend: **Live TEST** = implemented and checked live on the TEST environment (2026-11-13). **No live proof** = implemented, but not checked live (tests only against a local fault-injection server, or contract tests). **Missing** = not implemented.

| Area | Status |
|---|---|
| Certificate login (XAdES-BES), self-signed certificate, RSA 2048 and ECDSA P-256 | Live TEST |
| KSeF token login, JWT refresh | Live TEST |
| Creating and listing KSeF tokens (`/tokens`) | Live TEST |
| Interactive session: open, encrypted FA(3) send, close | Live TEST |
| Invoice status, KSeF number, invoice download, invoice and session UPO | Live TEST |
| Duplicate detection (code 440) | Live TEST |
| Invoice metadata queries, limits, authentication sessions | Live TEST |
| FA(3) builder and validator (standard VAT invoice in PLN) | the sample invoice was accepted by TEST in a live test; scope: [FA(3) invoices](fa3-invoices.md) |
| Real HTTP 429 with `Retry-After`, errors 21470 / 21184, 5xx | No live proof (local fault-injection server) |
| Qualified certificates and KSeF-issued Authentication certificates, `certificateFingerprint` | No live proof |
| Contexts other than NIP (`InternalId`, `NipVatUe`, `PeppolId`) | No live proof |
| DEMO and PRODUCTION environments | No live proof (never used) |
| Session list (`GET /sessions`) | TypeScript: implemented; PHP: **missing** |
| Batch sessions, package exports, permission management, KSeF certificate enrollment, collective identifiers, Peppol, offline modes, QR codes | Missing |
| Correction, advance and settlement invoices, foreign currency, split payment, FA(2), PEF, FA_RR | Missing ([FA(3) scope](fa3-invoices.md)) |

No `@experimental` markers were found in the source code. Anything without live proof is described in this documentation as a limitation.

## Where to go next

- New user: [Quick start](quick-start.md), then [Invoice lifecycle](invoice-lifecycle.md).
- Before going to production: [Environments](environments.md), [Authentication](authentication.md), [Security](security.md).
- Custom integration work: optional, via the author's profile <https://github.com/mysterekkk>. The SDK is a developer tool, not a ready-made ERP.

---

Built and maintained by LuroWeb.
