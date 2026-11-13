# FA(3) shared fixtures

Language-neutral test data shared by the PHP package and the future TypeScript port.
Schema: `schemat_FA3_v1-0E.xsd`, namespace `http://crd.gov.pl/wzor/2025/06/25/13775/`.

Per case `<name>`:

| File | Meaning |
|------|---------|
| `<name>.input.json` | builder model (below) |
| `<name>.expected.xml` | exact XML the builder must produce (byte-for-byte, LF, 2-space indent, no trailing newline); XSD-validated and tax-reviewed |
| `<name>.expected.totals.json` | `{lineNets: [...], fields: {P_13_n, P_14_n, P_15}}`; all amounts are strings with exactly 2 decimals |

Regenerate (PHP): `php fixtures/fa3/generate.php`. A port must reproduce the XML and totals from the input.

## Input model

All decimals are JSON **strings** (or integers). JSON floats are rejected (they would lose exactness).
Unknown keys are rejected. `?` = optional.

```
{
  "seller":  { "nip": "10 digits", "name": "1..512", "address": {"line1","line2?","country?":"PL"} },
  "buyer":   { "idType": "nip|eu|foreign|none", "name", "address": {...},
               "nip" (nip) | "euPrefix","euVatNumber" (eu) | "countryCode?","taxId" (foreign) },
  "number": "P_2, 1..256", "issueDate": "YYYY-MM-DD", "issuePlace?", "saleDate?" (P_6),
  "currency?": "PLN",            // only PLN supported
  "createdAt": "YYYY-MM-DDTHH:MM:SSZ",   // DataWytworzeniaFa (UTC, >= 2025-09-01)
  "systemInfo?",
  "lines": [ { "name","unit?","quantity" (<=6 decimals, >0),"unitNetPrice" (<=8 decimals),
               "netValue?" (explicit P_11, <=2 decimals),
               "vatRate": "23|22|8|7|5|0 KR|0 WDT|0 EX|zw|np I|np II" } ],
  "exemptionBasis?": {"type?":"statute|directive|other","text"},   // required iff a line has "zw"
  "payment?": { "method?":1..7, "paidDate?", "dueDates?":[...], "bankAccounts?":[{"number","swift?","bankName?","description?"}] },
  "additionalInfo?": [ {"key","value"} ],
  "footer?": "1..3500 chars"
}
```

Text rules: token fields are whitespace-collapsed and trimmed (XSD `xsd:token`), then length-checked
in Unicode characters. Characters forbidden by XML 1.0 / KSeF cause rejection.

Rounding: line net = round-half-up(quantity x unitNetPrice, 2); `P_13_n` = sum of line nets of the bucket;
`P_14_n` = round-half-up(`P_13_n` x rate / 100, 2); `P_15` = sum of all `P_13_n` + `P_14_n`.

## Cases

01 single 23% line; 02 mixed 23/8/5; 03 zw + np I + np II + 0 KR; 04 rounding traps (3 x 33.33, qty 0.333,
price 0.005 / 0.004 boundaries, 6/8 fraction digits); 05 100 lines of 0.07 @23% (per-bucket VAT 1.61, not 2.00);
06 special characters (`& < > " '`, `]]>`, `</Wartosc>` injection text, diacritics, emoji); 07 text at field maxima;
08 EU buyer + 0 WDT; 09 buyer without id; 10 payment + 2 bank accounts; 11 numeric maxima; 12 paid invoice;
13 export 0 EX with foreign buyer.

## invalid/

Each `invalid/<name>.xml` has `<name>.expected-errors.txt`: first line `code=<rule>. <explanation>`,
optional `contains=<substring of the libxml message>`. Rule codes: `xsd`, `doctype`, `bom`, `encoding`, `utf8`,
`forbidden-char`, `processing-instruction`, `size`. `oversize` is not stored (3 MB); see its description file.
