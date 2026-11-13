# KSeF Foundry - Laravel example

Minimal Laravel 12 app using `luroweb/laravel-ksef-foundry` (path repositories to this monorepo). Works against the KSeF **TEST** environment only by default.

```
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite && php artisan migrate     # queue tables (jobs, failed_jobs)
```

Then edit `.env`:

- `KSEF_CONTEXT_NIP` - the placeholder in `.env.example` is NOT a valid NIP (the SDK rejects it with a clear `ConfigurationException`); put the NIP of your KSeF TEST taxpayer there.
- `KSEF_TOKEN` - a KSeF TEST token for that taxpayer.
- `EXAMPLE_API_TOKEN` - add this line yourself: a long random secret protecting the demo API
  (`php -r "echo bin2hex(random_bytes(32));"`).

```
php artisan ksef:check
php artisan serve
curl -H "Authorization: Bearer $EXAMPLE_API_TOKEN" http://localhost:8000/ksef/limits
```

## Security of the demo API

Every `/ksef/*` route is behind `App\Http\Middleware\RequireApiToken`: a bearer token compared with `hash_equals`, fail-closed
(`503` when `EXAMPLE_API_TOKEN` is unset or shorter than 24 characters, `401` for a missing or wrong token). The routes live in
`routes/api.php` (stateless, no cookies, so no CSRF surface); the `web` group keeps its CSRF protection, nothing is excluded globally.
This is a demo guard only: replace it with your real authentication and authorization (Sanctum, policies ...) before exposing
anything, and add rate limiting. Never expose the submit endpoint publicly.

| Route | What |
|-------|------|
| `GET /ksef/limits` | context limits (login happens on first call) |
| `GET /ksef/invoices?subject=Subject2&page=1&per_page=20&days=30` | paginated invoice metadata |
| `GET /ksef/invoices/{ksefNumber}` | invoice XML (`X-Hash-Verified` header) |
| `POST /ksef/validate` | body = FA(3) XML; validates against the official XSD |
| `POST /ksef/invoices` | body = FA(3) XML; validates, then queues `SubmitInvoiceJob` (202) |
| `GET /ksef/status/{session}/{invoice}` | invoice processing status |
| `GET /ksef/upo/{session}/{invoice}` | UPO XML |

Process the queue with `php artisan queue:work`; `php artisan ksef:send-sample` queues a synthetic sample invoice (TEST only).
Errors from KSeF are rendered as safe JSON (429 with `Retry-After`, 403, rejected invoice 422) by `KsefExceptionRenderer`;
upstream details are not forwarded unless `KSEF_EXPOSE_DETAILS=true`.

Queue note: the database queue `retry_after` (config/queue.php, default 90 s) must be LARGER than `KSEF_JOB_TIMEOUT` (default 90 s)
or a second worker may pick the same job while the first is still running - see the package README. For this demo set
`DB_QUEUE_RETRY_AFTER=240` in `.env`.

Tests: `vendor/bin/phpunit --testsuite default` (real HTTP kernel, no KSeF network) and `composer test:live` (opt-in, KSeF TEST, creates a synthetic taxpayer).
Use a cache store shared by web and queue processes (`CACHE_STORE=file` or redis) so the JWT and the login lock are shared.
