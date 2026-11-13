# FA(3) invoices: building and validating

This guide covers the `Fa3InvoiceBuilder` invoice builder, the `XmlValidator`, the supported invoice scope, and the rounding rules and limits. The scope is **narrow by design**: the SDK raises an error instead of silently producing an incomplete invoice.

## Contents

- [Scope at a glance](#scope-at-a-glance)
- [Building an invoice](#building-an-invoice)
- [Offline XSD validation](#offline-xsd-validation)
- [Supported feature matrix](#supported-feature-matrix)
- [Rounding](#rounding)
- [Size limits](#size-limits)
- [XSD pitfalls the SDK already handles](#xsd-pitfalls-the-sdk-already-handles)

## Scope at a glance

Supported is a **standard VAT invoice** (`RodzajFaktury` = `VAT`) in **PLN**, with one seller (Podmiot1) and one buyer (Podmiot2). Schema: `schemat_FA3_v1-0E.xsd` (`kodSystemowy="FA (3)"`, `wersjaSchemy="1-0E"`, namespace `http://crd.gov.pl/wzor/2025/06/25/13775/`). Correction, advance and foreign-currency invoices and many other features are **not supported** (full list below).

XSD validity does not guarantee acceptance by KSeF: KSeF applies additional rules (for example, the issue date not later than receipt, the NIP checksum on PRODUCTION, and number uniqueness).

## Building an invoice

Pass amounts as **strings holding exact decimals**, never as `float`. Code from `examples/php/src/02-build-and-validate.php`:

```php
use LuroWeb\KsefFoundry\Invoice\Fa3Address;
use LuroWeb\KsefFoundry\Invoice\Fa3InvoiceBuilder;

$builder = Fa3InvoiceBuilder::create()
    ->seller($sellerNip, 'Przykładowa Sp. z o.o.', new Fa3Address('ul. Testowa 1', '00-001 Warszawa'))
    ->buyerNoId('Jan Kowalski', new Fa3Address('ul. Próbna 2', '00-002 Warszawa'))
    ->number('FV/2026/11/1')
    ->issueDate(gmdate('Y-m-d'))
    ->createdAt(new DateTimeImmutable())
    ->systemInfo('KSeF Foundry example')
    ->addLine('Usługa konsultingowa', 3, '33.33', '23', 'szt.')
    ->addLine('Książka', 1, '49.90', '5', 'szt.')
    ->paymentMethod(6);

echo json_encode($builder->totals()->toArray());
$xml = $builder->build(); // self-validates against the official FA(3) XSD
```

The TypeScript equivalent (`examples/typescript/src/02-build-and-validate.ts`). **`build()` is asynchronous**, because XSD validation runs in a WebAssembly worker thread:

```ts
import { Fa3Address, Fa3InvoiceBuilder } from '@luroweb/ksef-foundry';

const builder = Fa3InvoiceBuilder.create()
  .seller(sellerNip, 'Przykładowa Sp. z o.o.', new Fa3Address('ul. Testowa 1', '00-001 Warszawa'))
  .buyerNoId('Jan Kowalski', new Fa3Address('ul. Próbna 2', '00-002 Warszawa'))
  .number('FV/2026/11/1')
  .issueDate(new Date().toISOString().slice(0, 10))
  .createdAt(new Date())
  .addLine('Usługa konsultingowa', 3, '33.33', '23', 'szt.')
  .addLine('Książka', 1, '49.90', '5', 'szt.')
  .paymentMethod(6);

const xml = await builder.build();
```

Available methods (same names in PHP and TypeScript): `seller`, `buyerNip`, `buyerEu(prefix, vatNumber, ...)`, `buyerForeign(countryCode, taxId, ...)`, `buyerNoId`, `number`, `issueDate`, `issuePlace`, `saleDate`, `currency`, `createdAt`, `systemInfo`, `addLine(name, quantity, unitNetPrice, vatRate, unit, netValue)`, `exemptionBasis`, `paymentMethod` (1 cash, 2 card, 3 voucher, 4 cheque, 5 credit, 6 transfer, 7 mobile), `paid`, `dueDate`, `bankAccount`, `additionalInfo`, `footer`, `totals`, `build`.

Other entry points:

| Goal | PHP | TypeScript |
|---|---|---|
| Sample invoice for tests | `Fa3InvoiceBuilder::sampleXml($nip, $number)` | `await Fa3InvoiceBuilder.sampleXml(nip, number)` |
| From a model (array / JSON) | `Fa3InvoiceBuilder::fromArray($model)` | `Fa3InvoiceBuilder.fromModel(model)`, `fromJson(text)` |
| Without self-validation | `->withoutSelfValidation()` | `->withoutSelfValidation()`, `buildUnvalidated()` (synchronous, no XSD) |
| NIP checksum | `Fa3InvoiceBuilder::isValidNipChecksum($nip)` | `Fa3InvoiceBuilder.isValidNipChecksum(nip)` |

The JSON model format is described in `fixtures/fa3/README.md` in the repository. The invoice built by `sample` was accepted by TEST live, unchanged (status 200, KSeF number assigned).

Model errors (for example too many fraction digits, an unsupported rate) raise `InvoiceBuilderException`; invalid XML is never emitted. Note: in PHP `InvoiceBuilderException` extends `\InvalidArgumentException`, in TypeScript it extends `KsefException` (see [Error handling](error-handling.md)).

**TypeScript differences from PHP** (deliberate): `build`, `sampleXml` and the validator methods return a `Promise`; JS has no int/float distinction, so `1.0` is the integer `1` and is accepted, whereas any non-integer `number`, `NaN`, `Infinity` and numbers above 2^53-1 are rejected. Accepted types: `string | bigint | safe integer | Decimal`. For fractions always use strings (`'33.33'`).

## Offline XSD validation

`XmlValidator` checks **any** FA(3) document against the official XSD bundled with the package, with no network access and no entity expansion:

```php
use LuroWeb\KsefFoundry\Invoice\XmlValidator;

$validator = new XmlValidator();
$result = $validator->validateFa3($xml);
if (!$result->isValid()) {
    foreach ($result->errors() as $issue) {
        echo "line {$issue->line}: [{$issue->code}] {$issue->message}\n";
    }
}
$validator->assertValid($xml); // throws InvoiceValidationException
```

```ts
const validator = new XmlValidator();
const result = await validator.validateFa3(xml); // string | Uint8Array
console.log(result.isValid(), result.errors());
await validator.assertValid(xml);                // throws InvoiceValidationException (issues[])
```

Before the XSD, KSeF technical rules are checked: size limit, UTF-8 encoding without BOM (UTF-16 rejected), prolog encoding must be UTF-8, no `DOCTYPE`/`ENTITY` (rejected before parsing, so there is no entity expansion or file/network access), no processing instructions, no forbidden characters (including via character references). Issue codes (`code`) include `size`, `bom`, `encoding`, `utf8`, `forbidden-char`, `doctype`; TypeScript adds `engine` (the WASM engine failed: always reported as invalid, never as valid).

`submit()` and `sendInvoice()` run this validator by default, **before opening a session**; an invalid XML therefore costs no session open or close. To disable it: `new SendOptions(validate: false)` (PHP) or `validate: false` (TypeScript); for a custom validator: `SendOptions(validator: ...)` / a function in `validate`.

Validation engine: PHP uses libxml (`DOMDocument::schemaValidate`); TypeScript uses `xmllint-wasm` 5.3.0 (libxml2 compiled to WebAssembly). Messages are usually identical, but for cases outside the tested fixtures the wording may differ. TypeScript was tested only on Windows with Node 24; other platforms and Node 22 were not run.

## Supported feature matrix

| Area | Supported |
|---|---|
| Header | `KodFormularza` / `WariantFormularza` (fixed), `DataWytworzeniaFa`, `SystemInfo` |
| Seller | NIP, name, address (`KodKraju`, `AdresL1`, `AdresL2`) |
| Buyer | NIP; EU (prefix + EU VAT number); other identifier (optional country + number); no identifier. `JST` = 2 and `GV` = 2 always emitted (required by the XSD) |
| Invoice | currency PLN, `P_1`, `P_1M`, `P_2`, `P_6` |
| Rates | 23, 22 (`P_13_1`/`P_14_1`); 8, 7 (`P_13_2`/`P_14_2`); 5 (`P_13_3`/`P_14_3`); 0 KR (`P_13_6_1`); 0 WDT (`P_13_6_2`, enforces an EU buyer); 0 EX (`P_13_6_3`); zw (`P_13_7` plus `P_19`/`P_19A`/`P_19B`/`P_19C`); np I (`P_13_8`); np II (`P_13_9`) |
| Totals | `P_13_n`, `P_14_n`, `P_15` computed exactly |
| Annotations | `P_16`=2, `P_17`=2, `P_18`=2, `P_18A`=2, exemption, `P_22N`=1, `P_23`=2, margin scheme `P_PMarzyN`=1 |
| Lines | `NrWierszaFa`, `P_7`, `P_8A`, `P_8B`, `P_9A`, `P_11`, `P_12` (max 10000 lines) |
| Payment | paid + date, due date, payment method 1..7, bank account (number, SWIFT, bank name, description) |
| Other | `DodatkowyOpis` (key/value), invoice footer |

**Not supported** (rejected or not offered; never silently emitted): invoice types KOR, KOR_ZAL, KOR_ROZ, ZAL, ROZ, UPR; foreign currencies (`P_14_nW`, exchange rates); split payment (`P_18A` is always 2); reverse charge (`oo`, `P_18`=1, `P_13_10`); taxi rates (4, 3); OSS and special procedures (`P_13_5`, `P_12_XII`); margin procedure as a settlement (`PMarzy`, `P_13_11`); new means of transport (`P_22`); self-billing (`P_17`=1); cash method (`P_16`=1); simplified triangulation (`P_23`=1); Podmiot3, PodmiotUpowazniony, Podmiot1K/2K, AdresKoresp, DaneKontaktowe, NrEORI, NrKlienta, IDNabywcy; WZ, OkresFa; FP, TP; advances (`ZaliczkaCzesciowa`, `FakturaZaliczkowa`), Zamowienie, Rozliczenie, Skonto, LinkDoPlatnosci, IPKSeF, partial payments; WarunkiTransakcji; attachments (`Zalacznik`); line-level fields `P_6A`, `P_9B`, `P_10`, `P_11A`, `P_11Vat`, GTU, Procedura, Indeks, GTIN, PKWiU, CN, PKOB, KwotaAkcyzy, UU_ID, StanPrzed; ZwrotAkcyzy; Rejestry; FA(2); the PEF and FA_RR formats.

XSD-valid constructs the builder does not support can still be **validated** with `XmlValidator`, which checks any FA(3) document. This does not mean the SDK can build them.

## Rounding

Neither the XSD nor the KSeF guide says how to round. The SDK's documented decision (standard practice):

- line net value `P_11` = *half-up* rounding of `P_8B x P_9A` to 2 places (or an explicit `netValue`),
- `P_13_n` = the sum of line net values in the rate bucket,
- `P_14_n` = *half-up*(`P_13_n x rate / 100`, 2) **once per bucket**, never per line,
- `P_15` = the sum of all `P_13_n` and `P_14_n`.

*Half-up* means rounding "away from zero". The number of fraction digits comes from the XSD: amounts (`TKwotowy`) 2, unit price `P_9A` 8 (max 14 integer digits), quantity `P_8B` 6 (max 16 integer digits). The builder **rejects** (does not round) quantities and prices with more digits. Arithmetic is exact: PHP uses `bcmath` on strings, TypeScript uses its own `bigint`-based `Decimal` class.

Check that these rules match your own accounting policy before going live; `$builder->totals()` lets you inspect totals without building XML.

## Size limits

- `XmlValidator` has a default ceiling of **3,000,000 bytes** (the ceiling for invoices with attachments).
- KSeF allows **1 MB for an invoice without attachments**. For ordinary invoices set the limit explicitly:

```php
$send = new SendOptions(validator: new XmlValidator(maxBytes: 1_000_000));
$client->sessions()->sendInvoice($session, $xml, $send);
// with submit(): new SubmitOptions(send: $send)
```

```ts
const strict = new XmlValidator({ maxBytes: 1_000_000 });
await client.sender.submit(xml, { validate: (x) => strict.assertValid(x) });
```

- The limits actually in force for your context (maximum invoices per session, maximum invoice size in MB) can be read from the API: `$client->limits()->context()` / `client.limits.context()`.
- Attachments are not supported by the builder.

## XSD pitfalls the SDK already handles

- NIP: pattern `[1-9]((\d[1-9])|([1-9]\d))\d{7}` (digits 2 and 3 may not both be zero); the checksum is **not** in the XSD.
- Amounts: no leading zeros or `+` sign; `1000.001` breaks `fractionDigits`; at most 16 integer digits.
- `xsd:token` text fields collapse whitespace **before** the length check; the builder normalises the same way.
- Element order: `FaWiersz` after `DodatkowyOpis`, `Platnosc` after `FaWiersz`; Podmiot2 requires `JST` and `GV`; Adnotacje require all blocks in order.
- The EU prefix uses the VAT prefix list (`EL` for Greece, `XI` for Northern Ireland).
- The official XSD imports schemas remotely; the SDK ships copies in which **only** the `schemaLocation` values were rewritten to relative paths (all other bytes untouched), so validation works offline.

---

Built and maintained by LuroWeb.
