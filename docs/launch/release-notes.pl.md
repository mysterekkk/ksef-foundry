# KSeF Foundry v0.1.0 (wczesne wydanie)

Pierwsze publiczne wydanie KSeF Foundry: otwartoźródłowych SDK dla Krajowego Systemu e-Faktur (KSeF 2.0) dla **PHP / Laravel** oraz **TypeScript / NestJS**, licencja MIT.

To niezależny projekt, niezwiązany z Ministerstwem Finansów. Istnieją inne biblioteki KSeF (oficjalne klienty C# i Java Ministerstwa Finansów oraz niezależne klienty PHP i TypeScript). KSeF Foundry nie jest pierwsze. Celem jest spójne zachowanie w dwóch ekosystemach, adaptery do frameworków i jawny model zgodności z oficjalnym API.

## Instalacja: przeczytaj najpierw

Pakiety są **przygotowane** do Packagist i npm, ale **nie zostały jeszcze opublikowane**. `composer require` i `npm install` z publicznych rejestrów na razie nie zadziałają.

| Pakiet | Rola |
|---|---|
| `luroweb/ksef-foundry` | SDK PHP (PHP 8.2+, PSR-17/18) |
| `luroweb/laravel-ksef-foundry` | adapter Laravel 12 |
| `@luroweb/ksef-foundry` | SDK TypeScript (Node 22+, ESM) |
| `@luroweb/nestjs-ksef-foundry` | adapter NestJS 11 |

Na razie instalacja z klonu repozytorium: repozytorium typu `path` w Composerze dla PHP, workspace npm lub paczka z `npm pack` dla TypeScript. Kroki: [docs/pl/](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/pl/index.md) oraz [docs/en/quick-start.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/en/quick-start.md).

## Co zawiera v0.1.0

- Uwierzytelnianie tokenem KSeF i certyfikatem (XAdES-BES, RSA i ECDSA).
- Cache JWT, odświeżanie i ponowne logowanie, izolacja kontekstu podatnika.
- Zarządzanie tokenami KSeF (`/tokens`).
- Sesja interaktywna: szyfrowanie faktury AES, szyfrowanie klucza RSA-OAEP (SHA-256), wysyłka FA(3) aż do statusu końcowego.
- Numer KSeF, pobranie faktury z kontrolą skrótu, UPO faktury i sesji.
- Wykrywanie duplikatów (440) i uzgadnianie po skrócie faktury po niejednoznacznym wyniku. SDK nie wysyła ponownie na ślepo.
- Zapytania o metadane ze stronicowaniem, limity, sesje uwierzytelniania.
- Builder FA(3) dla standardowej faktury VAT w PLN z dokładną arytmetyką dziesiętną oraz offline'owa walidacja XSD względem oficjalnego schematu. Wynik TypeScript jest bajt w bajt zgodny z PHP.
- Adapter Laravel: service provider, fasada, cache tokenów, joby kolejki, `ksef:check`. Adapter NestJS: `KsefModule.forRoot/forRootAsync`, filtr wyjątków, health indicator.
- Bezpieczne domyślne ustawienia: domyślne środowisko to TEST, TLS nie da się wyłączyć, sekrety nie trafiają do logów i wyjątków, XML parsowany offline bez rozwijania encji.

## Co zweryfikowano i jak

- **Zweryfikowane na żywo w KSeF TEST** (`api-test.ksef.mf.gov.pl`, kontrakt 2.9.0, sprawdzony 2026-11-13): uwierzytelnianie (token oraz certyfikat samopodpisany, RSA i ECDSA), zarządzanie tokenami, otwarcie i zamknięcie sesji, zaszyfrowana wysyłka FA(3) do statusu 200, pobranie faktury ze zgodnym skrótem, UPO, wykrycie duplikatu (440), zapytania o metadane, limity, sesje.
- **Sprawdzone tylko na prawdziwym lokalnym serwerze HTTP z wstrzykiwaniem błędów** (to nie jest dowód zgodności z KSeF): 429 z `Retry-After`, 5xx, timeouty, zerwane połączenia, ponawianie pobrania faktury, gdy jest niedostępna dłużej niż 15 s, oraz kształt kilku odpowiedzi (np. faktury odrzucone, UPO po numerze KSeF w PHP).
- **Nigdy nie używane:** DEMO i PRODUKCJA. Żaden test ani przykład ich nie dotyka.
- **Niezweryfikowane:** certyfikaty kwalifikowane, certyfikaty uwierzytelniające wydane przez KSeF, `certificateFingerprint`, inne typy kontekstu, platformy inne niż Windows z Node 24.
- Podpis UPO nie jest weryfikowany kryptograficznie. UPO zwracane przez TEST nie przechodzi też walidacji `upo-v4-3.xsd` z dwóch znanych powodów opisanych w `docs/dev/findings-live-test.md`.

Zasada projektu: bez mocków tam, gdzie logikę da się uruchomić naprawdę. Kryptografia, XSD i API KSeF TEST są testowane naprawdę. Testy live są opcjonalne (`KSEF_LIVE_TEST=1`), działają wyłącznie na TEST, a przebieg, który się nie odbył, jest raportowany jako `SKIPPED`, nigdy jako sukces.

Wyniki testów dla tego wydania: link do raportu (`docs/dev/test-report.md`) dodaje opiekun projektu w opisie wydania.

## Czego NIE ma

- Sesji wsadowych, eksportu paczek, zarządzania uprawnieniami, rejestracji certyfikatów KSeF, trybów offline, kodów QR, Peppol, identyfikatorów zbiorczych.
- `GET /sessions` (lista sesji) w PHP.
- Faktur korygujących (KOR), zaliczkowych (ZAL/ROZ), uproszczonych (UPR), waluty obcej, podzielonej płatności, odwrotnego obciążenia, procedury marży, OSS, załączników, podmiotów trzecich (Podmiot3) i innych wariantów. Pełna lista: [docs/dev/fa3-scope.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/dev/fa3-scope.md). Nieobsługiwane konstrukcje są odrzucane, nigdy po cichu pomijane. Zgodność z XSD nie gwarantuje przyjęcia przez KSeF.
- Obsługiwany jest tylko FA(3) (bez FA(2)).

## Wypróbuj przykłady

Wszystkie przykłady działają na TEST z syntetycznym podatnikiem (losowy NIP/PESEL, certyfikat samopodpisany). Nie potrzeba sekretów ani prawdziwych danych.

```bash
# PHP
cd examples/php
composer install
composer quickstart      # limity i zapytanie o metadane
composer build-validate  # zbuduj i zwaliduj FA(3), offline
composer send            # wyślij fakturę próbną do TEST
composer errors          # obsługa błędów (lokalny serwer demo)

# TypeScript (z katalogu głównego repozytorium)
npm install
npm run build -w @luroweb/ksef-foundry
npm run 03:send -w @luroweb/ksef-foundry-example-typescript
```

Środowisko TEST jest współdzielone i bywa opóźnione: uprawnienia utworzone przez `/testdata/person` mogą działać dopiero po kilku sekundach, a pobranie zaraz po statusie 200 może wymagać krótkiego ponowienia. SDK obsługuje oba przypadki, ale zdarzają się wolniejsze przebiegi.

## Znane ograniczenia

- Status prawny tokenów KSeF po 31.12.2026 jest niejednoznaczny (MF zapowiada ich utrzymanie, tekst rozporządzenia nadal podaje tę datę). Dla nowych wdrożeń produkcyjnych bezpieczniejszy jest certyfikat, ale certyfikaty inne niż samopodpisane z TEST nie są tu zweryfikowane. Zob. [docs/api-compatibility.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/api-compatibility.md).
- Wersja 0.x: publiczne API może się zmienić.
- Sprawdzona wersja kontraktu: KSeF API 2.9.0. Cotygodniowy workflow wykrywa zmiany kontraktu i otwiera zgłoszenie. Nigdy nie aktualizuje SDK automatycznie.

## Bezpieczeństwo i współpraca

- Luki zgłaszaj zgodnie z [SECURITY.md](https://github.com/mysterekkk/ksef-foundry/blob/main/SECURITY.md). Nie otwieraj publicznych zgłoszeń dla luk bezpieczeństwa.
- Wkład w projekt: [CONTRIBUTING.md](https://github.com/mysterekkk/ksef-foundry/blob/main/CONTRIBUTING.md).
- Pytania i błędy: [GitHub Issues](https://github.com/mysterekkk/ksef-foundry/issues).

Tworzone i utrzymywane przez LuroWeb (autor: [mysterekkk](https://github.com/mysterekkk)). Biblioteka jest darmowa (MIT); opcjonalne wdrożenia opisuje [docs/commercial-integrations.md](https://github.com/mysterekkk/ksef-foundry/blob/main/docs/commercial-integrations.md).
