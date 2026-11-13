# Changelog

## 0.1.0 (unreleased)

- Service provider with auto-discovery, `config/ksef.php` (tag `ksef-config`), `KsefManager`, `Ksef` facade, lazy singleton `KsefClient`.
- Laravel cache token storage (encrypted at rest), `Cache::lock` single-flight login, redacting PSR-3 logger bridge.
- Optional hardened Guzzle PSR-18 driver (`KSEF_HTTP_DRIVER=guzzle`).
- Jobs `SubmitInvoiceJob`, `PollInvoiceStatusJob`, `FetchInvoicesJob`, `InvoiceSink` contract, events `InvoiceAccepted` / `InvoiceRejected`.
- `ksef:check` command, `KsefExceptionRenderer` (no upstream details by default, `ksef.exceptions.expose_details` to opt in).
- Hardening: `#[\SensitiveParameter]` on connection arrays; per-invoice run lock + `$timeout` for `SubmitInvoiceJob`; own-duplicate (440) recognised and reported as accepted; `Retry-After` release cap (`ksef.jobs.max_release_delay`); `.gitattributes` export-ignore.
- Supports Laravel 12 only (illuminate/* ^12.0, Testbench ^10). Tested locally on Laravel 12.72.0 / Testbench 10.12 / PHP 8.4.20; CI runs PHP 8.2-8.4. Not run: `--prefer-lowest`.
