# Changelog

## 0.1.0

Initial release.

- `KsefModule.forRoot` / `forRootAsync` (`useFactory`, `useClass`, `useExisting`), `isGlobal`, `forFeature`.
- One long-lived `KsefClient` per registration (`KSEF_CLIENT`, `@InjectKsef()`), typed `KsefService` facade.
- Multi-tenant: named clients and `KsefClientRegistry` (`get`, `service`, `register`).
- Nest `Logger` adapter (`logger: 'nest'`) on top of the SDK redaction.
- Shutdown: shared `AbortController` passed to `KsefClient.create({ signal })`, optional `logoutOnShutdown`.
- Peer range `@nestjs/*` `^11` (Nest 10 not verified).
- `InvoiceBuilderException` maps to 422 `KSEF_INVOICE_MODEL_INVALID`.
- `KsefExceptionFilter` (secret-free error bodies, `Retry-After`, reconcile hint) and `KsefHealthIndicator`.
