# FA(3) invoice layer, TypeScript port: differences, engine choice, limits

Code: `packages/typescript/src/invoice/**` (public API only through `src/invoice/index.ts`).
Tests: `packages/typescript/test/invoice/**` (vitest, no mocks). XSD bundle: `packages/typescript/resources/xsd/FA3/`
(byte copy of `packages/php/resources/xsd/FA3`, tested identical to `schemas/xsd/FA/resolved`).
Scope, rounding, supported/unsupported matrix and text rules are unchanged: see `docs/dev/fa3-scope.md`.

Run the invoice tests from `packages/typescript`:
`npm run test:invoice` (also included in `npm test`).

## Verified identical to PHP

- All 13 valid fixtures: `Fa3InvoiceBuilder` output is BYTE-identical to `*.expected.xml` (compared as bytes, not C14N), totals JSON
  identical including key order.
- Differential run against the PHP builder (PHP 8.4.20): 1500 random hostile models (CR, LF, TAB, `]]>`, `& < > " '`, emoji, U+0085, U+2028,
  odd decimals): 121 byte-identical XML documents and 1379 identical error messages, 0 differences (ad-hoc script, not part of the suite).
- All 16 stored invalid fixtures: `ValidationIssue` (line, column, level, code, message) identical to PHP/libxml for every issue
  (compared on the first 70 characters of the message); the 17th (oversize) is generated in the test.
- `Decimal` is `bigint` scaled integers with half-up (away from zero) rounding; same scale rules as the bcmath class; property test
  (1500 random invoices vs an integer-grosze oracle; own seeded PRNG, so not the same sequence as PHP mt_rand) passes.

## Differences vs PHP (all deliberate)

1. Async XSD. The WASM engine runs in a worker thread, so `XmlValidator.validateFa3/assertValid`, `Fa3InvoiceBuilder.build()` and
   `sampleXml()` return Promises. `buildUnvalidated()` is the synchronous path (model checks, no XSD). `withoutSelfValidation()` kept.
2. JS has no int/float distinction: `1.0` is the integer `1` and is accepted; any non-integer `number`, NaN, Infinity and integers beyond
   2^53-1 are rejected with `InvoiceBuilderException` (PHP rejects the float `1.0` with a TypeError). Accepted inputs:
   `string | bigint | safe integer | Decimal`. JSON numbers in `fromModel/fromJson` follow the same rule (`1.5` rejected, `"1.5"` accepted).
3. Validator input is `string | Uint8Array`. For strings a lone surrogate is the analogue of invalid UTF-8 (code `utf8`) and a leading
   U+FEFF is the BOM. Use bytes (`fs.readFileSync`) when the original encoding matters.
4. Extra code `engine` (TS only): the WASM engine itself failed (schema could not be compiled, or the bounded memory was exhausted).
   It is always reported as invalid, never as valid.
5. Errors: `InvoiceBuilderException extends KsefException`; `InvoiceValidationException extends XmlValidationException` (from
   `src/errors.ts`) and adds `issues` with `fatal` level and rule `code` (the base class only has `error|warning` and no code).
6. Model loader: JSON arrays are never accepted where an object is expected (PHP's `array` type conflates them); `fromModel` also accepts
   already-parsed objects, `fromJson` takes text. Fixture README says "no trailing newline" but the files (and both writers) end with LF; the port
   matches the actual bytes.
7. `sample(sellerNip, invoiceNumber, now?: Date)`; due date is `now + 14 days` in UTC (no DST arithmetic), same as PHP.

## Engine choice: `xmllint-wasm` 5.3.0 (libxml2 compiled to WebAssembly)

- Real libxml2 XSD validation, i.e. the same engine family as PHP's `DOMDocument::schemaValidate`; messages such as
  `Element '{ns}P_13_1': [facet 'fractionDigits'] ...` are the same text. Pure-JS XSD validators were not considered fit (incomplete facet/pattern
  coverage, different messages). Native bindings (node-libxml) need a toolchain.
- Offline imports: the main schema and every `.xsd` below its directory are preloaded into Emscripten's in-memory FS; relative
  `schemaLocation`s resolve there. Verified: the bundle validates; with no preload the import fails; with the official unresolved schema
  (http imports) the engine refuses ("failed to load external entity") and the validator reports `engine`, never "valid".
- Cannot fetch remote resources: WASM has no sockets; additionally `--nonet` is passed. Test: a live `127.0.0.1` HTTP listener, a schema
  importing from it and a document with `xsi:schemaLocation` pointing at it receive 0 requests.
- Pre-checks run before parsing (size, empty, BOM, UTF-16, UTF-8 validity, prolog encoding, DOCTYPE/ENTITY, forbidden characters), so entity
  expansion cannot happen. Processing instructions and forbidden characters via character references are checked after parsing
  (as in PHP) with `@xmldom/xmldom` (already a dependency of the package) on the already well-formed document, so PHP's precedence is kept.
- Memory is bounded: initial 16 MiB, hard cap 128 MiB (`INITIAL_PAGES`/`MAX_PAGES` in `xml-validator.ts`); a worker per call, terminated afterwards.

Measured (Windows, Node 24.13, raw engine call with the same options; one-off, not an SLA): ~1.06 MB invoice (4600 lines): valid in ~0.1 s;
~4.1 MB: valid in ~0.14 s with a 32 MiB cap; 3 MB of `<a/>` elements: rejected in ~0.12 s; 100000-deep nesting: libxml depth limit (257), 45 ms;
a 16 MiB cap on a 4 MB document: out-of-memory is thrown by the engine and mapped to `engine`. The test suite asserts only < 10 s.

## Unverified / limitations

- Tested on Windows + Node 24 only. Other platforms and Node 22 not run.
- libxml2 inside xmllint-wasm may be a different version than PHP's: message wording could differ for cases not in the fixtures.
  Columns of XML (parser) errors are derived from xmllint's caret line (verified only for the fixtures/`<a>`); schema errors have column 0 (as libxml).
- No timeout/abort for a running validation (the size limit and memory cap are the bounds). Each call spawns a worker (tens of ms).
- Warnings from libxml schema validation (level `warning`) are mapped when xmllint prints them; none were observed.
- `package.json` `files` must include `resources` or the published package will lack the XSD bundle (not edited by this task).
