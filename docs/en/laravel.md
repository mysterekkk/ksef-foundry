# Laravel

The `luroweb/laravel-ksef-foundry` adapter integrates the PHP core (`luroweb/ksef-foundry`) with Laravel 11 and 12: an auto-discovered provider, configuration from `.env`, a token cache, queue jobs and a diagnostic command. Version 0.1.0 (pre-release).

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Dependency injection and the facade](#dependency-injection-and-the-facade)
- [Token cache and login lock](#token-cache-and-login-lock)
- [Queue jobs](#queue-jobs)
- [HTTP errors](#http-errors)
- [The ksef:check command](#the-ksefcheck-command)
- [Multiple taxpayers](#multiple-taxpayers)
- [Example app](#example-app)
- [Test scope](#test-scope)

## Installation

The package has not been published to Packagist yet (it is prepared for publication). After publication, `composer require luroweb/laravel-ksef-foundry` will do. Until then, use `path` repositories pointing at a clone of the monorepo ([Quick start](quick-start.md#laravel)).

```bash
php artisan vendor:publish --tag=ksef-config   # optional: config/ksef.php
```

The provider (`LuroWeb\KsefFoundry\Laravel\KsefServiceProvider`) and the `Ksef` facade alias are auto-discovered.

## Configuration

All settings come from `.env`; secrets should not go into `config/ksef.php`.

```dotenv
KSEF_ENVIRONMENT=test            # test (default) | demo | production | custom
KSEF_AUTH=token                  # token | certificate
KSEF_CONTEXT_TYPE=nip            # nip | internal_id | nip_vat_ue
KSEF_CONTEXT_NIP=<NIP>
KSEF_TOKEN=<KSeF-token>          # or a certificate:
# KSEF_CERTIFICATE_PATH=... KSEF_PRIVATE_KEY_PATH=... KSEF_PRIVATE_KEY_PASSPHRASE=...
KSEF_CACHE_STORE=redis           # a shared store, not "array"
KSEF_LOG_CHANNEL=stack           # empty = no logs
```

| Variable | Default | Meaning |
|---|---|---|
| `KSEF_CONNECTION` | `default` | name of the default connection |
| `KSEF_ENVIRONMENT` | `test` | environment; `custom` requires `base_url` and is for test servers only |
| `KSEF_HTTP_DRIVER` | `curl` | `curl` (built-in) or `guzzle` (hardened PSR-18 wrapper) |
| `KSEF_HTTP_TIMEOUT` / `_CONNECT_TIMEOUT` / `KSEF_HTTP_DEADLINE` | 30 / 10 / 120 s | time limits |
| `KSEF_RETRY_ATTEMPTS`, `_BASE_DELAY`, `_MAX_DELAY`, `KSEF_RETRY_MAX_AFTER` | 4, 0.25 s, 8 s, 60 s | retry policy ([Error handling](error-handling.md)) |
| `KSEF_CACHE_STORE`, `KSEF_CACHE_PREFIX` | default store, `ksef` | token store |
| `KSEF_CACHE_ENCRYPT` | `true` | encrypt the JWT pair with `APP_KEY` |
| `KSEF_CACHE_LOCK`, `KSEF_LOCK_SECONDS`, `KSEF_LOCK_WAIT` | `true`, 150, 130 | login lock |
| `KSEF_QUEUE`, `KSEF_QUEUE_CONNECTION` | empty | queue and connection of the jobs |
| `KSEF_JOB_AWAIT` | 20 s | how long `SubmitInvoiceJob` waits for a status before handing over to `PollInvoiceStatusJob` |
| `KSEF_JOB_VALIDATE` | `true` | XSD validation inside the job |
| `KSEF_JOB_RECONCILE_DELAY`, `KSEF_JOB_UNIQUE_FOR` | 3 s, 3600 s | pause before reconciling; marker and uniqueness lifetime |
| `KSEF_FETCH_LOOKBACK_DAYS` | 30 | where `FetchInvoicesJob` starts without a cursor |

Connection keys (`connections.*`): `auth`, `context_type`, `context_value`, `token`, `certificate_path` / `certificate_pem`, `private_key_path` / `private_key_pem`, `private_key_passphrase`, `subject_identifier`, plus overrides: `environment`, `base_url`, `cache_store`, `cache_prefix`, `log_channel`, `http_driver`, `timeout`.

Notes:

- `KsefClient` is created **lazily** on first use. A missing token does not break `artisan about` or `route:list`; the error (`ConfigurationException`) appears only on use.
- `mergeConfigFrom` is shallow: if your own config file defines `connections`, it replaces the package's entirely.
- The value `0000000000` in `KSEF_CONTEXT_NIP` from the example `.env.example` is rejected until you replace it.

## Dependency injection and the facade

Prefer injecting `KsefClient` (the default connection) or `KsefManager` (named connections and tenants):

```php
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\KsefManager;

final class InvoiceController
{
    public function __construct(private KsefClient $ksef, private KsefManager $ksefs) {}

    public function limits() { return $this->ksef->limits()->context(); }
}

$ksefs->connection('shop-2')->query();   // a named connection from config('ksef.connections')
```

The `LuroWeb\KsefFoundry\Laravel\Facades\Ksef` facade (`Ksef::limits()` and so on) proxies to the **default** connection; `Ksef::manager()` returns `KsefManager`. Prefer injection: it is testable and explicit about the taxpayer.

## Token cache and login lock

`CacheTokenStorage` stores **only the JWT pair** (never the KSeF token or the private key) in the Laravel cache, encrypted with the `Encrypter`. Changing `APP_KEY` invalidates old entries (treated as a miss, a new login follows). The TTL is the shorter of the SDK TTL and the refresh token's validity. The key is the SDK tenant key under the `ksef:jwt:` prefix.

Login and refresh run inside `Cache::lock('<prefix>:login:<tenantKey>')` when the store supports `LockProvider`. Concurrent workers therefore share one login; a worker that cannot get the lock within `KSEF_LOCK_WAIT` seconds fails with a `KsefException`.

Use a store shared by the web and queue processes (Redis, database, file). The `array` store is per process, and `null` stores nothing.

## Queue jobs

```php
use LuroWeb\KsefFoundry\Laravel\Jobs\{SubmitInvoiceJob, PollInvoiceStatusJob, FetchInvoicesJob};

SubmitInvoiceJob::dispatch($fa3Xml, 'default');          // connection NAME, never credentials
FetchInvoicesJob::dispatch('default', 'Subject2');       // e.g. in the scheduler every 15 minutes
Event::listen(InvoiceAccepted::class, fn (InvoiceAccepted $e) => logger()->info('KSeF', ['ksefNumber' => $e->ksefNumber]));
```

**`SubmitInvoiceJob`** sends an invoice without duplicate risk:

- it is unique per (connection, invoice hash): the same XML is never queued twice at once,
- **before sending**, it stores a marker with the session reference in the cache (`ksef:submit:<sha256(connection|hash)>`, TTL = `unique_for`),
- after `UnknownOutcomeException`, a process crash or any error after the send, the next step is **`reconcile`**; a resend happens only when KSeF has no such invoice (marker with an invoice: only await the status; marker without an invoice: reconcile; no marker: new session and send),
- 429: `release(Retry-After)`, the marker stays (the request was not processed),
- a definite 4xx or a rejection (>= 400): the job fails **without retry** and emits `InvoiceRejected`,
- slow processing: `PollInvoiceStatusJob` takes over.

Defaults: `tries = 6`, delays 30, 60, 120, 300, 600 s. The job payload contains the **invoice XML** (see [Security](security.md)). Rejected invoices land in `failed_jobs` only under a real worker; with `dispatchSync`, `fail()` is a no-op.

**`PollInvoiceStatusJob`** polls the status with a growing delay until a final status (at most 7200 s) and emits `InvoiceAccepted` or `InvoiceRejected`. It is read-only, so retries are safe.

**`FetchInvoicesJob`** fetches metadata incrementally (`PermanentStorage`, ascending, with `restrictToPermanentStorageHwmDate`). The cursor (the highest `permanentStorageDate`) is kept by **your** `InvoiceSink`. The package persists nothing; the default `NullInvoiceSink` drops everything. Implement the `LuroWeb\KsefFoundry\Laravel\Contracts\InvoiceSink` interface (`cursor`, `accept`, `saveCursor`) and bind it in the container. `accept()` must be idempotent by KSeF number, because an invoice at the cursor boundary may be delivered twice.

Events: `InvoiceAccepted` (connection, hash, KSeF number, invoice and session references) and `InvoiceRejected` (connection, hash, status code, description, details, references).

## HTTP errors

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(fn (KsefException $e) => KsefExceptionRenderer::render($e));
})
```

`LuroWeb\KsefFoundry\Laravel\Exceptions\KsefExceptionRenderer` returns safe JSON `{"error": {"type", "message", ...}}` and maps:

| Exception | HTTP |
|---|---|
| `RateLimitException` | 429 + `Retry-After` |
| `AuthorizationException` | 403 |
| `AuthenticationException` | 502 (your server's credentials failed, not the caller's) |
| `InvoiceRejectedException`, `XmlValidationException` | 422 |
| `UnknownOutcomeException` | 409 (hash and session reference in the body) |
| `NetworkException` | 504 |
| `ConfigurationException` | 500 |
| other `ApiException` | 502 |

## The ksef:check command

```bash
php artisan ksef:check [connection] [--no-auth]
```

It prints the connection, the environment and URL (with a warning for PRODUCTION), the masked context, the authentication method, the public keys (`KsefTokenEncryption`, `SymmetricKeyEncryption`) and, without `--no-auth`, also the login result and the online session limits. It prints no secrets. It returns a failure exit code on error.

## Multiple taxpayers

You supply taxpayers stored in your database at runtime; each gets its own client and its own cache entries:

```php
$client = $ksefs->tenant(['auth' => 'token', 'context_value' => $tenant->nip, 'token' => $tenant->ksef_token]);

// connection names outside the config (for example a tenant id), also for jobs:
$ksefs->resolveUsing(fn (string $name) => Tenant::find($name)?->ksefConfig()); // an array like the config, or null
```

Jobs carry only the connection **name**, so database tenants work through `resolveUsing`. `KsefManager` remembers up to 32 tenant clients. `forget($name)` and `purge()` release clients (for example after rotating a token).

## Example app

`examples/laravel` is a minimal Laravel 12 app (`path` repositories into the monorepo), TEST only by default.

```bash
cd examples/laravel
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate    # queue tables
# fill in KSEF_CONTEXT_NIP and KSEF_TOKEN (a token from the TEST environment)
php artisan ksef:check && php artisan serve
php artisan queue:work                                    # in a second terminal
```

Routes: `GET /ksef/limits`, `GET /ksef/invoices?subject=Subject2&page=1&per_page=20&days=30`, `GET /ksef/invoices/{ksefNumber}`, `POST /ksef/validate` (raw XML), `POST /ksef/invoices` (validates and queues `SubmitInvoiceJob`, 202), `GET /ksef/status/{session}/{invoice}`, `GET /ksef/upo/{session}/{invoice}`. `php artisan ksef:send-sample` queues a synthetic sample invoice (TEST only). Use `CACHE_STORE=file` or Redis so the web process and the worker share the JWT and the lock.

## Test scope

Adapter tests: 51 tests / 263 assertions on Laravel 12.72.0 (Testbench 10.12) and Laravel 11.57.1 (Testbench 9.18), PHP 8.4.20, green (unit, feature, fault-injection). **Not run:** `--prefer-lowest`, PHP 8.2 and 8.3, Laravel older than 11.57, Octane, Horizon specifics. The live test (opt-in) creates a synthetic taxpayer on TEST. DEMO and PRODUCTION were never used. Details: [Testing](testing.md).

---

Built and maintained by LuroWeb.
