---
title: "What I learned integrating KSeF 2.0 from PHP and TypeScript"
published: false
description: "Notes from building PHP and TypeScript SDKs for Poland's e-invoicing API and testing them against the live TEST environment."
tags: php, typescript, api, opensource
---

KSeF is Poland's national e-invoice system. Version 2.0 of its API is a REST interface with encrypted sessions, XAdES authentication and FA(3), an XML invoice format validated against an official XSD. I spent a while building SDKs for it in PHP and TypeScript, with Laravel and NestJS adapters, and testing them against the live TEST environment (`api-test.ksef.mf.gov.pl`, contract 2.9.0, checked 2026-11-13).

This is not a launch pitch. The project is called [KSeF Foundry](https://github.com/mysterekkk/ksef-foundry), it is MIT licensed, and it is not the first KSeF library: the Ministry of Finance publishes official C# and Java clients, and independent PHP and TypeScript clients exist. What follows are the things that surprised me, all taken from my own notes on live runs. DEMO and PRODUCTION were never used.

## 1. HTTP 202 is not acceptance

Sending an invoice to an interactive session returns HTTP 202. That only means the request was received. The invoice is processed asynchronously, and you only know the outcome when its status reaches a final value: 200 with a KSeF number, or an error.

If your code treats 202 as success, you will report invoices as sent that KSeF later rejects. So the SDK's `submit()` opens a session, encrypts, sends, closes the session, and polls until a final status. It returns only after status 200. Here is the TypeScript example from the repository:

```ts
try {
  // open session -> encrypt -> send -> close -> poll until a FINAL status. HTTP 202 is NOT acceptance.
  const result = await client.sender.submit(xml);
  console.log('Accepted. KSeF number:', result.ksefNumber);
  // ...
} catch (e) {
  if (e instanceof InvoiceRejectedException) {
    console.error('KSeF rejected the invoice:', e.diagnostics());
  } else if (e instanceof UnknownOutcomeException) {
    // The request may or may not have been processed. NEVER resend blindly:
    // reconcile first (client.sessions.reconcile(sessionRef, e.invoiceHash)).
    console.error('Unknown outcome; reconcile before resending. hash =', e.invoiceHash);
  } else throw e;
}
```

The PHP version has the same shape and the same exception names:

```php
try {
    $result = $client->sender()->submit($xml);
    echo "Accepted. KSeF number: {$result->ksefNumber}\n";
} catch (InvoiceRejectedException $e) {
    fwrite(STDERR, 'KSeF rejected the invoice: ' . json_encode($e->diagnostics()) . "\n");
} catch (UnknownOutcomeException $e) {
    // reconcile first ($client->sessions()->reconcile($session, $e->invoiceHash()))
}
```

## 2. Duplicates and ambiguous outcomes

KSeF rejects a duplicate invoice with status 440. The duplicate key is seller NIP, invoice type and invoice number (`P_2`). In the live runs the 440 status carried `extensions.originalKsefNumber` and `extensions.originalSessionReferenceNumber`, which is useful: you can find the original instead of guessing.

The harder case is a timeout or a connection reset after you sent the invoice. You do not know whether KSeF processed it. Resending blindly risks a duplicate. The SDK raises `UnknownOutcomeException`, carrying the invoice hash, and the caller reconciles by hash before doing anything else.

One honest caveat. Duplicate detection (440) was seen live, but timeouts and resets cannot be produced on demand by the real API. Those paths are covered by fault-injection tests against a local HTTP server, which show the handling logic works but are not proof of KSeF compatibility.

## 3. TEST data propagates with a delay

On TEST you create a synthetic taxpayer through `/testdata/person`. That call returns 200 with an empty body, but the permissions it grants take a few seconds to apply. If you authenticate with XAdES right away, the status can come back as `415 "Brak przypisanych uprawnień"` (no permissions assigned). The same request a few seconds later succeeds.

My first reading was that something was wrong with my certificate or signature. It was not. Signature problems come back synchronously as HTTP 400 from `POST /auth/xades-signature` (codes 9101, 9102, 9105). Certificate or permission problems (415, 460) only show up when you poll the authentication status. That asymmetry is worth knowing, because it tells you where to look. The live tests now retry on 415.

## 4. "Processed" is not "downloadable"

Right after an invoice reaches status 200, a download can fail. I saw HTTP 400 with code `21165` ("processed but not yet available for download"), and rarely a bare 406. A few seconds later it works. One run of the PHP suite hit the 406 once and could not reproduce it. In TypeScript I saw a 1.2 second delay after a token login.

Both SDKs retry the download for up to 15 seconds on these cases. I want to be precise about what is proven: the short delay was seen live, but the behaviour when the invoice stays unavailable longer than 15 seconds was only checked against a local fault-injection server.

## 5. RSA-OAEP with SHA-256 needs phpseclib in PHP

The session key and the KSeF token are encrypted with RSA-OAEP using SHA-256 and MGF1-SHA-256. PHP's `openssl_public_encrypt` supports OAEP only with SHA-1, so it cannot do this. I used `phpseclib` v3 for that one operation and left AES-256-CBC to `ext-openssl`:

```php
$padded = $loaded->withPadding(RSA::ENCRYPTION_OAEP);
$hashed = $padded->withHash('sha256');
// ... with the MGF hash also set to sha256
$out = $mgf->encrypt($plain);
```

(Abridged from `packages/php/src/Crypto/Crypto.php`.) Node needed no extra library. A small detail from the same file: the phpseclib exception is never chained into the one the SDK throws, because its stack trace arguments can contain key material.

## 6. Offline XSD validation needs resolved copies

I wanted FA(3) validation to work offline, before any request. The official `schemat_FA3_v1-0E.xsd` imports its base schemas from a remote `http://crd.gov.pl/...` URL. A validator that must not touch the network cannot use it as is.

The fix is a script that creates derived copies where only the `schemaLocation` attribute values are rewritten to relative local paths. Every other byte stays untouched, and the repository records SHA-256 sums for both source and resolved files. Two side effects:

- Validation also rejects DOCTYPE and entity declarations before parsing, and loads with no network access.
- Line endings matter. The XSDs use CR/CRLF, so if git normalises them the hashes change, and you need `*.xsd -text` in `.gitattributes`.

In TypeScript the validation runs libxml2 compiled to WebAssembly, so `Fa3InvoiceBuilder.build()` is asynchronous. The XSD also has quirks: the `TNipVatUE` pattern in the authentication schema ends in a literal `$`, so no value can match it, and the test skips that context type.

## 7. The no-mocks policy, and where it stops

The rule I set: no mocks for logic that can run for real. Cryptography, XSD validation and the TEST API are exercised for real. The live tests are opt-in, run against TEST only, and a run that did not happen is reported as `SKIPPED`, never as a pass.

This policy has honest limits, and I try to say them out loud:

- Things the real API cannot produce on demand (429 with `Retry-After`, 5xx, timeouts, resets) are tested against a real local HTTP server. That checks my handling, not KSeF.
- Real behaviour beat the documentation several times. For example, the TEST UPO does not validate against `upo-v4-3.xsd`: the receiving entity name has a TEST suffix, and the document carries a `ds:Signature` the XSD does not declare. The UPO signature is not cryptographically verified by the SDK.
- Qualified certificates and KSeF-issued authentication certificates are unverified. Only self-signed TEST certificates were exercised.
- Closing an empty session returns 440 "session cancelled", not 200. A mock written from the docs would not have told me.

## Scope, honestly

FA(3) support covers a standard VAT invoice in PLN. There are no correcting or advance invoices, foreign currency, split payment, attachments, batch sessions or offline modes. The npm packages are published on npmjs.com (`npm install @luroweb/ksef-foundry`); the Composer packages are not yet on Packagist, so for PHP and Laravel you install from the repository. Examples run on TEST with a synthetic taxpayer, with no secrets:

```bash
cd examples/php && composer install && composer send
```

If you integrate KSeF yourself, I would be glad to hear which of these behaviours you saw differently, or ones I missed. Repository and issues: <https://github.com/mysterekkk/ksef-foundry>. Verified test results are linked from the repository's `docs/dev/test-report.md` [LINK TO BE ADDED BY THE MAINTAINER].
