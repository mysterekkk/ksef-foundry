# NestJS

The `@luroweb/nestjs-ksef-foundry` package integrates the TypeScript SDK (`@luroweb/ksef-foundry`) with NestJS: one long-lived `KsefClient` per registration (never per request), a typed `KsefService`, a multi-client registry, an exception filter and an optional health check. Version 0.1.0.

## Contents

- [Installation and requirements](#installation-and-requirements)
- [Registering the module](#registering-the-module)
- [Injection](#injection)
- [KsefService](#ksefservice)
- [Multiple clients (multi-tenant)](#multiple-clients-multi-tenant)
- [Exception filter](#exception-filter)
- [Lifecycle and shutdown](#lifecycle-and-shutdown)
- [Health check](#health-check)
- [Example app](#example-app)
- [Test scope and limitations](#test-scope-and-limitations)

## Installation and requirements

The package has not been published to npm yet (it is prepared for publication). After publication:

```bash
npm i @luroweb/nestjs-ksef-foundry @luroweb/ksef-foundry @nestjs/common @nestjs/core reflect-metadata rxjs
```

Until then, build and pack both packages from a clone of the monorepo (`npm pack -w @luroweb/ksef-foundry`, `npm pack -w @luroweb/nestjs-ksef-foundry`) and install the tarballs ([Quick start](quick-start.md#nestjs)).

Requirements: Node >= 22; peer dependencies: `@nestjs/common` and `@nestjs/core` `^11`, `rxjs ^7.1`, `reflect-metadata`, `@luroweb/ksef-foundry >=0.1.0 <1.0.0`. The package is **ESM only**, like the core SDK. Only NestJS 11 was run; Nest 10 was not verified (hence it is not declared), and Nest 12 (ESM only) was not tested.

## Registering the module

```ts
import { Context, KsefTokenCredentials } from '@luroweb/ksef-foundry';
import { KsefModule } from '@luroweb/nestjs-ksef-foundry';

@Module({
  imports: [
    KsefModule.forRoot({
      isGlobal: true,
      credentials: new KsefTokenCredentials(process.env.KSEF_TOKEN!, Context.nip(process.env.KSEF_CONTEXT_NIP!)),
      logger: 'nest', // SDK events (already redacted) to the Nest Logger "Ksef"
    }),
  ],
})
export class AppModule {}
```

The async variant (`useFactory`, `useClass` with a `createKsefOptions()` method, or `useExisting`):

```ts
KsefModule.forRootAsync({
  imports: [ConfigModule],
  inject: [ConfigService],
  useFactory: (c: ConfigService): KsefModuleOptions => ({
    credentials: new KsefTokenCredentials(c.getOrThrow('KSEF_TOKEN'), Context.nip(c.getOrThrow('KSEF_NIP'))),
  }),
});
```

`name` and `isGlobal` go next to `useFactory`, because they must be known before the factory runs.

### Options (`KsefModuleOptions`)

| Option | Meaning |
|---|---|
| `environment` | default `Environment.TEST`; PRODUCTION only explicitly |
| `credentials` | `KsefTokenCredentials` or `CertificateCredentials` |
| `storage` | a custom `TokenStorage` ([Authentication](authentication.md#tokenstorage)) |
| `fetch`, `timeoutMs`, `deadlineMs`, `retry`, `expirySkewSeconds`, `allowedHosts` | forwarded to `KsefClient.create` |
| `logger` | `'nest'` or an SDK `Logger` object; no logging by default |
| `logoutOnShutdown` | default `false`; revokes the authentication session on shutdown |
| `shutdownLogoutTimeoutMs` | 5000 ms |
| `invoiceValidator` | a custom validator before `submitInvoice` (default: the bundled FA(3) XSD) |

The example configuration factory (`examples/nestjs/src/ksef.config.ts`) picks a token and, when there is none, XAdES certificate credentials from PEMs in environment variables (newlines as `\n`).

## Injection

| What | How |
|---|---|
| `KsefClient` | `@InjectKsef()` or `@Inject(KSEF_CLIENT)` |
| `KsefService` (default) | `constructor(private ksef: KsefService)` or `@Inject(KsefService)` |
| named client / service | `@InjectKsef('tenant-a')`, `@InjectKsefService('tenant-a')` |
| registry | `KsefClientRegistry` (`get(name)`, `service(name)`, `register(name, options)`, `has`, `names`) |
| health | `KsefHealthIndicator` (default registration) |

The package **does not use `emitDecoratorMetadata`**: injection always goes through an explicit token, so write `@Inject(KsefService)` when metadata is unavailable. This also makes it work in toolchains without metadata emit.

## KsefService

A thin, typed facade over one client. Fields: `client`, `name`, `shutdownSignal`, `tokens`, `limits`, `sessions`, `authSessions`. Methods:

| Method | What it does |
|---|---|
| `validateInvoice(xml)` | offline FA(3) validation; returns a `ValidationResult`, does not throw for invalid XML |
| `submitInvoice(xml, opts?)` | open a session, send, wait for status 200, close; **never resends**, an ambiguous outcome is `UnknownOutcomeException` |
| `queryInvoices(filters, opts?)` | one page of metadata |
| `iterateInvoices(filters, opts?)` | lazy iteration over all pages |
| `downloadInvoice(ksefNumber)` | download an invoice |
| `getInvoiceStatus(sessionRef, invoiceRef)` | the status of an invoice in a session |
| `getUpo({ sessionReferenceNumber, invoiceReferenceNumber \| ksefNumber \| upoReferenceNumber })` | the UPO |

The network methods link your `AbortSignal` with the application's shutdown signal.

```ts
const page = await this.ksef.queryInvoices({
  subjectType: 'Subject1',
  dateRange: { dateType: 'Issue', from: new Date(Date.now() - 30 * 86_400_000) },
});
const result = await this.ksef.submitInvoice(xml);   // returns after status 200
```

## Multiple clients (multi-tenant)

Register one client per tenant, or create them at runtime:

```ts
KsefModule.forRoot({ name: 'a', credentials: credsA });
KsefModule.forRoot({ name: 'b', credentials: credsB });

registry.register(tenantId, { credentials, storage });   // closed with the application
const client = registry.get(tenantId);
```

Clients **do not share tokens**: the SDK keys the JWT cache by environment, context, method and credential fingerprint. `KsefModule.forFeature(['a'])` re-exports already registered named clients into a feature module. Registering the same name twice raises `ConfigurationException`.

## Exception filter

Enable it with `@UseFilters(KsefExceptionFilter)`, `app.useGlobalFilters(new KsefExceptionFilter())` or `{ provide: APP_FILTER, useClass: KsefExceptionFilter }`. Response body: `{ statusCode, error, code, message, ksef?, reconcile?, validationErrors?, detail? }`, built only from allow-listed `diagnostics()` fields: no tokens, no raw KSeF body and no stack trace.

| SDK exception | HTTP | `code` |
|---|---|---|
| `RateLimitException` | 429 + `Retry-After` | `KSEF_RATE_LIMITED` |
| `AuthorizationException` | 403 (`reasonCode`, required permissions) | `KSEF_FORBIDDEN` |
| `AuthenticationException` | 502 | `KSEF_AUTHENTICATION_FAILED` |
| `ValidationException` (KSeF 400) | 400 | `KSEF_VALIDATION_FAILED` |
| `XmlValidationException` | 422 + `validationErrors` | `KSEF_XML_INVALID` |
| `InvoiceBuilderException` | 422 + `detail` | `KSEF_INVOICE_MODEL_INVALID` |
| `InvoiceRejectedException` | 422 | `KSEF_INVOICE_REJECTED` |
| `UnknownOutcomeException` | 409 + a `reconcile` hint | `KSEF_UNKNOWN_OUTCOME` |
| `TimeoutException` | 504 | `KSEF_TIMEOUT` |
| `NetworkException` | 502 | `KSEF_NETWORK_ERROR` |
| `NotFoundException` | 404 | `KSEF_NOT_FOUND` |
| `GoneException` | 410 | `KSEF_GONE` |
| `SessionException` | 409; 503 for KSeF code 21184 | `KSEF_SESSION_ERROR` / `KSEF_SESSION_UNAVAILABLE` |
| `AbortException` | 503 | `KSEF_ABORTED` |
| other `ApiException` (5xx and so on) | 502 | `KSEF_UPSTREAM_ERROR` |
| `ConfigurationException`, `CertificateException`, `EncryptionException` | 500, generic message | `KSEF_INTERNAL_ERROR` |

`AuthenticationException` is 502, not 401: the credentials that failed belong to **your server**; a 401 would suggest to your API's client that it must log in to your service. `UnknownOutcomeException` means a send may have reached KSeF: list the session's invoices and compare `invoiceHash` before resending ([Invoice lifecycle](invoice-lifecycle.md)). The filter uses Nest's `HttpAdapterHost`; the example's tests used Express.

## Lifecycle and shutdown

When the module is destroyed (`app.close()`, or a signal with `app.enableShutdownHooks()`): an optional `logout` (only with `logoutOnShutdown: true`), then the shared `AbortController` of all that client's requests is aborted. `KsefService` calls and direct `client.*` calls end with `AbortException`; an abort is not retried and nothing is sent after shutdown.

`NestFactory.create` calls `process.abort()` on initialisation errors by default; the example app sets `abortOnError: false` so a configuration error is an ordinary exception.

## Health check

```ts
new KsefHealthIndicator(service, { mode: 'public-key' /* or 'rate-limits' */, timeoutMs: 5000 }).check();
// { ksef: { status: 'up' | 'down', environment, message? } }
```

The shape is compatible with `@nestjs/terminus` (terminus is not required). The `public-key` mode (default) is an uncached, unauthenticated `GET /security/public-key-certificates`: it checks reachability and TLS. The `rate-limits` mode is authenticated, so it also proves the credentials are valid. When `down`, the message holds only the exception class name, with no URLs or secrets.

## Example app

`examples/nestjs` works against TEST only (`KSEF_ENVIRONMENT` must be `test`; any other value stops the app at start).

```bash
npm install
npm run build -w @luroweb/ksef-foundry
npm run build -w @luroweb/nestjs-ksef-foundry
cp examples/nestjs/.env.example examples/nestjs/.env   # fill in the values
npm run start -w ksef-foundry-example-nestjs           # http://127.0.0.1:3000
```

Use a synthetic TEST NIP and a real KSeF token from TEST, or leave `KSEF_TOKEN` empty and provide `KSEF_CERTIFICATE_PEM` / `KSEF_PRIVATE_KEY_PEM`. Main endpoints: `GET /invoices` (metadata, range up to 100 days), `GET /invoices/:ksefNumber`, `POST /invoices/validate`, `POST /invoices/build` (JSON model to XML), `POST /invoices` (XML or a JSON model; validate, session, send, status 200), `GET /invoices/status/:sessionRef/:invoiceRef`, the UPO routes, `GET /limits`, `GET /health`, `GET /demo/forbidden` and `GET /demo/rate-limit` (filter demonstrations). `FileTokenStorage` (`KSEF_TOKEN_CACHE_FILE`) is an example of a custom `TokenStorage`: a file with 0600 permissions holding only the JWT pair; in production use an encrypted store. Invoice content is not logged.

## Test scope and limitations

The tests use real Nest DI, real HTTP and real cryptography; a local server (`test/support/auth-server.ts`) implements the KSeF token-login protocol with real RSA-OAEP-SHA256 decryption, and injects faults (429, 403, 400, 401, 500, hang, reset) after a real login. This is **fault-injection, not proof of API compatibility**. The live test (`KSEF_LIVE_TEST=1`) boots the example app with the XAdES credentials of a synthetic taxpayer on TEST. Details: [Testing](testing.md).

Limitations: Nest 11 only (see above), no verification on DEMO and PRODUCTION. `npm install` may warn about the `esbuild` install script (a transitive dependency of `vitest` and `tsx`); nothing in the package needs it.

---

Built and maintained by LuroWeb.
