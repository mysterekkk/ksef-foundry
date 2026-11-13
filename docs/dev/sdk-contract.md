# KSeF Foundry — SDK contract (binding for all four packages)

This document is the single source of truth for how the PHP and TypeScript SDKs
(and their Laravel / NestJS adapters) must behave. PHP and TypeScript must stay
**behaviourally identical** and use the **same names** (PHP `camelCase` methods,
TS `camelCase` methods; classes `PascalCase` in both).

Target contract: **KSeF API 2.0**, OpenAPI in `schemas/openapi/{test,demo,prod}.json`
(prod/demo = 2.9.0 as of 2026-11-13, test is a superset incl. `/testdata/*`).
Never guess a path, field, enum or header: read the OpenAPI and the official guide
(`ksef-api` repo, folder `docs`). KSeF 1.0 is irrelevant. Do not call deprecated operations.

## 1. Hard rules

1. **No mock objects for behaviour that can be run for real.**
   - Crypto (RSA-OAEP, AES, SHA-256, XAdES) is tested with real crypto, never stubbed.
   - XSD validation is tested with the real official XSD.
   - Anything that talks to KSeF is proven by **live TEST tests** (see §9).
   - Failure modes that the real API cannot be made to produce on demand
     (HTTP 429 + `Retry-After`, 500, timeouts, dropped connections, truncated bodies)
     are tested against a **real local HTTP server** that speaks real HTTP
     (fault-injection server), never against a mocked client object. Such tests are
     labelled `fault-injection`, never presented as proof of API compatibility.
   - Official example payloads from the OpenAPI (`example` / `examples` keys) are
     used for contract tests (deserialisation round-trips).
2. Default environment is **TEST**. `PRODUCTION` must be selected explicitly. There is
   no code path that switches environment automatically. No code in tests, examples
   or CI may call the DEMO or PRODUCTION API. The single exception is the read-only,
   redirect-free download of the public `/docs/v2/openapi.json` documents by
   `scripts/check-openapi-drift.mjs` (contract drift detection).
3. Secrets (KSeF token, private keys, JWT, refresh token, `Authorization` header,
   encrypted payloads, symmetric keys) never appear in logs, exceptions, `__toString`,
   `var_dump`/`console.log`/`util.inspect`, snapshots, stack traces or test output.
   Use a `Redactor` + `SensitiveString` (PHP `#[\SensitiveParameter]` where available)
   / TS class with `toJSON`/`inspect` overrides returning `[REDACTED]`.
4. Invoice content is never logged. Default logger is a no-op.
5. TLS verification is always on; no option to disable it. Min TLS 1.2.
6. The SDK only calls URLs derived from the selected environment base URL. Absolute
   URLs found in API responses are never followed unless their host equals the
   environment host (or is explicitly allow-listed by the caller). No redirects
   followed across hosts.
7. XML is parsed without network access and without entity expansion; DOCTYPE and
   processing instructions are rejected for invoices (KSeF rejects them too).
8. Money is exact decimal arithmetic (PHP: `bcmath` strings; TS: decimal class) — never float.
9. Public API stability: do not rename public symbols between phases; add, don't change.
10. Anything not fully implemented/verified is marked `@experimental` in code and
    documented as such. Never fake it.

## 2. Environments

| Name | Base URL |
|------|----------|
| `TEST` | `https://api-test.ksef.mf.gov.pl/v2` |
| `DEMO` | `https://api-demo.ksef.mf.gov.pl/v2` |
| `PRODUCTION` | `https://api.ksef.mf.gov.pl/v2` |

`Environment::custom(baseUrl)` / `Environment.custom(baseUrl)` exists only for
fault-injection servers and future MF environments: must be `https`, except
loopback hosts (`127.0.0.1`, `::1`, `localhost`) which may use `http`.
It is named/documented as "unsafe for real data".

## 3. Layering (identical in both languages)

```
KsefClient (facade, composition root)
 ├─ HttpTransport        low-level: base URL, headers, JSON, timeouts, retry policy, error mapping
 ├─ AuthenticationManager  login (token | certificate), JWT cache, refresh, re-login, single-flight
 ├─ PublicKeyProvider    GET /security/public-key-certificates (cached, by usage, rotation aware)
 ├─ Crypto               RSA-OAEP(SHA-256/MGF1-SHA256), AES-256-CBC/PKCS7, SHA-256 base64, sizes
 ├─ KsefTokenService     POST/GET/DELETE /tokens   (management of long-lived KSeF tokens)
 ├─ InvoiceSessionService  online session: open → send → close → status → failed invoices
 ├─ InvoiceService       GET /invoices/ksef/{ksefNumber}  (XML + metadata headers)
 ├─ InvoiceQueryService  POST /invoices/query/metadata (+ pagination helper)
 ├─ UpoService           session UPO / invoice UPO download
 ├─ AuthSessionService   GET /auth/sessions, DELETE /auth/sessions/current|{ref}
 ├─ LimitsService        GET /limits/context, /limits/subject, /rate-limits
 └─ TestData (TEST only) POST /testdata/person, /testdata/subject ... refuses non-TEST env
Invoice (FA(3)): Fa3InvoiceBuilder, XmlValidator, Money/Decimal  (independent of transport)
```

Out of scope for v0.1 (documented as "not implemented" in the compatibility table):
batch sessions, invoice exports, permissions management, certificate enrollment,
collective identifiers, peppol, offline modes, QR codes, FA(2)/PEF/FA_RR builders.

## 4. Authentication (most important part)

Two credential types, never conflated:

- `KsefTokenCredentials(token: SensitiveString, context: ContextIdentifier)` — long-lived KSeF token.
- `CertificateCredentials(certificatePem, privateKeyPem(+passphrase), context, subjectIdentifierType)` —
  XAdES-signed `AuthTokenRequest` (`schemas/xsd/auth/schemat_auth_v2-1.xsd`).
  Only certificates of type **Authentication** / qualified / self-signed (TEST only).
  Offline certificates are not accepted for authentication — document it.

Token flow (verify every step against `uwierzytelnianie.md` + OpenAPI):
`POST /auth/challenge` → encrypt `"{ksefToken}|{timestampMs}"` with RSA-OAEP-SHA256 using the
public key with `usage = KsefTokenEncryption` → `POST /auth/ksef-token` (with `publicKeyId`)
→ poll `GET /auth/{referenceNumber}` with the temporary `authenticationToken` as Bearer until
status 200 (100 = in progress, ≥ 400 = failure) → `POST /auth/token/redeem` (one-shot!) → `accessToken` (JWT, short)
+ `refreshToken` (long, up to 7 days) → `POST /auth/token/refresh` (refresh token as Bearer).

Certificate flow: challenge → build `AuthTokenRequest` XML → XAdES-BES **enveloped** signature
(RSA ≥ 2048 or ECDSA ≥ P-256; digest SHA-256; canonicalisation per the official allowed list)
→ `POST /auth/xades-signature` (`Content-Type: application/xml`) → same poll/redeem/refresh.
XAdES must use proven primitives only (standard C14N, `openssl`/WebCrypto signing of `SignedInfo`,
SHA-256 digests; no home-made crypto). It is only declared "supported" after a **live TEST**
login with a self-signed certificate succeeds (see §9). Until then it is `@experimental`.

`AuthenticationManager`:
- Caches per **tenant key** = `sha256(env | contextType | contextValue | authMethod | credentialFingerprint)`.
  Two contexts / two credentials never share tokens (tenant isolation test required).
- `getAccessToken(context)` returns a valid JWT: reuses cached one; refreshes when `exp - now < 60s` (configurable skew);
  if refresh fails with 401/403 or refresh token expired → one full re-login; concurrent callers share one in-flight
  login/refresh (single-flight / mutex-per-key; in PHP use a per-process in-flight guard + optional lock callback).
- `TokenStorageInterface` (PHP) / `TokenStorage` (TS): `get(key)`, `put(key, TokenPair, ttlSeconds)`, `delete(key)`.
  Default `InMemoryTokenStorage`. The long-lived KSeF token / private key is **never** passed to storage —
  only the JWT pair. Storage implementations are supplied by the app (Laravel cache adapter, Nest adapter, ...).
- Never decode-and-trust JWT claims for authorisation; only read `exp` for scheduling. Server remains the authority.
- Errors: wrong permissions → `AuthorizationException`; auth failure (status 415/425/450...) → `AuthenticationException`
  carrying the KSeF status code and details.

## 5. Crypto (verified against `sesja-interaktywna.md` & `klucze-publiczne-do-szyfrowania.md`)

- Public keys from `GET /security/public-key-certificates`; pick by `usage`
  (`KsefTokenEncryption` for token login, `SymmetricKeyEncryption` for session/invoice key),
  valid *now*, latest `validFrom` wins; send `publicKeyId`. On error code `21470`: refetch once and retry once.
  Validate: certificate parses, within validity, RSA modulus ≥ 2048, `publicKeyId` = base64(sha256(SPKI DER)) matches.
- Session key: 32 random bytes (CSPRNG) + 16 random bytes IV **per session**; AES-256-CBC + PKCS#7;
  key encrypted with RSA-OAEP (OAEP hash SHA-256, MGF1 SHA-256, empty label).
  PHP `openssl_public_encrypt` OAEP is SHA-1 only → use `phpseclib/phpseclib` v3 for RSA-OAEP-SHA256.
- Invoice metadata: `invoiceHash` = base64(sha256(plain bytes)), `invoiceSize`, `encryptedInvoiceHash`,
  `encryptedInvoiceSize`, `encryptedInvoiceContent` = base64(ciphertext).
- Known-answer tests use fixed key/IV/plaintext with independently computed expected values
  (e.g. via `openssl enc`), plus a round-trip decrypt with a locally generated RSA key.

## 6. Send lifecycle and duplicate safety

`InvoiceSessionService`:
- `openOnline(FormCode = FA(3) 1-0E / "FA") : OnlineSession` — holds `referenceNumber`, `validUntil`, key material (kept in memory only).
- `sendInvoice(OnlineSession, string $xml, SendOptions) : SentInvoice` — validates XML (optional `validate=true` default) before sending; returns invoice `referenceNumber` + `invoiceHash`.
- `close(OnlineSession)`; `getStatus(sessionRef)`; `getInvoiceStatus(sessionRef, invoiceRef)`; `listSessionInvoices(...)`; `listFailedInvoices(...)`.
- `awaitInvoice(...)`: polls with backoff + overall deadline until a terminal status; `200` = accepted (has `ksefNumber`), `>=400` = rejected →
  `InvoiceRejectedException` (status code, description, details). **HTTP 202 on send ≠ accepted.** Never report acceptance without status 200.
- High-level `InvoiceSender::submit(xml, options): SubmissionResult { ksefNumber, invoiceReferenceNumber, sessionReferenceNumber, upoAvailable... }`.

**Retry & duplicates policy (non-negotiable):**
- Idempotent GETs: retry on network error, 429 (honour `Retry-After`), 502/503/504 with exponential backoff + jitter, bounded.
- `POST /invoices send`, `open session`, `close session`, `redeem`: **never auto-retried** after an ambiguous failure (timeout, connection reset,
  5xx). Exception: HTTP 429 (request not processed) may be retried after `Retry-After`.
- Ambiguous failure on `sendInvoice` → throw `UnknownOutcomeException` with `invoiceHash` and session ref. The caller (or `InvoiceSender`)
  must *reconcile* first: list session invoices (`GET /sessions/{ref}/invoices`) and compare `invoiceHash`; only if absent may it resend.
  `InvoiceSessionService::reconcile(OnlineSession, invoiceHash): ?InvoiceStatus` implements this.
- KSeF itself rejects duplicates (code 440, key = seller NIP + invoice type + invoice number) — this is the last line of defence, not the first.

## 7. Errors

Base `KsefException`. Hierarchy (PHP `LuroWeb\KsefFoundry\Exception\…`, TS `errors` module, identical names):

```
KsefException
 ├─ ConfigurationException
 ├─ NetworkException          (connect/DNS/TLS/reset)         retryable (idempotent only)
 │   └─ TimeoutException
 ├─ ApiException              (any non-2xx; status, ksefCodes[], details[], traceId, problem)
 │   ├─ ValidationException   400 (BadRequestProblemDetails.errors[])
 │   ├─ AuthenticationException 401 (+ auth status failures)
 │   ├─ AuthorizationException  403 (reasonCode, security.requiredAnyOfPermissions…)
 │   ├─ NotFoundException / GoneException
 │   ├─ RateLimitException    429 (retryAfterSeconds)
 │   └─ ServerException       5xx
 ├─ SessionException          (session state problems, expired session, 21184 temporarily unavailable…)
 ├─ InvoiceRejectedException  (final status ≥ 400 for an invoice; KSeF status code + details)
 ├─ UnknownOutcomeException   (ambiguous send; carries invoiceHash/session ref; NOT auto-retryable)
 ├─ EncryptionException
 ├─ CertificateException      (bad/expired cert, wrong type, bad key, passphrase)
 └─ XmlValidationException    (XSD errors: list of {line, column, message, level})
```

Every `ApiException` exposes: `category`, `statusCode`, `ksefCode` (first of `errors[].code`, or legacy `exception.exceptionDetailList[0].exceptionCode`),
`ksefCodes`, `message`, `traceId`, `retryable: bool`, `retryAfterSeconds`, `diagnostics()` (secret-free array/object).
Parse both `application/problem+json` and the legacy `ExceptionResponse` shape. Do not treat 400/401/403/429 alike.

## 8. Pagination & queries

Follow the real mechanism of each endpoint in the OpenAPI (page offset/size + `hasMore`, or continuation token header).
Provide a lazy iterator helper (`PHP: Generator` / `TS: AsyncGenerator`) for metadata queries and session invoice lists.
Dates: ISO-8601 with offset; date ranges max 100 days (UTC) as per 2.7.1; convert to UTC explicitly.
Distinguish invoice subject types (`Subject1` seller / `Subject2` buyer / `Subject3` / `SubjectAuthorized`) exactly as the contract.
Never persist invoices anywhere unless the caller wires it up.

## 9. Test layers

1. **unit** — pure logic (decimal math, redaction, URL building, retry maths) with no I/O.
2. **contract** — official OpenAPI examples ↔ our models; real XSD validation; known-answer crypto.
3. **fault-injection** — real local HTTP server for 429/500/timeout/reset/slow body.
4. **framework** — Laravel (Testbench + real container/cache) / Nest (`Test.createTestingModule`, real DI).
5. **live TEST** — opt-in via `KSEF_LIVE_TEST=1`; refuse to run unless env is `TEST`.
   Needs **no secrets**: the test provisions a synthetic taxpayer itself:
   1. generate random valid NIP (checksum) + PESEL (checksum), never real ones;
   2. `POST /testdata/person {nip, pesel, description, isBailiff:false}` → owner permissions in context of that NIP;
   3. generate self-signed personal certificate (`serialNumber = PNOPL-<pesel>`, givenName, surname, CN, C=PL, RSA 2048);
   4. XAdES login (context NIP) → JWT; create a KSeF token via `POST /tokens` → token login;
   5. exercise queries, sessions, send a synthetic FA(3) invoice (random invoice number), poll status, fetch UPO, fetch by KSeF number.
   When not enabled the runner must print `SKIPPED (live TEST disabled)` — a skip is never reported as pass.
   The TEST environment is shared by all integrators: use random identifiers, keep request volume low, respect 16:00–18:00 maintenance windows.

CI default runs layers 1–4. Layer 5 lives in a separate manually-triggered workflow.
