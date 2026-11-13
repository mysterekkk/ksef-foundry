# Laravel integration notes (internal)

Package: `packages/laravel` (`luroweb/laravel-ksef-foundry`), example: `examples/laravel`.

## Decisions

- **Connection config is a plain array** (same shape for `config('ksef.connections.*')` and runtime tenants via `KsefManager::tenant()` /
  `resolveUsing()`). Jobs carry only the connection NAME, so DB tenants work through `resolveUsing`. Tenant isolation comes from the SDK
  tenant key (`env|context|method|credential fingerprint`), which the cache storage uses verbatim under `ksef:jwt:`.
- **Lazy client**: `KsefClient` is a container singleton resolved on first use; config errors surface as `ConfigurationException`, not at boot.
- **Environment**: `KSEF_ENVIRONMENT` unset/empty = TEST; `test|demo|production|custom` (case-insensitive, trimmed); anything else
  throws. `custom` needs `base_url` (SDK rule: https, or loopback http) and exists for fault-injection tests only.
- **Token storage**: JWT pair JSON encrypted with the Laravel `Encrypter` (serialize=false), TTL = min(SDK ttl, refresh expiry). Undecryptable
  or malformed entries are treated as a miss and deleted.
- **Lock**: `Cache::lock('<prefix>:login:<tenantKey>')->block(wait, work)` passed to `KsefClientBuilder::lock()`. Only when the store
  implements `LockProvider`. The SDK re-reads the storage inside the lock (double-checked), so waiters do not re-login.
- **HTTP**: default is the SDK curl client. `GuzzlePsr18Client` is offered because its security-relevant options can be pinned
  (redirects off, verify on, http_errors off, timeouts). Verified by tests against real sockets (redirect, timeout, self-signed TLS).
  Not provided: response size cap; `Illuminate\Http\Client` was not wrapped (it layers retries/redirect defaults on Guzzle and gives no
  PSR-18 surface; Guzzle direct is the honest minimum).
- **SubmitInvoiceJob** uses the lower-level `InvoiceSessionService` (not `InvoiceSender`) because `InvoiceSender` closes the session and
  loses the session reference when `reconcile` itself fails; the job keeps a cache marker `{session, invoice?}` (key `ksef:submit:<sha256(connection|hash)>`,
  TTL `unique_for`) written BEFORE the send. Next attempt: marker with invoice -> only await; marker without invoice -> `reconcile`;
  absent -> forget marker, fresh session, resend; present -> continue with its reference. 429 keeps the marker (not processed, reconcile
  finds nothing, resend). Definite 4xx clears it.
- **Queueable gotcha**: job properties must not be called `$connection`/`$queue` (trait properties) - fatal at class load. Jobs use `$ksefConnection`.
- `InteractsWithQueue::fail()` is a no-op without a queue job (e.g. `dispatchSync`): rejected invoices only land in `failed_jobs` under a real worker. Tests use the database queue + `queue:work --once`.
- `failed_jobs.payload` contains the invoice XML (user data, never secrets). Logs contain connection name + hash only.

## Test matrix actually run

Laravel 12 only. Laravel 11 was dropped: Composer refuses every laravel/framework 11.x release in a clean install (security advisories), so testbench ^9 cannot be resolved and 11 cannot honestly be supported. Run locally: Laravel 12.72.0, Testbench 10.12, PHP 8.4.20 (60 tests, phpcs, PHPStan max). Not run locally: prefer-lowest, PHP 8.2/8.3 (CI).

## Findings

- SDK (agent A, current): `AuthenticationManager` raises `AuthorizationException` (statusCode 403) for auth status 415; the KSeF auth status
  is `ApiException::authStatusCode()`. Live retry loops must use that, not `statusCode()`.
- `mergeConfigFrom` is shallow: a user config that defines `connections` replaces the packaged `connections` entirely (intended).
- `.env.example` of the example holds `KSEF_CONTEXT_NIP=0000000000`, which `ContextIdentifier::nip()` rejects with a clear error until replaced.
