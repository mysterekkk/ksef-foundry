# Faktura FA(3): budowanie i walidacja

Ten przewodnik opisuje builder faktur `Fa3InvoiceBuilder`, walidator `XmlValidator`, zakres obsługiwanych faktur oraz zasady zaokrąglania i limity. Jest to **wąski, świadomie ograniczony zakres**: SDK zwraca błąd zamiast po cichu wygenerować niepełną fakturę.

## Spis treści

- [Zakres w skrócie](#zakres-w-skrócie)
- [Budowanie faktury](#budowanie-faktury)
- [Walidacja XSD offline](#walidacja-xsd-offline)
- [Macierz obsługiwanych funkcji](#macierz-obsługiwanych-funkcji)
- [Zaokrąglanie](#zaokrąglanie)
- [Limity rozmiaru](#limity-rozmiaru)
- [Pułapki XSD, które SDK już obsługuje](#pułapki-xsd-które-sdk-już-obsługuje)

## Zakres w skrócie

Obsługiwana jest **standardowa faktura VAT** (`RodzajFaktury` = `VAT`) w **PLN**, z jednym sprzedawcą (Podmiot1) i jednym nabywcą (Podmiot2). Schemat: `schemat_FA3_v1-0E.xsd` (`kodSystemowy="FA (3)"`, `wersjaSchemy="1-0E"`, przestrzeń nazw `http://crd.gov.pl/wzor/2025/06/25/13775/`). Faktury korygujące, zaliczkowe, w walucie obcej i wiele innych **nie są obsługiwane** (pełna lista niżej).

Zgodność z XSD nie gwarantuje przyjęcia przez KSeF: KSeF stosuje dodatkowe reguły (np. data wystawienia nie późniejsza niż odbiór, suma kontrolna NIP na PRODUCTION, unikalność numeru).

## Budowanie faktury

Kwoty podajesz jako **ciągi znaków z dokładnymi liczbami dziesiętnymi**, nigdy jako `float`. Kod z `examples/php/src/02-build-and-validate.php`:

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
$xml = $builder->build(); // samowalidacja względem oficjalnego XSD FA(3)
```

Odpowiednik TypeScript (`examples/typescript/src/02-build-and-validate.ts`). **`build()` jest asynchroniczne**, bo walidacja XSD działa w wątku roboczym WebAssembly:

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

Dostępne metody (te same nazwy w PHP i TypeScript): `seller`, `buyerNip`, `buyerEu(prefix, vatNumber, ...)`, `buyerForeign(countryCode, taxId, ...)`, `buyerNoId`, `number`, `issueDate`, `issuePlace`, `saleDate`, `currency`, `createdAt`, `systemInfo`, `addLine(name, quantity, unitNetPrice, vatRate, unit, netValue)`, `exemptionBasis`, `paymentMethod` (1 gotówka, 2 karta, 3 bon, 4 czek, 5 kredyt, 6 przelew, 7 mobilna), `paid`, `dueDate`, `bankAccount`, `additionalInfo`, `footer`, `totals`, `build`.

Inne sposoby:

| Cel | PHP | TypeScript |
|---|---|---|
| Faktura próbna do testów | `Fa3InvoiceBuilder::sampleXml($nip, $numer)` | `await Fa3InvoiceBuilder.sampleXml(nip, numer)` |
| Z modelu (tablica / JSON) | `Fa3InvoiceBuilder::fromArray($model)` | `Fa3InvoiceBuilder.fromModel(model)`, `fromJson(text)` |
| Bez samowalidacji | `->withoutSelfValidation()` | `->withoutSelfValidation()`, `buildUnvalidated()` (synchronicznie, bez XSD) |
| Suma kontrolna NIP | `Fa3InvoiceBuilder::isValidNipChecksum($nip)` | `Fa3InvoiceBuilder.isValidNipChecksum(nip)` |

Format modelu JSON opisuje `fixtures/fa3/README.md` w repozytorium. Faktura próbna zbudowana przez `sample` została na żywo przyjęta przez TEST bez zmian (status 200, nadany numer KSeF).

Błędy modelu (np. zbyt wiele cyfr po przecinku, nieobsługiwana stawka) zgłaszają `InvoiceBuilderException`; nigdy nie jest emitowany nieprawidłowy XML. Uwaga: w PHP `InvoiceBuilderException` dziedziczy po `\InvalidArgumentException`, w TypeScript po `KsefException` (patrz [Obsługa błędów](obsluga-bledow.md)).

**Różnice TypeScript względem PHP** (celowe): metody `build`, `sampleXml` i walidatora zwracają `Promise`; w JS nie ma rozróżnienia int/float, więc `1.0` jest liczbą całkowitą `1` i jest akceptowane, natomiast każda niecałkowita liczba `number`, `NaN`, `Infinity` i liczby powyżej 2^53-1 są odrzucane. Akceptowane typy: `string | bigint | bezpieczna liczba całkowita | Decimal`. Dla ułamków zawsze używaj ciągów znaków (`'33.33'`).

## Walidacja XSD offline

`XmlValidator` sprawdza **dowolny** dokument FA(3) względem oficjalnego XSD dołączonego do pakietu, bez dostępu do sieci i bez rozwijania encji:

```php
use LuroWeb\KsefFoundry\Invoice\XmlValidator;

$validator = new XmlValidator();
$result = $validator->validateFa3($xml);
if (!$result->isValid()) {
    foreach ($result->errors() as $issue) {
        echo "line {$issue->line}: [{$issue->code}] {$issue->message}\n";
    }
}
$validator->assertValid($xml); // zgłasza InvoiceValidationException
```

```ts
const validator = new XmlValidator();
const result = await validator.validateFa3(xml); // string | Uint8Array
console.log(result.isValid(), result.errors());
await validator.assertValid(xml);                // zgłasza InvoiceValidationException (issues[])
```

Przed XSD sprawdzane są reguły techniczne KSeF: limit rozmiaru, kodowanie UTF-8 bez BOM (UTF-16 odrzucone), kodowanie w prologu musi być UTF-8, brak `DOCTYPE`/`ENTITY` (odrzucane przed parsowaniem, więc nie ma rozwijania encji ani dostępu do plików lub sieci), brak instrukcji przetwarzania, brak zabronionych znaków (także przez odwołania znakowe). Kody problemów (`code`) to m.in. `size`, `bom`, `encoding`, `utf8`, `forbidden-char`, `doctype`; w TypeScript dodatkowo `engine` (silnik WASM zawiódł: raportowane zawsze jako nieprawidłowe, nigdy jako prawidłowe).

`submit()` i `sendInvoice()` uruchamiają ten walidator domyślnie, **zanim otworzą sesję**; błędny XML nie kosztuje więc otwarcia ani zamknięcia sesji. Wyłączenie: `new SendOptions(validate: false)` (PHP) lub `validate: false` (TypeScript); własny walidator: `SendOptions(validator: ...)` / funkcja w `validate`.

Silnik walidacji: PHP używa libxml (`DOMDocument::schemaValidate`); TypeScript używa `xmllint-wasm` 5.3.0 (libxml2 skompilowane do WebAssembly). Komunikaty są zwykle identyczne, ale dla przypadków spoza testowanych fikstur brzmienie może się różnić. Testowano TypeScript tylko na Windows z Node 24; inne platformy i Node 22 nie były uruchamiane.

## Macierz obsługiwanych funkcji

| Obszar | Obsługiwane |
|---|---|
| Nagłówek | `KodFormularza` / `WariantFormularza` (stałe), `DataWytworzeniaFa`, `SystemInfo` |
| Sprzedawca | NIP, nazwa, adres (`KodKraju`, `AdresL1`, `AdresL2`) |
| Nabywca | NIP; UE (prefiks + numer VAT UE); inny identyfikator (kraj opcjonalnie + numer); brak identyfikatora. `JST` = 2 i `GV` = 2 zawsze emitowane (wymaga ich XSD) |
| Faktura | waluta PLN, `P_1`, `P_1M`, `P_2`, `P_6` |
| Stawki | 23, 22 (`P_13_1`/`P_14_1`); 8, 7 (`P_13_2`/`P_14_2`); 5 (`P_13_3`/`P_14_3`); 0 KR (`P_13_6_1`); 0 WDT (`P_13_6_2`, wymusza nabywcę z UE); 0 EX (`P_13_6_3`); zw (`P_13_7` plus `P_19`/`P_19A`/`P_19B`/`P_19C`); np I (`P_13_8`); np II (`P_13_9`) |
| Sumy | `P_13_n`, `P_14_n`, `P_15` liczone dokładnie |
| Adnotacje | `P_16`=2, `P_17`=2, `P_18`=2, `P_18A`=2, zwolnienie, `P_22N`=1, `P_23`=2, procedura marży `P_PMarzyN`=1 |
| Wiersze | `NrWierszaFa`, `P_7`, `P_8A`, `P_8B`, `P_9A`, `P_11`, `P_12` (maks. 10000 wierszy) |
| Płatność | zapłacono + data, termin, forma płatności 1..7, rachunek bankowy (numer, SWIFT, nazwa banku, opis) |
| Inne | `DodatkowyOpis` (klucz/wartość), stopka faktury |

**Nieobsługiwane** (odrzucane lub nieoferowane; nigdy po cichu emitowane): rodzaje faktur KOR, KOR_ZAL, KOR_ROZ, ZAL, ROZ, UPR; waluty obce (`P_14_nW`, kursy); split payment (`P_18A` zawsze 2); odwrotne obciążenie (`oo`, `P_18`=1, `P_13_10`); stawki taxi (4, 3); procedury OSS i szczególne (`P_13_5`, `P_12_XII`); procedura marży jako rozliczenie (`PMarzy`, `P_13_11`); nowe środki transportu (`P_22`); samofakturowanie (`P_17`=1); metoda kasowa (`P_16`=1); uproszczone trójstronne (`P_23`=1); Podmiot3, PodmiotUpowazniony, Podmiot1K/2K, AdresKoresp, DaneKontaktowe, NrEORI, NrKlienta, IDNabywcy; WZ, OkresFa; FP, TP; zaliczki (`ZaliczkaCzesciowa`, `FakturaZaliczkowa`), Zamowienie, Rozliczenie, Skonto, LinkDoPlatnosci, IPKSeF, płatności częściowe; WarunkiTransakcji; załączniki (`Zalacznik`); pola wierszowe `P_6A`, `P_9B`, `P_10`, `P_11A`, `P_11Vat`, GTU, Procedura, Indeks, GTIN, PKWiU, CN, PKOB, KwotaAkcyzy, UU_ID, StanPrzed; ZwrotAkcyzy; Rejestry; FA(2); formaty PEF i FA_RR.

Konstrukcje poprawne w XSD, ale nieobsługiwane przez builder, można nadal **walidować** przez `XmlValidator`, który sprawdza dowolny dokument FA(3). Nie oznacza to, że SDK potrafi je zbudować.

## Zaokrąglanie

Ani XSD, ani przewodnik KSeF nie opisują sposobu zaokrąglania. Przyjęta w SDK decyzja (praktyka standardowa, udokumentowana):

- wartość netto wiersza `P_11` = zaokrąglenie *half-up* z `P_8B x P_9A` do 2 miejsc (albo jawne `netValue`),
- `P_13_n` = suma wartości netto wierszy w danym koszyku stawki,
- `P_14_n` = *half-up*(`P_13_n x stawka / 100`, 2) **raz na koszyk**, nigdy per wiersz,
- `P_15` = suma wszystkich `P_13_n` i `P_14_n`.

*Half-up* oznacza zaokrąglanie „od zera". Liczba miejsc po przecinku wynika z XSD: kwoty (`TKwotowy`) 2, cena jednostkowa `P_9A` 8 (maks. 14 cyfr całkowitych), ilość `P_8B` 6 (maks. 16 cyfr całkowitych). Builder **odrzuca** (nie zaokrągla) ilości i ceny z większą liczbą cyfr. Arytmetyka jest dokładna: PHP używa `bcmath` na ciągach znaków, TypeScript własnej klasy `Decimal` opartej na `bigint`.

Sprawdź zgodność tych reguł z własną polityką księgową przed wdrożeniem; `$builder->totals()` pozwala obejrzeć sumy bez budowania XML.

## Limity rozmiaru

- `XmlValidator` ma domyślnie górny limit **3 000 000 bajtów** (sufit dla faktur z załącznikami).
- KSeF dopuszcza **1 MB dla faktury bez załączników**. Dla zwykłych faktur ustaw limit jawnie:

```php
$send = new SendOptions(validator: new XmlValidator(maxBytes: 1_000_000));
$client->sessions()->sendInvoice($session, $xml, $send);
// przy submit(): new SubmitOptions(send: $send)
```

```ts
const strict = new XmlValidator({ maxBytes: 1_000_000 });
await client.sender.submit(xml, { validate: (x) => strict.assertValid(x) });
```
- Faktycznie obowiązujące limity Twojego kontekstu (maksymalna liczba faktur w sesji, maks. rozmiar faktury w MB) odczytasz z API: `$client->limits()->context()` / `client.limits.context()`.
- Załączniki nie są obsługiwane przez builder.

## Pułapki XSD, które SDK już obsługuje

- NIP: wzorzec `[1-9]((\d[1-9])|([1-9]\d))\d{7}` (cyfry 2 i 3 nie mogą być jednocześnie zerami); suma kontrolna **nie** jest w XSD.
- Kwoty: brak zer wiodących i znaku `+`; `1000.001` łamie `fractionDigits`; maks. 16 cyfr całkowitych.
- Pola tekstowe `xsd:token` zwijają białe znaki **przed** sprawdzeniem długości; builder normalizuje tak samo.
- Kolejność elementów: `FaWiersz` po `DodatkowyOpis`, `Platnosc` po `FaWiersz`; Podmiot2 wymaga `JST` i `GV`; Adnotacje wymagają wszystkich bloków w kolejności.
- Prefiks UE używa listy prefiksów VAT (`EL` dla Grecji, `XI` dla Irlandii Północnej).
- Oficjalny XSD importuje schematy zdalnie; SDK dołącza kopie, w których przepisano **tylko** wartości `schemaLocation` na ścieżki względne (reszta bajtów bez zmian), dzięki czemu walidacja działa offline.

---

Built and maintained by LuroWeb.
