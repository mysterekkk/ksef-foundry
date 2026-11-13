# luroweb/laravel-ksef-foundry

Integracja Laravel dla **KSeF Foundry** (klient KSeF API 2.0, `luroweb/ksef-foundry`). PHP >= 8.2, Laravel 11 / 12, MIT, LuroWeb.
Status: **v0.1 (pre-release)**. Domyslne srodowisko to **TEST**; `production` tylko po jawnym ustawieniu.

- Provider z auto-discovery, konfiguracja z `.env`, singleton `KsefClient` w kontenerze, `KsefManager` (polaczenia nazwane i tenanci z bazy), opcjonalna fasada `Ksef`.
- Cache Laravela jako magazyn tokenow JWT (szyfrowany `APP_KEY`, TTL = waznosc tokenu odswiezania), blokada `Cache::lock` przy logowaniu (single-flight miedzy procesami).
- Joby: `SubmitInvoiceJob` (bezpieczny przed duplikatami: po niejednoznacznym bledzie najpierw `reconcile`, nigdy slepy ponowny wysylek), `PollInvoiceStatusJob`, `FetchInvoicesJob` (przyrost z kursorem w Twoim `InvoiceSink`).
- Komenda `php artisan ksef:check`, `KsefExceptionRenderer` (bezpieczne odpowiedzi JSON dla 429/403/odrzuconej faktury), zdarzenia `InvoiceAccepted` / `InvoiceRejected`.
- Logger PSR-3 z kanalu Laravela (`KSEF_LOG_CHANNEL`), dodatkowo przepuszczony przez `Redactor` SDK.

```
composer require luroweb/laravel-ksef-foundry
php artisan vendor:publish --tag=ksef-config      # opcjonalnie
```

```dotenv
KSEF_ENVIRONMENT=test            # test (domyslnie) | demo | production | custom (tylko serwery testowe)
KSEF_AUTH=token                  # token | certificate
KSEF_CONTEXT_TYPE=nip
KSEF_CONTEXT_NIP=1234567890
KSEF_TOKEN=...                   # albo KSEF_CERTIFICATE_PATH / KSEF_PRIVATE_KEY_PATH / KSEF_PRIVATE_KEY_PASSPHRASE
KSEF_CACHE_STORE=redis           # wspolny magazyn (nie "array"); domyslnie domyslny store
KSEF_LOG_CHANNEL=stack           # puste = brak logow
```

Pelna dokumentacja po angielsku ponizej; szczegoly decyzji: `docs/dev/laravel-notes.md` w monorepo.

## English

### Use

```php
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\KsefManager;

final class InvoiceController
{
    public function __construct(private KsefClient $ksef, private KsefManager $ksefs) {}   // DI is preferred

    public function limits() { return $this->ksef->limits()->context(); }
}

// named connection from config('ksef.connections')
$ksefs->connection('shop-2')->query();

// tenant credentials from YOUR database, resolved at runtime (separate client, separate cached tokens)
$client = $ksefs->tenant(['auth' => 'token', 'context_value' => $tenant->nip, 'token' => $tenant->ksef_token]);

// or let jobs and connection('name') resolve tenants by name
$ksefs->resolveUsing(fn (string $name) => Tenant::find($name)?->ksefConfig());   // returns the same array shape or null
```

`Ksef::limits()` etc. (facade `LuroWeb\KsefFoundry\Laravel\Facades\Ksef`) proxies to the **default** connection; prefer injection.
`app(KsefClient::class)` is built lazily on first use, so a missing token does not break `artisan about` / `route:list`.

### Connection config keys

`auth`, `context_type` (`nip`, `internal_id`, `nip_vat_ue`), `context_value`, `token`, `certificate_path` / `certificate_pem`,
`private_key_path` / `private_key_pem`, `private_key_passphrase`, `subject_identifier`, plus optional per-connection overrides
`environment`, `base_url` (only with `custom`), `cache_store`, `cache_prefix`, `log_channel`, `http_driver`, `timeout`.

### Token cache and login lock

`CacheTokenStorage` stores only the JWT pair (never the KSeF token or private key), encrypted with the Laravel encrypter.
Changing `APP_KEY` makes old entries unreadable; they are treated as a miss and a new login happens. With `KSEF_CACHE_ENCRYPT=false`
the pair is stored as JSON (not recommended). Use a store shared by all web/queue workers (redis, database, file) -
`array` is per process and `null` caches nothing. Login/refresh runs inside `Cache::lock('<prefix>:login:<tenantKey>')`
so concurrent workers share one login; a worker that cannot get the lock in `KSEF_LOCK_WAIT` seconds fails with a `KsefException`.

### HTTP client

Default: the SDK built-in curl client (TLS verification on, TLS >= 1.2, no redirects, timeouts, response size cap).
`KSEF_HTTP_DRIVER=guzzle` switches to `GuzzlePsr18Client`, a PSR-18 wrapper over Guzzle with **pinned** options
(`allow_redirects=false`, `verify=true`, `http_errors=false`, timeouts from config). Tested: cross-host redirect not followed,
timeout enforced, self-signed TLS refused. Not enforced for Guzzle: the response size cap. The `Illuminate\Http\Client` facade is
not used: it cannot offer a plain PSR-18 contract with these guarantees.

### Jobs

```php
use LuroWeb\KsefFoundry\Laravel\Jobs\{SubmitInvoiceJob, PollInvoiceStatusJob, FetchInvoicesJob};

SubmitInvoiceJob::dispatch($fa3Xml, 'default');          // connection NAME, never credentials
FetchInvoicesJob::dispatch('default', 'Subject2');       // scheduler: ->everyFifteenMinutes()
Event::listen(InvoiceAccepted::class, fn ($e) => ...);   // ksefNumber, invoiceHash, session/invoice references (no content)
```

`SubmitInvoiceJob`: unique per (connection, invoice hash); a pending marker (session reference) is cached before the send; after
`UnknownOutcomeException`, a crash or any later error the next step is `InvoiceSessionService::reconcile()` and a resend happens only
when KSeF has no such invoice. 429 -> `release(Retry-After)`; rejected (>= 400) -> failed without retry (+ `InvoiceRejected`);
still processing -> `PollInvoiceStatusJob`. Bind your own `InvoiceSink` (cursor + `accept()`, idempotent by KSeF number) for
`FetchInvoicesJob`; the default sink drops everything and persists nothing. The queue payload contains the invoice XML.

### Queue requirements (important)

- `retry_after` of the queue connection MUST be larger than `KSEF_JOB_TIMEOUT` (default 90 s; e.g. `retry_after` 240 for the
  database/redis driver). Otherwise a second worker can pick up the same invoice while the first attempt is still running.
- As a second line of defence `SubmitInvoiceJob` runs under an atomic `Cache::lock` per invoice (needs a lock-capable store shared by
  all workers: redis, database, file, not `array`/`null`); a worker that finds the lock held releases the job after 15 s and sends nothing.
  `pcntl` is needed for the worker to enforce `$timeout`; on Windows only the lock protects you.
- If KSeF still answers 440 (duplicate) and the already stored invoice is byte-identical with the one we sent (verified by SHA-256 of the
  download), the job reports `InvoiceAccepted` with the existing KSeF number instead of `InvoiceRejected`; any other 440 stays a rejection.
- Server `Retry-After` values used for `release()` are capped by `ksef.jobs.max_release_delay` (`KSEF_JOB_MAX_RELEASE`, default 300 s).

### Secrets in stack traces

Connection arrays (token, private key, passphrase) are passed through `#[\SensitiveParameter]` parameters, so PHP does not print them
in trace args even with `zend.exception_ignore_args=0`. Still set it to `1` in production.

### HTTP errors

```php
// bootstrap/app.php
->withExceptions(fn ($e) => $e->render(fn (KsefException $x) => KsefExceptionRenderer::render($x)))
```
429 -> 429 + `Retry-After`; 403 -> 403; failed login -> 502; rejected invoice -> 422; unknown outcome -> 409 (hash + session reference).
By default the response carries generic messages, `ksefCode`, `traceId` and nothing upstream: `details`, `security` and the upstream text
(which may contain taxpayer identifiers) are forwarded only with `KSEF_EXPOSE_DETAILS=true` (`ksef.exceptions.expose_details`).

### Development

```
composer install
composer check        # phpcs + phpstan (level max, larastan) + unit/feature/fault tests
composer test:live    # opt-in, KSeF TEST only (KSEF_LIVE_TEST=1), synthetic taxpayer
```

Supported Laravel versions are listed in `CHANGELOG.md` together with the versions the suite was actually run on.
Not covered: KSeF DEMO/PRODUCTION (never touched by tests), Laravel Octane, Horizon specifics.
