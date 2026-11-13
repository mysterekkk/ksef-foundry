# @luroweb/ksef-foundry (TypeScript)

Client for Poland's National e-Invoice System (KSeF) **API 2.0**. MIT, by LuroWeb. Node >= 22, ESM
(CommonJS consumers can `require()` it on Node >= 22.12 via `require(esm)`; there is no separate CJS build).

Default environment is **TEST**. `Environment.PRODUCTION` must be chosen explicitly. TLS verification cannot be disabled.

## Install

```sh
npm install @luroweb/ksef-foundry
# only for TestCertificateFactory (TEST-only helper):
npm install -D @peculiar/x509 reflect-metadata
```

## Usage

```ts
import { KsefClient, KsefTokenCredentials, Context, Environment } from '@luroweb/ksef-foundry';

const client = KsefClient.create({
  environment: Environment.TEST,
  credentials: new KsefTokenCredentials(process.env.KSEF_TOKEN!, Context.nip('5265877635')),
});

const page = await client.invoiceQuery.queryMetadata({
  subjectType: 'Subject1',
  dateRange: { dateType: 'PermanentStorage', from: new Date(Date.now() - 30 * 86_400_000) },
});
for await (const inv of client.invoiceQuery.iterateMetadata({ subjectType: 'Subject2', dateRange: { dateType: 'Issue', from: new Date('2026-01-01') } })) {
  console.log(inv.ksefNumber);
}

// build a synthetic FA(3) invoice, send it and wait for acceptance (status 200)
import { Fa3InvoiceBuilder } from '@luroweb/ksef-foundry';
const xml = await Fa3InvoiceBuilder.sampleXml('5265877635', 'FV/2026/11/1'); // build()/sampleXml() are async (WASM XSD worker)
const result = await client.sender.submit(xml);
console.log(result.ksefNumber);
```

Certificate (XAdES) login:

```ts
import { CertificateCredentials } from '@luroweb/ksef-foundry';
new CertificateCredentials({ certificatePem, privateKeyPem, passphrase, context: Context.nip(nip) });
```

Verified scope (LIVE TEST, 2026-11-13): self-signed personal certificate (`serialNumber=PNOPL-<pesel>`), RSA 2048 and
ECDSA P-256, `certificateSubject`, `Nip` context. Not verified: qualified/KSeF-issued certificates,
`certificateFingerprint`, other context types, DEMO/PRODUCTION. Offline certificates are not accepted by KSeF for authentication.

## What exists

`KsefClient` exposes: `tokens` (KsefTokenService), `sessions` (InvoiceSessionService), `sender` (InvoiceSender),
`invoices` (InvoiceService), `invoiceQuery` (InvoiceQueryService), `upo` (UpoService), `authSessions`
(AuthSessionService), `limits` (LimitsService), `testData` (TEST only), plus `authentication`, `publicKeys`, `http`.

Duplicate safety: `POST` send/open/close/redeem are never auto-retried after an ambiguous failure (only HTTP 429 is).
`sendInvoice` throws `UnknownOutcomeException` (with `invoiceHash`); use `sessions.reconcile()` before resending.
HTTP 202 on send is *received*, not *accepted*: `awaitInvoice` / `sender.submit` only succeed on status 200.

Secrets are wrapped in `SensitiveString` and never appear in logs, errors or `util.inspect`.

## Not implemented (v0.1)

Batch sessions, invoice exports, permissions management, certificate enrollment, collective identifiers, peppol,
offline modes, QR codes, FA(2)/PEF/FA_RR builders.

## Invoice validation

`sessions.sendInvoice` and `sender.submit` validate the XML against the bundled official FA(3) XSD (real libxml2 compiled to
WebAssembly via `xmllint-wasm`, offline) before anything is encrypted or sent. Failures throw `InvoiceValidationException`
(an `XmlValidationException` with `issues[]`, rule `code` and `fatal` level). Pass `validate: false` to skip it, or a function for a
custom validator. `XmlValidator` can also be used directly.

## Scripts

`npm run build | typecheck | lint | test | test:unit | test:contract | test:fault | verify:pack | generate:types`.
Live tests: `KSEF_LIVE_TEST=1 npm run test:live` (talks to `api-test.ksef.mf.gov.pl` only, synthetic NIP/PESEL, no secrets;
runs the full invoice lifecycle and refuses to run during the 16:00-18:00 Europe/Warsaw maintenance window).
