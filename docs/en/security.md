# Security

The SDK handles taxpayer credentials and financial documents, so security is a core requirement. This guide describes what the SDK guarantees by default, what remains your responsibility, and how to report a vulnerability. The rules are consistent with [`SECURITY.md`](../../SECURITY.md).

## Contents

- [Safe-use rules](#safe-use-rules)
- [Secret handling](#secret-handling)
- [Logging policy](#logging-policy)
- [TLS](#tls)
- [SSRF and redirects](#ssrf-and-redirects)
- [XML security](#xml-security)
- [Tenant isolation](#tenant-isolation)
- [Operation safety (duplicates)](#operation-safety-duplicates)
- [What the SDK does not do](#what-the-sdk-does-not-do)
- [Reporting vulnerabilities](#reporting-vulnerabilities)

## Safe-use rules

1. Keep the KSeF token and private keys in a secret manager, not in the repository or a container image.
2. The default environment is TEST; PRODUCTION requires an explicit choice. Do not use real data (NIP, PESEL, invoices) on TEST or DEMO.
3. Do not enable logging of invoice content. The SDK logs nothing by default.
4. A custom `TokenStorage` should encrypt data at rest and isolate keys per taxpayer.
5. After an ambiguous send result (`UnknownOutcomeException`), always check the state first (`reconcile`), and only then retry if needed.
6. Use separate credentials for each environment ([Environments](environments.md)).
7. For new production integrations, consider a KSeF certificate instead of a token ([Authentication](authentication.md)).
8. In bug reports and issues, **never paste** real tokens, keys, certificates or invoice content.

## Secret handling

The SDK treats these as secrets: the KSeF token, private keys, JWTs (access and refresh), the `Authorization` header, encrypted payloads and symmetric keys.

- Values are wrapped (`SensitiveString`; in PHP also the `#[\SensitiveParameter]` attribute where available). `var_dump`, `console.log`, `util.inspect`, `__toString` and JSON serialisation yield `[REDACTED]`.
- A `Redactor` scrubs exceptions and `diagnostics()`. Exceptions, stack traces and test output contain no secrets.
- Only the JWT pair reaches `TokenStorage`. The long-lived KSeF token and the private key are **never** passed to storage.
- The JWT pair is still a credential (the refresh token lasts up to 7 days). The Laravel adapter encrypts it with `APP_KEY`; after `APP_KEY` changes, old entries are treated as a miss (a new login follows). `KSEF_CACHE_ENCRYPT=false` is discouraged.
- Session key material (AES) is kept in memory only and regenerated for every session.
- `SensitiveString` returns the value only through an explicit `reveal()`; call it only where the raw value is truly needed, and never pass its result to a logger.

## Logging policy

- The default logger is a no-op. You plug in a PSR-3 logger (PHP) or the `Logger` interface (TypeScript) explicitly.
- When you enable a logger, the SDK records the method, path and status; **never** invoice content or secrets, and events additionally pass through the redactor.
- Laravel: `KSEF_LOG_CHANNEL` (empty = no logs); the logger is wrapped in `RedactingLogger`. Job logs contain the connection name and the invoice hash.
- NestJS: `logger: 'nest'` routes SDK events to a `Logger` with context `Ksef`.
- **Note:** the Laravel `failed_jobs` table stores the job payload, which is the **invoice XML** (user data, not secrets). Protect it and set a retention policy.
- The adapters' exception filters build responses only from allow-listed `diagnostics()` fields: no tokens, no raw KSeF body and no stack trace.

## TLS

- Server certificate verification is **always on**; there is no option to disable it. The minimum TLS version is 1.2.
- The built-in PHP client (`CurlHttpClient`) requires `ext-curl`. On Windows without `curl.cainfo` set, TLS verification would fail; the built-in client uses the system certificate store (`CURLSSLOPT_NATIVE_CA`) while verification stays on.
- A custom PSR-18 client must have TLS verification enabled. The Guzzle driver in the Laravel adapter has these options pinned (`verify=true`); tests check that a self-signed certificate is refused on a real socket.

## SSRF and redirects

- The SDK calls only URLs derived from the selected environment's base URL. Request paths must be relative; absolute URLs and escaping the host are rejected.
- Absolute URLs from API responses (for example the UPO `downloadUrl`) are **not followed**. The SDK calls the documented endpoints.
- Redirects are **not followed across hosts**. In TypeScript, `fetch` runs with `redirect: 'manual'`; a redirect to another host is refused, and the allowed hosts are the environment host plus any explicitly listed in the `allowedHosts` option. PHP does not follow redirects at all.
- You **must** configure a custom PSR-18 client (Guzzle, Symfony) so it does not follow redirects to other hosts.
- `Environment::custom()` / `Environment.custom()` allows only `https` (loopback excepted) and is marked unsafe for real data.
- The Guzzle driver of the Laravel adapter does **not enforce** a response size cap (the built-in curl client does).

## XML security

- Invoices are parsed **without network access** and without entity expansion. `DOCTYPE`, `ENTITY` declarations and processing instructions are rejected (KSeF rejects them too).
- PHP loads XML with `LIBXML_NONET`, never with `LIBXML_NOENT` or `DTDLOAD`. TypeScript uses libxml2 in WebAssembly with no sockets, additionally passing `--nonet`; memory is bounded (16 MiB start, 128 MiB hard cap).
- Pre-checks (size, UTF-8 without BOM, prolog encoding, forbidden characters) run before parsing.
- The builder normalises text according to the XSD and generates XML with escaping; XML injection is within the scope of security reports. The PHP and TypeScript implementations were compared on 1500 random hostile models (CR, LF, TAB, `]]>`, XML special characters, emoji): the output was byte-identical or produced the same errors.
- The built-in curl client has a response size cap (64 MiB by default).
- There is no timeout or abort for a running WASM validation; the size limit and the memory cap are the bounds.

## Tenant isolation

Tokens are cached under the key `sha256(environment | context type | context value | method | credential fingerprint)`. Two taxpayers never share tokens; a test covers it. In multi-tenant applications use a separate client per taxpayer (Laravel: `KsefManager::tenant()`, NestJS: `KsefClientRegistry`). Remember that data isolation in your own application (who may call which connection) is up to you.

## Operation safety (duplicates)

Unsafe retrying is treated as a vulnerability (see the scope in `SECURITY.md`). That is why a send, session open and close, and `redeem` are not retried after an ambiguous failure. See [Invoice lifecycle](invoice-lifecycle.md) and [Error handling](error-handling.md).

## What the SDK does not do

- It does not cryptographically verify the UPO signature.
- It does not store invoices; durability and retention are yours.
- It does not replace access control in your application.
- It has not been verified on DEMO or PRODUCTION; qualified certificates and KSeF certificates have not been checked live.
- CI runs `npm audit` and `composer audit` for production dependencies and checks that tracked files contain no secrets, but this does not replace your own dependency review.

## Reporting vulnerabilities

**Do not open a public issue.** Use GitHub private vulnerability reporting: <https://github.com/mysterekkk/ksef-foundry/security/advisories/new>.

Please include: the package version, a description and impact, steps to reproduce (ideally a minimal example), and whether it concerns TEST, DEMO or PROD. Response targets (best effort, volunteer project): acknowledgement within 7 days, a fix or plan within 30 days, publication after the fix is released. The latest `0.x` version is supported.

In scope: leaking secrets into logs, exceptions, files or artifacts; cryptography bugs (session and token encryption, XAdES signing); SSRF and disabling TLS; XXE, entity expansion, XML injection; mixing taxpayer contexts; unsafe retrying that leads to duplicates. Out of scope: vulnerabilities in the KSeF system itself (report those to the Ministry of Finance) and dependency bugs with no effect on the SDK.

---

Built and maintained by LuroWeb.
