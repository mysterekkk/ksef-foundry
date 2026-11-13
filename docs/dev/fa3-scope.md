# FA(3) invoice layer: scope, decisions, pitfalls

Code: `packages/php/src/Invoice/**`. Fixtures: `fixtures/fa3/`. Tests: `packages/php/tests/Invoice/`.

## XSD version and provenance

- Schema `schemat_FA3_v1-0E.xsd`, `targetNamespace` = `http://crd.gov.pl/wzor/2025/06/25/13775/`,
  `KodFormularza` `kodSystemowy="FA (3)"` `wersjaSchemy="1-0E"`, `WariantFormularza` = 3.
- The official files import `http://crd.gov.pl/.../StrukturyDanych_v10-0E.xsd` remotely. `scripts/resolve-xsd.php`
  creates derived copies in `schemas/xsd/FA/resolved/` and `packages/php/resources/xsd/FA3/` where ONLY
  `schemaLocation` attribute values are rewritten to relative local paths (every other byte untouched,
  including the original copyright header). Both outputs are byte-identical (tested).

| File | sha256 source | sha256 resolved |
|------|---------------|-----------------|
| schemat_FA3_v1-0E.xsd | adcfbb10cf0d4d7537dd18d3e86440f2f092f8dce6bad68e77a8f191a2c10709 | 526588d5b91932bb9e189ec9a5176840c649cbe927285d004e6ae6f6361c8196 |
| bazowe/StrukturyDanych_v10-0E.xsd | cb08348374598e1e716e086c40d740390fb9e1bfa3aba1f4ec4cba0e1ef6d60f | same (already relative) |
| bazowe/ElementarneTypyDanych_v10-0E.xsd | 8daf4d3771de200b26b697294cc906a2add3de9acfbbd97f4b1bd4fc0e5ecb2f | same |
| bazowe/KodyKrajow_v10-0E.xsd | 1d41a1b3184188f2d20a51d3afde26204dda182ec5dacf018204dcc9870dc644 | same |

`SHA256SUMS.txt` next to the copies records both. If git normalises line endings (the XSDs use CR / CRLF),
hashes change: add `*.xsd -text` to `.gitattributes`.

## Supported variant

Standard VAT invoice, `RodzajFaktury` = `VAT`, currency PLN, one seller (Podmiot1), one buyer (Podmiot2).

| Area | Supported |
|------|-----------|
| Header | KodFormularza/WariantFormularza fixed, DataWytworzeniaFa, SystemInfo |
| Seller | NIP, name, address (TAdres: KodKraju, AdresL1, AdresL2) |
| Buyer id | NIP; EU VAT (KodUE + NrVatUE); other id (KodKraju?, NrID); BrakID. `JST`=2, `GV`=2 always emitted (required by XSD) |
| Fa header | KodWaluty=PLN, P_1, P_1M, P_2, P_6 |
| Rates (P_12 -> bucket) | 23,22 -> P_13_1/P_14_1; 8,7 -> P_13_2/P_14_2; 5 -> P_13_3/P_14_3; 0 KR -> P_13_6_1; 0 WDT -> P_13_6_2 (EU buyer enforced); 0 EX -> P_13_6_3; zw -> P_13_7 (+P_19/P_19A|B|C); np I -> P_13_8; np II -> P_13_9 |
| Totals | P_13_n, P_14_n, P_15 computed exactly |
| Adnotacje | P_16=2, P_17=2, P_18=2, P_18A=2, Zwolnienie (P_19N=1 or P_19=1+P_19A/B/C), NoweSrodkiTransportu/P_22N=1, P_23=2, PMarzy/P_PMarzyN=1 |
| Lines | NrWierszaFa, P_7, P_8A, P_8B, P_9A, P_11, P_12 |
| Payment | Zaplacono+DataZaplaty, TerminPlatnosci/Termin, FormaPlatnosci 1..7, RachunekBankowy (NrRB, SWIFT, NazwaBanku, OpisRachunku) |
| Other | DodatkowyOpis (key/value), Stopka/Informacje/StopkaFaktury |

## Unsupported (rejected or not offered; never silently emitted)

KOR, KOR_ZAL, KOR_ROZ, ZAL, ROZ, UPR invoice types; foreign currency (P_14_nW, KursWalutyZ, KursWaluty);
split payment (P_18A is always 2); reverse charge (`oo`, P_18=1, P_13_10); taxi flat rate (rates 4, 3 -> P_13_4);
OSS / special procedure (P_13_5, P_12_XII); margin procedure (PMarzy, P_13_11); new means of transport (P_22);
self-billing (P_17=1); cash method (P_16=1); simplified triangulation (P_23=1); Podmiot3, PodmiotUpowazniony,
Podmiot1K/2K, AdresKoresp, DaneKontaktowe, NrEORI, NrKlienta, IDNabywcy; WZ, OkresFa; FP, TP; ZaliczkaCzesciowa,
FakturaZaliczkowa, Zamowienie, Rozliczenie (charges/deductions), Skonto, LinkDoPlatnosci, IPKSeF, partial payments;
WarunkiTransakcji (contracts, orders, transport); attachments (Zalacznik); per-line P_6A, P_9B, P_10, P_11A,
P_11Vat, GTU, Procedura, Indeks, GTIN, PKWiU, CN, PKOB, KwotaAkcyzy, UU_ID, StanPrzed; ZwrotAkcyzy; Rejestry; FA(2).
Unsupported XSD-valid constructs can still be *validated* with `XmlValidator` (it validates any FA(3) XML).

## Rounding decision (documented, official sources silent)

The XSD and the KSeF guide do not prescribe how to round. Decision (standard practice): line net P_11 =
half-up(P_8B x P_9A, 2) (or explicit `netValue`); P_13_n = sum of line nets of the bucket; P_14_n = half-up(P_13_n x rate/100, 2)
once per bucket (never per line); P_15 = sum of P_13_n + P_14_n. Half-up is "away from zero". Fraction digits
come from the XSD: TKwotowy 2 (pattern `-?([1-9]\d{0,15}|0)(\.\d{1,2})?`), TKwotowy2 8 (P_9A, max 14 integer digits),
TIlosci 6 (P_8B, max 16 integer digits). The builder rejects (never rounds) quantities/prices with more digits.

## Discovered XSD pitfalls

- TNrNIP pattern `[1-9]((\d[1-9])|([1-9]\d))\d{7}`: digits 2 and 3 may not both be 0; checksum NOT in XSD (KSeF PROD checks it).
- TKwotowy pattern forbids leading zeros and `+`; `1000.001` fails fractionDigits; at most 16 integer digits although totalDigits is 18.
- `xsd:token` text fields (TZnakowy*) collapse whitespace *before* length checks; the builder normalises identically.
- Podmiot2 requires `JST` and `GV` elements; Podmiot1 `Adres` is required; Adnotacje requires all nested blocks in order.
- `FaWiersz` comes AFTER `DodatkowyOpis`; `Platnosc` after `FaWiersz`; P_13_x/P_14_x pairs are optional sequences; P_13_6_x..P_13_11 have no P_14.
- P_13_5 has an optional P_14_5; P_14_nW exist only for foreign currency.
- Buyer EU prefix uses the VAT-prefix list (`EL` for Greece, `XI` for Northern Ireland); NrVatUE pattern max 12 chars `[0-9A-Z+*]`.
- Remote `schemaLocation` in the official file; hence the resolved copies.
- KSeF-only rules not in XSD: P_1 not later than receipt; NIP checksum on PROD; duplicate key seller NIP + RodzajFaktury + P_2 (error 440);
  1 MB limit without attachments (the validator default is 3 MB as the ceiling for attachments; pass `maxBytes: 1_000_000` for plain invoices).

## Validator rules (before XSD)

size limit; UTF-8 without BOM; no UTF-16; prolog encoding must be UTF-8; no DOCTYPE/ENTITY (rejected before parsing, so no
entity expansion or file/network access); no processing instructions; no forbidden characters (raw or via character reference).
Loading uses `LIBXML_NONET`, never `LIBXML_NOENT`/`DTDLOAD`; `libxml_use_internal_errors` state is restored.
Note: `libxml_clear_errors()` clears errors a caller had queued under internal-errors mode.

## README snippet for packages/php (to be pasted by the lead)

> **FA(3) builder** (`LuroWeb\KsefFoundry\Invoice`): `Fa3InvoiceBuilder` builds schema-valid standard VAT invoices
> (PLN, rates 23/22/8/7/5/0 KR/0 EX/0 WDT/zw/np I/np II). NOT supported: KOR/ZAL/ROZ/UPR, foreign currency, split payment,
> reverse charge, margin, OSS, taxi rates, Podmiot3, attachments, FP/TP and the other items listed in `docs/dev/fa3-scope.md`.
> `XmlValidator` validates any FA(3) XML against the official XSD offline. XSD validity does not guarantee KSeF acceptance.
