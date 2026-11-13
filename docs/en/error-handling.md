# Error handling

The SDK raises exceptions from a single hierarchy with **the same names** in PHP and TypeScript. This guide is a reference: what each exception means, what is retried automatically, and what must never be retried.

## Contents

- [Exception hierarchy](#exception-hierarchy)
- [API exception fields](#api-exception-fields)
- [Retry matrix](#retry-matrix)
- [429 and Retry-After](#429-and-retry-after)
- [403, reasonCode and permissions](#403-reasoncode-and-permissions)
- [Notable KSeF codes](#notable-ksef-codes)
- [Handling example](#handling-example)

## Exception hierarchy

PHP: namespace `LuroWeb\KsefFoundry\Exception\…`. TypeScript: exports of `@luroweb/ksef-foundry`.

```text
KsefException
 ├─ ConfigurationException
 ├─ NetworkException
 │   └─ TimeoutException
 ├─ AbortException                (TypeScript only)
 ├─ ApiException
 │   ├─ ValidationException        400
 │   ├─ AuthenticationException    401 and authentication status failures
 │   ├─ AuthorizationException     403 and authentication status 415
 │   ├─ NotFoundException          404
 │   ├─ GoneException              410
 │   ├─ RateLimitException         429
 │   └─ ServerException            5xx
 ├─ SessionException
 ├─ InvoiceRejectedException
 ├─ UnknownOutcomeException
 ├─ EncryptionException
 ├─ CertificateException
 └─ XmlValidationException
     └─ InvoiceValidationException (FA(3): list of issues)
InvoiceBuilderException             (FA(3) builder; see the note)
```

| Exception | Meaning | Typical reaction |
|---|---|---|
| `ConfigurationException` | bad SDK configuration (environment, URL, missing credentials, date range > 100 days) | fix the configuration; do not retry |
| `NetworkException` | connection, DNS, TLS or reset error | retried automatically for idempotent requests only |
| `TimeoutException` | request timeout or overall deadline exceeded; also waiting too long for a status | for a send: **reconcile**, do not resend |
| `AbortException` (TS) | aborted through an `AbortSignal` | never retried |
| `ValidationException` | HTTP 400, details in `errors[]` | fix the data; do not retry |
| `AuthenticationException` | HTTP 401 or an authentication status other than 415 (for example 425, 450, 460) | check the credentials |
| `AuthorizationException` | HTTP 403 or authentication status 415 (no permissions) | check the context's permissions |
| `NotFoundException`, `GoneException` | 404, 410 | the resource does not exist or has expired |
| `RateLimitException` | 429; the request **was not processed** | wait `retryAfterSeconds` |
| `ServerException` | 5xx | 502, 503, 504 retried for idempotent requests |
| `SessionException` | session state (expired, code 21184 "temporarily unavailable") | open a new session |
| `InvoiceRejectedException` | final invoice status >= 400 (for example 440 duplicate) | fix the invoice; do not retry |
| `UnknownOutcomeException` | ambiguous outcome of a send | **reconcile** ([Invoice lifecycle](invoice-lifecycle.md)) |
| `EncryptionException`, `CertificateException` | cryptography error; bad or expired certificate, key or passphrase | fix the key material |
| `XmlValidationException` / `InvoiceValidationException` | XSD and technical-rule errors (line, column, message) | fix the XML |

**Note on `InvoiceBuilderException`:** in PHP (`LuroWeb\KsefFoundry\Invoice`) it extends `\InvalidArgumentException` and does **not** extend `KsefException`; in TypeScript it extends `KsefException`. Catching `KsefException` in PHP will not catch invoice-model errors.

## API exception fields

Every `ApiException` exposes: the category, HTTP status (`statusCode`), the first KSeF code (`ksefCode`) and all of them (`ksefCodes`), `details`, `traceId`, `retryable`, `retryAfterSeconds`, `authStatusCode`, and `diagnostics()` (a secret-free structure). In PHP these are methods (`$e->statusCode()`); in TypeScript they are fields and getters (`e.statusCode`, `e.retryable`).

The SDK understands both `application/problem+json` and the older `ExceptionResponse` shape. Log `diagnostics()`, not the raw response body.

## Retry matrix

Defaults: up to 4 attempts in total, exponential backoff with jitter (PHP: 0.25 s to 8 s; TypeScript: 400 ms to 10 s), with `Retry-After` taking precedence.

| Situation | Idempotent request (GET, queries) | Non-idempotent request (send, session open and close, `redeem`) |
|---|---|---|
| Network error, reset, timeout | retried | **never** after an ambiguous failure (for a send: `UnknownOutcomeException`) |
| 429 | retried after `Retry-After` | retried after `Retry-After` (the request was not processed) |
| 502, 503, 504 | retried | **never** |
| 500 | not retried | not retried |
| 400, 401, 403, 404, 410 | not retried | not retried |
| Abort (`AbortSignal`) | not retried | not retried |

Exceptions to the general rules:

- **Invoice download** retries code **21165** and a bare 406 for up to 15 s ([Invoice lifecycle](invoice-lifecycle.md#downloading-an-invoice-and-error-21165)).
- **Token login**: code 21470 (unknown public key) triggers one key refresh and one retry.
- The `POST /invoices/query/metadata` query is marked idempotent (a read).
- Difference: when `Retry-After` exceeds `maxRetryAfterSeconds` (default 60), **PHP caps the wait at that value**, while **TypeScript does not wait and raises the 429**.

To disable retries: PHP `TransportOptions(retry: RetryPolicy::none())` passed through `KsefClient::builder()->transportOptions(...)`; TypeScript `KsefClient.create({ ..., retry: { maxAttempts: 1 } })`. In Laravel, the `ksef.retry.*` keys ([Laravel](laravel.md)).

429, 500 and connection-drop behaviour was verified against a local HTTP server (fault-injection); **a real 429 with `Retry-After` has not been observed live**, because TEST does not return one on demand.

## 429 and Retry-After

`RateLimitException` carries `retryAfterSeconds` (a header in seconds or an HTTP date). The SDK retries by itself within the attempt limit. Once that is exhausted, the exception reaches your application: wait the indicated time (ideally in a queue, like `SubmitInvoiceJob`, which releases the job with a `Retry-After` delay) and slow down. In the adapters, Laravel returns 429 with `Retry-After`; so does NestJS.

## 403, reasonCode and permissions

`AuthorizationException` carries a `reasonCode` (for example `missing-permissions`) and a `security` section with the required permissions (`requiredAnyOfPermissions`) and the present ones (`presentPermissions`); both are available in `diagnostics()`. Treat 400, 401, 403 and 429 differently: each needs its own reaction. Authentication status **415** for a freshly created TEST taxpayer is permission propagation delay, not a configuration error ([Authentication](authentication.md#status-415-right-after-testdataperson)).

## Notable KSeF codes

| Code | Context | SDK handling |
|---|---|---|
| 440 | duplicate invoice; empty session closed ("Session cancelled") | `InvoiceRejectedException` with `statusExtensions` (`originalKsefNumber`, `originalSessionReferenceNumber`) |
| 21165 | invoice processed, not yet available for download | retried for up to 15 s |
| 21184 | session temporarily unavailable | `SessionException`; open a new session |
| 21470 | unknown public key (rotation) | one key refresh and one retry |
| 9101, 9102, 9105 | XAdES signature errors (bad document, missing signature, bad signature or digest) | `ValidationException` (HTTP 400) |

Codes 21470 and 21184 have not been observed live (local server only).

## Handling example

From `examples/typescript/src/04-error-handling.ts` (the example starts a **real local HTTP server** imitating 429 and 403 answers, because the real KSeF cannot be asked for them; it shows the handling path, not proof of API compatibility):

```ts
try {
  await client.http.request({ method: 'GET', path, idempotent: true });
} catch (e) {
  if (e instanceof RateLimitException) {
    console.log(`429 -> wait ${String(e.retryAfterSeconds)}s, then retry (retryable=${String(e.retryable)}, traceId=${String(e.traceId)})`);
  } else if (e instanceof AuthorizationException) {
    console.log('403 -> missing permissions:', JSON.stringify(e.diagnostics()));
  } else if (e instanceof ApiException) {
    console.log('other API error', e.statusCode, e.ksefCode);
  } else throw e;
}
```

The PHP equivalent from `examples/php/src/04-error-handling.php`:

```php
try {
    $client->transport()->send(new HttpRequest('GET', $path, idempotent: true));
} catch (RateLimitException $e) {
    echo "429 -> wait {$e->retryAfterSeconds()}s, then retry (retryable=" . var_export($e->retryable(), true) . ")\n";
} catch (AuthorizationException $e) {
    echo '403 -> missing permissions: ' . json_encode($e->diagnostics(), JSON_UNESCAPED_UNICODE) . "\n";
} catch (ApiException $e) {
    echo 'other API error ' . $e->statusCode() . ' ' . var_export($e->ksefCode(), true) . "\n";
}
```

Order of `catch` clauses matters: `ApiException` subclasses before `ApiException` itself.

When sending an invoice, handle at least `InvoiceRejectedException` (final rejection) and `UnknownOutcomeException` (reconcile), as in `03-send-invoice` ([Invoice lifecycle](invoice-lifecycle.md)). Mapping exceptions to HTTP responses in the adapters: [Laravel](laravel.md#http-errors), [NestJS](nestjs.md#exception-filter).

---

Built and maintained by LuroWeb.
