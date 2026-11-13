# NestJS integration: dev notes

Package `packages/nestjs`, example `examples/nestjs`.

## Decisions

- ESM only, like the core SDK. Nest 10/11 are CJS; named imports from `@nestjs/common` work through Node's CJS lexer
  (verified by `verify:pack`). Only Nest 11 was run. A Nest 10 temp-install test run was attempted but the command was denied by the permission system, so the peer range is narrowed to `^11`. Nest 12 is ESM-only and untested.
- No `emitDecoratorMetadata`: every injection uses an explicit `@Inject(token)`. This avoids ESM TDZ problems with
  metadata and makes the package work under toolchains without metadata emit (vitest/oxc).
- `ConfigurableModuleBuilder` with extras `{ name, isGlobal }`. `name` is an extra, not an option, because provider
  tokens must be known before an async options factory runs. Factory method name: `createKsefOptions`.
- Registry lives in a `@Global()` `KsefCoreModule` so every `forRoot({ name })` and every run-time `register()` ends up in one place.
- Shutdown: `KsefRuntime` passes its shutdown `AbortController` signal to `KsefClient.create({ signal })` (combined with a user supplied `signal`). Abort is never retried by the SDK, so raw `client.*` calls and `KsefService` helpers both fail with `AbortException`.
- `AuthenticationException` -> 502 (our upstream credentials failed), documented in the README and the filter.
- `NestFactory.create` calls `process.abort()` on init errors by default; the example sets `abortOnError: false`.
- Nest's `TestingModuleBuilder.compile()` replaces the static `Logger`; capture logs with `.setLogger()`.

## Tests (no mocks)

`test/support/auth-server.ts` is a real local HTTP server speaking the KSeF token-login protocol with real RSA-OAEP-SHA256
decryption; faults (429, 403 problem+json, 400, 401, 500, hang, reset) are injected after a real login.
All of it is labelled fault-injection, not API compatibility proof. Live tests (`KSEF_LIVE_TEST=1`) boot the example app with
XAdES certificate credentials of a synthetic taxpayer on TEST.

## Security review follow-ups

- I6: filter body is minimal by default; `exposeUpstreamDetails` (option or `KSEF_EXCEPTION_FILTER_OPTIONS`) opts in. Constructor is now `(options?, host?)`.
- L9: example validates route params with pipes, caps `/demo/rate-limit` to 5 sequential reads and has an optional env-configured bearer guard.
- `test/unknown-outcome.test.ts` expects the core to throw `UnknownOutcomeException` for malformed 2xx and aborted sends; the filter already maps that class to 409.

## SDK requests

Both requests of phase 1 (client-level `signal`, validate before opening a session) were implemented by the SDK and are covered by tests.

## Install-scripts warning

`npm install` warns about `esbuild@0.28.2` (postinstall `node install.js`), a transitive dependency of `vitest`/`vite` and `tsx`. npm skips it until approved; nothing in this package needs it (tests run without it).
