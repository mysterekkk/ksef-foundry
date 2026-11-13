# Testing

This guide explains how the SDK is tested, how to run each suite and, just as important, **what the tests do not prove**. The project rule: the live API wins over documentation, and a skipped test is never reported as passed.

## Contents

- [Test layers](#test-layers)
- [The "no mocks" policy and its scope](#the-no-mocks-policy-and-its-scope)
- [How to run the tests](#how-to-run-the-tests)
- [Live tests on TEST](#live-tests-on-test)
- [CI](#ci)
- [What the tests do not prove](#what-the-tests-do-not-prove)

## Test layers

| Layer | What it checks | Network to KSeF |
|---|---|---|
| **unit** | pure logic: decimal arithmetic, secret redaction, URL building, retry maths | no |
| **contract** | official OpenAPI examples against our models (round-trip), real XSD validation, known-answer cryptography | no |
| **fault-injection** | failure scenarios (429 with `Retry-After`, 500, timeout, dropped connection, truncated body, slow body) against a **real local HTTP server** | no |
| **framework** | the adapters: Laravel (Testbench, real container and cache), NestJS (`Test.createTestingModule`, real DI) | no |
| **live TEST** | the full scenario on `api-test.ksef.mf.gov.pl` | yes (TEST only) |

Fault-injection tests are labelled as such and **are never presented as proof of API compatibility**: the real KSeF cannot be asked for a 429 or a 500.

## The "no mocks" policy and its scope

The rule: **no mock objects for behaviour that can be run for real.** Precisely:

- Cryptography (RSA-OAEP, AES, SHA-256, XAdES) is tested with real algorithms; known-answer tests use fixed keys and values with independently computed expectations.
- XSD validation uses the real, official schema.
- Anything that talks to KSeF is proven by live tests on TEST.
- Failures the real API cannot produce on demand are reproduced against a real HTTP server in a local process (PHP: `tests/Fault/raw_server.php`; TypeScript: a local server in the tests; NestJS: `test/support/auth-server.ts`), not against a mocked client.
- Frameworks are tested with real DI and a real cache.

**The scope of this rule is precise.** It does not mean there are no injected dependencies in the code. The SDK exposes injection points for the clock and the `sleep` function (used so tests do not wait in real time); these are stand-ins for time, not mocks of API behaviour. Nor does it mean fault-injection proves compatibility with KSeF: it proves only that **our code** behaves correctly when a server answers in a given way.

## How to run the tests

Requirements: PHP >= 8.2 (with `bcmath`, `dom`, `libxml`, `openssl`, `mbstring`, `curl`), Composer 2, Node >= 22. Commands run from the clone's root.

**PHP (`packages/php`)**

```bash
cd packages/php
composer install
composer test           # unit + contract + fault + invoice (no live)
composer test:unit      # also test:contract, test:fault, test:invoice
composer check          # phpcs (PSR-12) + phpstan (src: max, tests: 6) + tests
```

**Laravel (`packages/laravel`)**

```bash
cd packages/laravel
composer install
composer test           # unit + feature (Testbench) + fault
composer check          # phpcs + phpstan (larastan) + tests
```

The adapter suite was run on Laravel 12.72.0 and 11.57.1: 51 tests (263 assertions) each, green. The example app: `cd examples/laravel && vendor/bin/phpunit --testsuite default`.

**TypeScript (`packages/typescript`)**

```bash
npm ci
npm run build --workspaces --if-present
cd packages/typescript
npm test                # unit + contract + fault + invoice (no live)
npm run test:unit       # also test:contract, test:fault, test:invoice
npm run typecheck && npm run lint
npm run verify:pack     # npm pack, install the tarball, import smoke test
```

**NestJS (`packages/nestjs`)**

```bash
npm test -w @luroweb/nestjs-ksef-foundry          # real Nest DI, HTTP, cryptography, local servers
npm run verify:pack -w @luroweb/nestjs-ksef-foundry
```

**All Node packages at once:** `npm test --workspaces --if-present`.

## Live tests on TEST

Live tests are **optional** and run manually, because TEST is a shared environment used by many integrators.

```bash
KSEF_LIVE_TEST=1 composer --working-dir=packages/php test:live
KSEF_LIVE_TEST=1 npm --workspace @luroweb/ksef-foundry run test:live
KSEF_LIVE_TEST=1 npm --workspace @luroweb/nestjs-ksef-foundry run test:live
composer --working-dir=packages/laravel test:live      # the script sets KSEF_LIVE_TEST=1 itself
```

(In PowerShell set the variable with `$env:KSEF_LIVE_TEST = '1'`.)

How it works:

1. **`KSEF_LIVE_TEST=1` is required.** Without it the result is an explicit `SKIPPED (live TEST disabled)` message; **a skip is not a pass**.
2. **The environment is always TEST**; the test code refuses to work with DEMO and PRODUCTION.
3. **No secrets are needed.** The test creates a synthetic taxpayer by itself: it generates a checksum-valid NIP and PESEL (never real ones), calls `POST /testdata/person`, generates a self-signed personal certificate (`serialNumber = PNOPL-<pesel>`), logs in with XAdES, creates a KSeF token with `POST /tokens` and logs in with the token.
4. Then: queries, an online session, sending a synthetic FA(3) invoice with a random number, polling the status, the UPO, download by KSeF number, a duplicate (440).
5. **Maintenance window 16:00-18:00 Europe/Warsaw:** during it the send scenarios are skipped and reported as `SKIPPED`, not as success. Login after `/testdata/person` is retried on status 415 ([Authentication](authentication.md#status-415-right-after-testdataperson)).
6. Volume is low and identifiers are random, following the etiquette for the shared TEST ([Environments](environments.md#etiquette-for-the-shared-test-environment)).

The manual GitHub Actions workflow `Live TEST (manual)` (`workflow_dispatch`, no secrets) runs the live tests of all four packages.

## CI

The default CI runs the unit, contract, fault-injection and framework layers (no live):

- PHP 8.2, 8.3 and 8.4 (`composer check`),
- Laravel 11 and 12 on PHP 8.2-8.4 in four combinations (`composer check`),
- Node 22 and 24: build, typecheck, lint, tests, `verify:pack` and the `02:build-validate` example,
- an audit of production dependencies (`npm audit`, `composer audit`) and a check that tracked files contain no secrets.

OpenAPI contract drift detection runs separately, once a week ([Contract updates](contract-updates.md)).

## What the tests do not prove

Honestly, as of 2026-11-13:

- **DEMO and PRODUCTION have never been used** by tests, examples or CI.
- **Qualified certificates**, a KSeF-issued Authentication certificate and `certificateFingerprint` were not checked live; only a self-signed personal certificate (RSA 2048 and ECDSA P-256) and the NIP context were.
- **A real 429 with `Retry-After`**, codes 21470 and 21184, and 500/502/503 errors were checked only against a local fault-injection server.
- Retrying a download when unavailability lasts longer than 15 s was checked only locally.
- In PHP there is no live test for the session list (`GET /sessions`, not implemented), and `/sessions/{ref}/invoices/failed` and UPO by KSeF number were checked only for shape against a local server.
- The UPO signature is not cryptographically verified; the UPO from TEST does not validate against `upo-v4-3.xsd` (two known deviations).
- Platforms: TypeScript live tests ran on Windows with Node 24; other systems and Node 22 were not run live. PHP TLS on Windows needed the system certificate store (the built-in client uses it).
- Laravel: locally, `--prefer-lowest`, PHP 8.2 and 8.3, Laravel older than 11.57, Octane and Horizon were not run. The CI matrix covers PHP 8.2-8.4, but its results are not confirmed here. NestJS: Nest 11 only.
- TEST accepting an invoice does not guarantee PRODUCTION will (for example the NIP checksum).
- The contract was verified against KSeF API 2.9.0 (2026-11-13); later changes are caught by the drift script but are not rolled out automatically.
- The substantive correctness of invoices (VAT, JPK, accounting) rests with the issuer; the SDK is not tax advice.

---

Built and maintained by LuroWeb.
