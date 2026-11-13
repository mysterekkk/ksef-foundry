# Testowanie

Ten przewodnik wyjaśnia, jak testowane jest SDK, jak uruchomić każdy zestaw testów oraz, równie ważne, **czego testy nie dowodzą**. Zasada projektu: żywe API wygrywa z dokumentacją, a pominięty test nigdy nie jest zgłaszany jako zaliczony.

## Spis treści

- [Warstwy testów](#warstwy-testów)
- [Polityka „bez mocków" i jej zakres](#polityka-bez-mocków-i-jej-zakres)
- [Jak uruchomić testy](#jak-uruchomić-testy)
- [Testy live na TEST](#testy-live-na-test)
- [CI](#ci)
- [Czego testy nie dowodzą](#czego-testy-nie-dowodzą)

## Warstwy testów

| Warstwa | Co sprawdza | Sieć do KSeF |
|---|---|---|
| **unit** | czysta logika: arytmetyka dziesiętna, redakcja sekretów, budowa URL, obliczenia ponawiania | nie |
| **contract** | przykłady z oficjalnego OpenAPI względem naszych modeli (deserializacja i z powrotem), prawdziwa walidacja XSD, kryptografia na znanych wynikach | nie |
| **fault-injection** | scenariusze awarii (429 z `Retry-After`, 500, timeout, zerwanie połączenia, ucięte ciało, powolne ciało) na **prawdziwym lokalnym serwerze HTTP** | nie |
| **framework** | adaptery: Laravel (Testbench, prawdziwy kontener i cache), NestJS (`Test.createTestingModule`, prawdziwe DI) | nie |
| **live TEST** | pełny scenariusz na `api-test.ksef.mf.gov.pl` | tak (tylko TEST) |

Testy fault-injection są oznaczone tą nazwą i **nigdy nie są przedstawiane jako dowód zgodności z API**: prawdziwego KSeF nie da się poprosić o 429 ani 500.

## Polityka „bez mocków" i jej zakres

Reguła: **żadnych obiektów-atrap dla zachowania, które da się uruchomić naprawdę.** Dokładnie:

- Kryptografia (RSA-OAEP, AES, SHA-256, XAdES) jest testowana prawdziwymi algorytmami; testy znanych wyników używają stałych kluczy i wartości z niezależnie policzonymi oczekiwaniami.
- Walidacja XSD używa prawdziwego, oficjalnego schematu.
- Wszystko, co rozmawia z KSeF, jest dowodzone testami live na TEST.
- Awarie, których prawdziwe API nie wywoła na żądanie, są odtwarzane na prawdziwym serwerze HTTP w procesie lokalnym (PHP: `tests/Fault/raw_server.php`; TypeScript: lokalny serwer w testach; NestJS: `test/support/auth-server.ts`), nie na atrapie klienta.
- Frameworki są testowane z prawdziwym DI i prawdziwym cache.

**Zakres tej reguły jest precyzyjny.** Nie oznacza ona, że w kodzie nie ma żadnych wstrzykiwanych zależności. SDK udostępnia punkty wstrzyknięcia zegara i funkcji `sleep` (używane, by testy nie czekały realnie); to zastępniki czasu, nie atrapy zachowania API. Nie oznacza też, że fault-injection dowodzi zgodności z KSeF: dowodzi wyłącznie, że **nasz kod** zachowuje się poprawnie, gdy serwer odpowie w dany sposób.

## Jak uruchomić testy

Wymagania: PHP >= 8.2 (z `bcmath`, `dom`, `libxml`, `openssl`, `mbstring`, `curl`), Composer 2, Node >= 22. Polecenia z korzenia klona.

**PHP (`packages/php`)**

```bash
cd packages/php
composer install
composer test           # unit + contract + fault + invoice (bez live)
composer test:unit      # także test:contract, test:fault, test:invoice
composer check          # phpcs (PSR-12) + phpstan (src: max, tests: 6) + testy
```

**Laravel (`packages/laravel`)**

```bash
cd packages/laravel
composer install
composer test           # unit + feature (Testbench) + fault
composer check          # phpcs + phpstan (larastan) + testy
```

Zestaw adaptera uruchomiono na Laravel 12.72.0 i 11.57.1: po 51 testów (263 asercje) zielone. Aplikacja przykładowa: `cd examples/laravel && vendor/bin/phpunit --testsuite default`.

**TypeScript (`packages/typescript`)**

```bash
npm ci
npm run build --workspaces --if-present
cd packages/typescript
npm test                # unit + contract + fault + invoice (bez live)
npm run test:unit       # także test:contract, test:fault, test:invoice
npm run typecheck && npm run lint
npm run verify:pack     # npm pack, instalacja tarballa, import smoke test
```

**NestJS (`packages/nestjs`)**

```bash
npm test -w @luroweb/nestjs-ksef-foundry          # prawdziwe DI Nest, HTTP, kryptografia, lokalne serwery
npm run verify:pack -w @luroweb/nestjs-ksef-foundry
```

**Wszystko dla Node naraz:** `npm test --workspaces --if-present`.

## Testy live na TEST

Testy live są **opcjonalne** i uruchamiane ręcznie, bo TEST jest wspólnym środowiskiem wielu integratorów.

```bash
KSEF_LIVE_TEST=1 composer --working-dir=packages/php test:live
KSEF_LIVE_TEST=1 npm --workspace @luroweb/ksef-foundry run test:live
KSEF_LIVE_TEST=1 npm --workspace @luroweb/nestjs-ksef-foundry run test:live
composer --working-dir=packages/laravel test:live      # skrypt sam ustawia KSEF_LIVE_TEST=1
```

(W PowerShellu ustaw zmienną przez `$env:KSEF_LIVE_TEST = '1'`.)

Jak to działa:

1. **Zmienna `KSEF_LIVE_TEST=1` jest wymagana.** Bez niej wynik to jawny komunikat `SKIPPED (live TEST disabled)`; **pominięcie nie jest zaliczeniem**.
2. **Środowisko jest zawsze TEST**; kod testów odmawia pracy z DEMO i PRODUCTION.
3. **Nie potrzeba żadnych sekretów.** Test sam tworzy syntetycznego podatnika: losuje NIP i PESEL z poprawną sumą kontrolną (nigdy prawdziwe), wywołuje `POST /testdata/person`, generuje samopodpisany certyfikat osoby (`serialNumber = PNOPL-<pesel>`), loguje się XAdES, tworzy token KSeF przez `POST /tokens` i loguje się tokenem.
4. Dalej: zapytania, sesja online, wysyłka syntetycznej faktury FA(3) z losowym numerem, odpytywanie statusu, UPO, pobranie po numerze KSeF, duplikat (440).
5. **Okno serwisowe 16:00-18:00 czasu Europe/Warsaw:** w tym czasie scenariusze wysyłki są pomijane i raportowane jako `SKIPPED`, nie jako sukces. Logowanie po `/testdata/person` jest ponawiane przy statusie 415 ([Uwierzytelnianie](uwierzytelnianie.md#status-415-tuż-po-testdataperson)).
6. Wolumen jest niski i identyfikatory losowe, zgodnie z zasadami wspólnego TEST ([Środowiska](srodowiska.md#zasady-korzystania-ze-wspólnego-test)).

Ręczny workflow GitHub Actions `Live TEST (manual)` (`workflow_dispatch`, bez sekretów) uruchamia testy live wszystkich czterech pakietów.

## CI

Domyślne CI uruchamia warstwy unit, contract, fault-injection i framework (bez live):

- PHP 8.2, 8.3 i 8.4 (`composer check`),
- Laravel 11 i 12 na PHP 8.2-8.4 w czterech kombinacjach (`composer check`),
- Node 22 i 24: build, typecheck, lint, testy, `verify:pack` oraz przykład `02:build-validate`,
- audyt zależności produkcyjnych (`npm audit`, `composer audit`) i kontrola braku sekretów w śledzonych plikach.

Wykrywanie zmian kontraktu OpenAPI działa osobno, raz w tygodniu ([Aktualizacja kontraktu](aktualizacja-kontraktu.md)).

## Czego testy nie dowodzą

Szczerze, na dzień 2026-11-13:

- **DEMO i PRODUCTION nigdy nie były używane** przez testy, przykłady ani CI.
- **Certyfikaty kwalifikowane**, certyfikat uwierzytelniający wydany w KSeF i `certificateFingerprint` nie były sprawdzane na żywo; sprawdzono tylko samopodpisany certyfikat osoby (RSA 2048 i ECDSA P-256) i kontekst NIP.
- **Prawdziwy 429 z `Retry-After`**, kody 21470 i 21184 oraz błędy 500/502/503 sprawdzono tylko na lokalnym serwerze fault-injection.
- Ponawianie pobrania faktury przy niedostępności dłuższej niż 15 s sprawdzono tylko lokalnie.
- W PHP nie ma testu live dla listy sesji (`GET /sessions`, nie zaimplementowano), a `/sessions/{ref}/invoices/failed` oraz UPO po numerze KSeF sprawdzono tylko kształtem na serwerze lokalnym.
- Podpis UPO nie jest weryfikowany kryptograficznie; UPO z TEST nie przechodzi walidacji `upo-v4-3.xsd` (dwa znane odchylenia).
- Platformy: testy live TypeScript wykonano na Windows z Node 24; inne systemy i Node 22 nie były uruchamiane live. TLS na Windows w PHP wymagał magazynu certyfikatów systemu (wbudowany klient go używa).
- Laravel: lokalnie nie uruchamiano `--prefer-lowest`, PHP 8.2 i 8.3, Laravel starszego niż 11.57, Octane ani Horizon. Macierz CI obejmuje PHP 8.2-8.4, ale jej wyniki nie są tu potwierdzone. NestJS: tylko Nest 11.
- Przyjęcie faktury przez TEST nie gwarantuje przyjęcia na PRODUCTION (np. suma kontrolna NIP).
- Kontrakt zweryfikowano względem KSeF API 2.9.0 (2026-11-13); późniejsze zmiany wychwytuje skrypt dryfu, lecz nie są automatycznie wdrażane.
- Zgodność merytoryczna faktur (VAT, JPK, księgowość) leży po stronie wystawcy; SDK nie jest doradztwem podatkowym.

---

Built and maintained by LuroWeb.
