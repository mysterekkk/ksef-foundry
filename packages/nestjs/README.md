# @luroweb/nestjs-ksef-foundry

NestJS integration for [`@luroweb/ksef-foundry`](../typescript) (KSeF API 2.0 SDK) by LuroWeb. MIT.

One long-lived `KsefClient` per registration (never per request), a typed `KsefService` facade,
multi-tenant registry, an exception filter and an optional health check. Default environment is **TEST**;
PRODUCTION must be selected explicitly.

```
npm i @luroweb/nestjs-ksef-foundry @luroweb/ksef-foundry @nestjs/common @nestjs/core reflect-metadata rxjs
```

Peers: `@nestjs/common`/`@nestjs/core` `^11` (Nest 10 was not verified, so it is not declared; Nest 12 is ESM-only and untested), `rxjs`, `reflect-metadata`, `@luroweb/ksef-foundry`.
The package is ESM (like the core SDK).

## Setup

```ts
import { Context, Environment, KsefTokenCredentials } from '@luroweb/ksef-foundry';
import { KsefModule } from '@luroweb/nestjs-ksef-foundry';

@Module({
  imports: [
    KsefModule.forRoot({
      isGlobal: true,
      credentials: new KsefTokenCredentials(process.env.KSEF_TOKEN!, Context.nip('5265877635')),
      logger: 'nest', // SDK events (already redacted) -> Nest Logger "Ksef"
    }),
  ],
})
export class AppModule {}
```

Async (any of `useFactory`, `useClass` with `createKsefOptions()`, `useExisting`):

```ts
KsefModule.forRootAsync({
  imports: [ConfigModule],
  inject: [ConfigService],
  useFactory: (c: ConfigService): KsefModuleOptions => ({
    credentials: new KsefTokenCredentials(c.getOrThrow('KSEF_TOKEN'), Context.nip(c.getOrThrow('KSEF_NIP'))),
  }),
});
```

`name` and `isGlobal` sit next to `useFactory` (they must be known before the factory runs).

### Options (`KsefModuleOptions`)

`environment` (default `Environment.TEST`), `credentials`, `storage` (custom `TokenStorage`), `fetch` (custom transport),
`timeoutMs`, `deadlineMs`, `retry`, `expirySkewSeconds`, `allowedHosts` (all forwarded to `KsefClient.create`) plus:
`logger` (`'nest'` or an SDK `Logger`; default none), `logoutOnShutdown` (default `false`),
`shutdownLogoutTimeoutMs` (5000), `invoiceValidator` (custom pre-flight validator for `submitInvoice`; default is the SDK's bundled FA(3) XSD).

## Injection

| What | How |
|---|---|
| `KsefClient` | `@InjectKsef()` / `@Inject(KSEF_CLIENT)` |
| `KsefService` (default) | `constructor(private ksef: KsefService)` (or `@Inject(KsefService)`) |
| named client / service | `@InjectKsef('tenant-a')`, `@InjectKsefService('tenant-a')` |
| registry | `KsefClientRegistry` (`get(name)`, `service(name)`, `register(name, options)`, `has`, `names`) |
| health | `KsefHealthIndicator` (default registration) |

Without `emitDecoratorMetadata` use `@Inject(KsefService)` explicitly.

`KsefService`: `client`, `name`, `shutdownSignal`, `tokens`, `limits`, `sessions`, `authSessions`,
`validateInvoice(xml)`, `submitInvoice(xml, opts?)`, `queryInvoices(filters, opts?)`, `iterateInvoices(filters, opts?)`,
`downloadInvoice(ksefNumber)`, `getInvoiceStatus(sessionRef, invoiceRef)`,
`getUpo({ sessionReferenceNumber, invoiceReferenceNumber | ksefNumber | upoReferenceNumber })`.
The network helpers link your `AbortSignal` with the shutdown signal.

## Multi-tenant

Register one client per tenant (`forRoot({ name: 'a', ... })`, `forRoot({ name: 'b', ... })`) or create them at run time:

```ts
registry.register(tenantId, { credentials, storage });   // closed with the app
const client = registry.get(tenantId);
```

Clients never share tokens: the SDK keys its JWT cache by environment, context, auth method and credential fingerprint.
`KsefModule.forFeature(['a'])` re-exports already registered named clients into a feature module.

## Exception filter

`@UseFilters(KsefExceptionFilter)`, `app.useGlobalFilters(new KsefExceptionFilter())` or
`{ provide: APP_FILTER, useClass: KsefExceptionFilter }`. Body: `{ statusCode, error, code, message, ksef, reconcile?, validationErrors?, detail? }`,
built only from whitelisted `diagnostics()` fields (no tokens, no raw upstream body, no stack).

**Minimal by default.** `ksef` is just `{ category, traceId?, retryAfterSeconds? }`. Upstream KSeF `details`, `reasonCode`,
`security` (required permissions), KSeF error/status codes and `retryable` can contain taxpayer identifiers, so they are only
forwarded with `exposeUpstreamDetails`: `new KsefExceptionFilter({ exposeUpstreamDetails: true })`, or, when Nest creates the
filter, `{ provide: KSEF_EXCEPTION_FILTER_OPTIONS, useValue: { exposeUpstreamDetails: true } }`. Default is `false`;
enable it only for trusted/internal clients. The `reconcile` hint (session reference, `invoiceHash` of the caller's own invoice),
`validationErrors` and builder `detail` come from local checks and are always included.

| SDK exception | HTTP | `code` |
|---|---|---|
| `RateLimitException` | 429 + `Retry-After` | `KSEF_RATE_LIMITED` |
| `AuthorizationException` | 403 (`reasonCode`, required permissions) | `KSEF_FORBIDDEN` |
| `AuthenticationException` | 502 | `KSEF_AUTHENTICATION_FAILED` |
| `ValidationException` (KSeF 400) | 400 | `KSEF_VALIDATION_FAILED` |
| `XmlValidationException` | 422 + `validationErrors` | `KSEF_XML_INVALID` |
| `InvoiceBuilderException` | 422 + `detail` | `KSEF_INVOICE_MODEL_INVALID` |
| `InvoiceRejectedException` | 422 | `KSEF_INVOICE_REJECTED` |
| `UnknownOutcomeException` (dropped connection, malformed 2xx, aborted send) | 409 + `reconcile` hint | `KSEF_UNKNOWN_OUTCOME` |
| `TimeoutException` / `NetworkException` | 504 / 502 | `KSEF_TIMEOUT` / `KSEF_NETWORK_ERROR` |
| `NotFoundException` / `GoneException` | 404 / 410 | |
| `SessionException` | 409 (503 for KSeF code 21184) | |
| `AbortException` | 503 | `KSEF_ABORTED` |
| other `ApiException` (5xx...) | 502 | `KSEF_UPSTREAM_ERROR` |
| `ConfigurationException`, `CertificateException`, `EncryptionException` | 500, generic message | |

`AuthenticationException` is 502, not 401: the credentials that failed are *your server's*; a 401 would tell your API
client to log in to your service. `UnknownOutcomeException` means a send may or may not have reached KSeF: list the session
invoices and compare `invoiceHash` before resending.

## Lifecycle

On module destroy (`app.close()`, or a signal with `app.enableShutdownHooks()`): optional `logout` (only with
`logoutOnShutdown: true`), then an `AbortController` shared by all requests of that client is aborted. `KsefService`
and direct `client.*` calls fail with `AbortException` (the shutdown signal is passed to `KsefClient.create({ signal })`; abort is never retried and nothing is sent afterwards).

## Health

`new KsefHealthIndicator(service, { mode: 'public-key' | 'rate-limits', timeoutMs })` returns
`{ ksef: { status: 'up' | 'down', environment, message? } }` (terminus-compatible shape, terminus not required).
`public-key` (default) is an uncached unauthenticated call; `rate-limits` also proves the credentials.

## Tests

`npm test` (real Nest DI, real HTTP, real crypto, local fault-injection servers) and `npm run test:live`
(`KSEF_LIVE_TEST=1`, TEST environment only, synthetic taxpayer; prints `SKIPPED (live TEST disabled)` otherwise).
See [`examples/nestjs`](../../examples/nestjs) for a runnable app.
