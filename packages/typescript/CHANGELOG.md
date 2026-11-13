# Changelog

## 0.1.0 - unreleased

Initial release of the TypeScript core SDK for KSeF API 2.0.

- Environments (`TEST` default, `DEMO`, `PRODUCTION`, `Environment.custom` for loopback/https), `ContextIdentifier`, `FormCodes`.
- `HttpTransport`: injectable `fetch`, per-attempt timeout + overall deadline + caller `AbortSignal`,
  retry policy (idempotent GET: network/429/502/503/504; POST: 429 only), `Retry-After` (seconds and HTTP date),
  full error hierarchy from `application/problem+json` and legacy `ExceptionResponse`, manual redirects (never cross-host).
- `PublicKeyProvider` + `Crypto` (RSA-OAEP SHA-256/MGF1 SHA-256, AES-256-CBC/PKCS#7, SHA-256 base64), `publicKeyId` verification, 21470 refetch-once.
- `AuthenticationManager`: KSeF token flow and XAdES-BES certificate flow (RSA 2048 and ECDSA P-256, verified live on TEST
  with self-signed certificates), tenant-key isolation, single-flight, refresh, re-login, expiry skew, pluggable `TokenStorage`.
- Services: `KsefTokenService`, `InvoiceSessionService`, `InvoiceSender`, `InvoiceService`, `InvoiceQueryService`,
  `UpoService`, `AuthSessionService`, `LimitsService`, `TestData` (TEST only), facade `KsefClient`.
- TEST-only helpers: `TestCertificateFactory`, `generateTestNip`, `generateTestPesel`.
- FA(3) layer exported: `Fa3InvoiceBuilder`, `XmlValidator` (bundled XSD, offline WASM libxml2), `Decimal`, `Money`, `VatRate`, `Fa3Address`, `Fa3Totals`, `ValidationIssue/Result`, `FA3_NS`.
- `sendInvoice`/`submit` validate against the FA(3) XSD by default (`validate: false` or a custom function to override);
  `XmlValidationIssue` gained `fatal` level and rule `code`; `InvoiceRejectedException.statusExtensions` (e.g. `originalKsefNumber` on 440).
- `InvoiceService.getByKsefNumber` retries "not yet available" (KSeF 21165 / bare 406) for up to 15 s (configurable, abortable).
- `sender.submit` validates (XSD, size, DOCTYPE/PI, BOM) BEFORE opening a session; new `InvoiceSessionService.validateInvoice`.
- `KsefClientOptions.signal` (also `HttpTransport` option): aborts all in-flight and future requests and waits; never retried.
- Not implemented: batch sessions, exports, permissions management, certificate enrollment, collective identifiers, peppol, offline modes, QR codes.
