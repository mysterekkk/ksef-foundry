# KSeF Foundry: NestJS example

Runnable app for `@luroweb/nestjs-ksef-foundry`. It talks to the KSeF **TEST** environment only
(`KSEF_ENVIRONMENT` must be `test`; any other value stops the app on start).

## Run

From the repository root (one lockfile, npm workspaces):

```
npm install
npm run build -w @luroweb/ksef-foundry
npm run build -w @luroweb/nestjs-ksef-foundry
cp examples/nestjs/.env.example examples/nestjs/.env   # then edit the values
npm run start -w ksef-foundry-example-nestjs
```

The app listens on `http://127.0.0.1:3000` (`PORT`). `.env.example` contains obviously fake values:
use a synthetic TEST NIP and a real TEST KSeF token, or leave `KSEF_TOKEN` empty and provide
`KSEF_CERTIFICATE_PEM` / `KSEF_PRIVATE_KEY_PEM` for an XAdES login (newlines as `\n`).
`KSEF_BASE_URL` (loopback `http` or `https`) only exists to point the app at a local fault-injection server.

## Endpoints

| Method and path | What |
|---|---|
| `GET /invoices?subjectType=seller&dateType=Issue&from=&to=&pageSize=50&pageOffset=0&sortOrder=Desc` | metadata query (range max 100 days) |
| `GET /invoices/:ksefNumber` | invoice XML |
| `POST /invoices/validate` | offline FA(3) XSD + KSeF technical rules, `Content-Type: application/xml` |
| `POST /invoices/build` | JSON model (fixtures/fa3/README.md) to FA(3) XML via `Fa3InvoiceBuilder.fromModel` (XSD self-checked); bad model gives 422 |
| `POST /invoices` | send FA(3) XML (`application/xml`) or the JSON model (`application/json`): validate, open session, send, wait for status 200, close |
| `GET /invoices/status/:sessionRef/:invoiceRef` | invoice processing status |
| `GET /upo/:sessionRef/invoices/:invoiceRef`, `/upo/:sessionRef/ksef/:ksefNumber`, `/upo/:sessionRef/session/:upoRef` | UPO XML |
| `GET /limits`, `GET /limits/subject` | context + rate limits, subject limits |
| `GET /health` | KSeF reachability |
| `GET /demo/forbidden` | permission error demo: 403 `KSEF_FORBIDDEN` when the context lacks credential management |
| `GET /demo/rate-limit?burst=3` | up to 5 sequential reads (TEST is shared by all integrators, so no parallel bursts); a 429 from KSeF becomes `429 + Retry-After` |

You can also POST the JSON model directly to `/invoices` (or `/invoices/build` to just get the XML). Quick start with the SDK sample invoice:

```ts
const xml = await Fa3InvoiceBuilder.sampleXml('5265877635', 'FV/2026/11/1');
await fetch('http://127.0.0.1:3000/invoices', { method: 'POST', headers: { 'content-type': 'application/xml' }, body: xml });
```

Invoice XML is never logged. Failures go through `KsefExceptionFilter` (registered with `APP_FILTER`).
`FileTokenStorage` (`KSEF_TOKEN_CACHE_FILE`) is a custom `TokenStorage` example: it keeps only the JWT pair in a
file with mode 0600; use an encrypted store in production.

## Hardening in the example

- **Route params are validated** (`src/pipes.ts`): KSeF numbers must match the OpenAPI `KsefNumber` pattern, session/invoice/UPO reference numbers exactly 36 characters of `[A-Za-z0-9-]` (the SDK enforces the same rules itself). Anything else is a 400 before the SDK is called, so a caller cannot steer request paths.
- **Optional bearer-token guard** (`src/api-token.guard.ts`): set `KSEF_EXAMPLE_API_TOKEN` and `POST /invoices` and all `/demo/*` routes require `Authorization: Bearer <token>` (SHA-256 of both sides, constant-time compare). Unset means open, which is only acceptable because the app binds to `127.0.0.1`. This protects your API, it is not a KSeF credential; use real authentication (OIDC/JWT, mTLS) in front of anything that can submit invoices.
- **Error bodies are minimal** (`category`, `traceId`, `retryAfterSeconds`); upstream KSeF details are not forwarded to clients (`exposeUpstreamDetails` is off).
