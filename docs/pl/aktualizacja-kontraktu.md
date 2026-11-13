# Aktualizacja kontraktu OpenAPI

Zachowanie SDK wynika z oficjalnego kontraktu KSeF API 2.0 (OpenAPI i przewodnik Ministerstwa Finansów), a nie ze zgadywania. Ten przewodnik opisuje, jak projekt wykrywa zmiany kontraktu i jak bezpiecznie je wdrożyć. Adresowany jest głównie do osób utrzymujących lub rozwijających repozytorium; użytkownicy SDK dowiedzą się z niego, co oznacza „zweryfikowano względem 2.9.0".

## Spis treści

- [Stan obecny](#stan-obecny)
- [Wykrywanie zmian](#wykrywanie-zmian)
- [Procedura aktualizacji](#procedura-aktualizacji)
- [Czego skrypt nie robi](#czego-skrypt-nie-robi)
- [Powiązane pliki](#powiązane-pliki)

## Stan obecny

| Środowisko | Plik w repozytorium | Wersja API (changelog MF) | Data weryfikacji |
|---|---|---|---|
| TEST | `schemas/openapi/test.json` | 2.9.0 | 2026-11-13 |
| DEMO | `schemas/openapi/demo.json` | 2.9.0 | 2026-11-13 |
| PRODUCTION | `schemas/openapi/prod.json` | 2.9.0 | 2026-11-13 |

Sumy SHA-256 i liczbę ścieżek zapisuje `schemas/openapi/manifest.json`. Pole `info.version` w plikach OpenAPI zawiera tylko `v2`; numer 2.9.0 pochodzi z oficjalnego changelogu, nie z pliku. Pełna tabela i źródła: `docs/api-compatibility.md`.

## Wykrywanie zmian

Skrypt `scripts/check-openapi-drift.mjs` (Node >= 22):

```bash
node scripts/check-openapi-drift.mjs            # porównanie; kod wyjścia 1 przy zmianie
node scripts/check-openapi-drift.mjs --update   # odświeża kopie i manifest (po zmianach w kodzie i testach!)
```

Co robi:

1. Pobiera trzy oficjalne pliki OpenAPI (`https://api-test.ksef.mf.gov.pl/docs/v2/openapi.json`, analogicznie DEMO i PRODUCTION). Kontaktuje się wyłącznie z tymi trzema oficjalnymi hostami i wykonuje tylko odczyt (GET).
2. Porównuje skrót SHA-256 każdego pliku z kopią w repozytorium. Każde środowisko jest porównywane **osobno**: nie zakładamy, że TEST i PRODUCTION mają tę samą wersję.
3. Dla zmienionych kontraktów wypisuje operacje **dodane**, **usunięte** i **nowo oznaczone jako deprecated**.
4. Kody wyjścia: `0` brak zmian, `1` wykryto dryf, `2` nie udało się pobrać któregoś pliku.

Workflow GitHub Actions `OpenAPI drift` (`openapi-drift.yml`) uruchamia skrypt **co tydzień** (poniedziałek 07:00 UTC) i na żądanie. Przy kodzie `1` **otwiera zgłoszenie** z etykietą `api-drift` i wynikiem skryptu; przy kodzie `2` kończy się błędem. **Nigdy nie zmienia kodu SDK ani nie wydaje wersji.**

## Procedura aktualizacji

Zgodnie z `CONTRIBUTING.md`:

1. **Uruchom skrypt** i przeczytaj wynik oraz zgłoszenie `api-drift`.
2. **Przeczytaj changelog MF** (repozytorium `CIRFMF/ksef-api`, plik `api-changelog.md`, oraz przewodnik w katalogu `docs`) i różnicę między plikami. Skrypt pokazuje tylko operacje; zmiany na poziomie schematów (pola, wyliczenia, nagłówki) sprawdź ręcznie porównując pliki.
3. **Zaimplementuj** zmiany w obu SDK: PHP i TypeScript muszą zachować się identycznie (te same nazwy, te same reguły ponawiania, ten sam model błędów). Nie wołaj operacji oznaczonych jako deprecated. Typy TypeScript odtwórz poleceniem `npm run generate:types` (generuje z `schemas/openapi/test.json`).
4. **Zaktualizuj schematy XSD**, jeśli zmienił się FA(3) lub schemat uwierzytelniania: oficjalne pliki importują schematy zdalnie, dlatego kopie lokalne generuje `scripts/resolve-xsd.php` (przepisuje tylko `schemaLocation`; sumy kontrolne w `SHA256SUMS.txt`). Pamiętaj, że kopie XSD w pakiecie TypeScript muszą być bajt w bajt zgodne z PHP.
5. **Uruchom wszystkie testy**: jednostkowe, kontraktowe, fault-injection, frameworkowe oraz **live na TEST** ([Testowanie](testowanie.md)). Wynik `SKIPPED` testów live nie jest sukcesem.
6. **Odśwież kopie** poleceniem `node scripts/check-openapi-drift.mjs --update` i uruchom testy ponownie.
7. **Zaktualizuj dokumentację:** `docs/api-compatibility.md` (wersje, daty, sumy), tę dokumentację (PL i EN) oraz `CHANGELOG.md`. Zmiany w publicznym API wymagają wpisu w CHANGELOG i uzasadnienia; nie zmieniaj nazw publicznych symboli między wydaniami, dodawaj nowe.
8. Otwórz mały, skupiony pull request z opisem „co i dlaczego".

## Czego skrypt nie robi

- Nie aktualizuje SDK ani nie wydaje wersji.
- Nie porównuje zmian wewnątrz schematów; to krok ręczny.
- Nie sprawdza zmian XSD faktury (FA(3)) ani XSD uwierzytelniania; wykrywaj je na podstawie komunikatów MF i changeloga.
- Nie dowodzi, że SDK jest zgodne z nową wersją; dowodem są dopiero zaktualizowane testy, w tym live na TEST.
- `--update` bez wcześniejszych zmian w kodzie i testach pozostawiłoby repozytorium z kopią kontraktu, której SDK nie obsługuje. Używaj go świadomie.

## Powiązane pliki

- `scripts/check-openapi-drift.mjs`: skrypt wykrywania dryfu.
- `.github/workflows/openapi-drift.yml`: cotygodniowe uruchomienie i zgłoszenie.
- `schemas/openapi/`: kopie kontraktów i `manifest.json`.
- `docs/api-compatibility.md`: wersje, źródła, status prawny tokenów.
- `docs/dev/sdk-contract.md`: wiążący kontrakt zachowania SDK (po angielsku).
- [`CONTRIBUTING.md`](../../CONTRIBUTING.md): zasady współtworzenia.

---

Built and maintained by LuroWeb.
