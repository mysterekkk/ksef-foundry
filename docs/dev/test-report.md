# Raport testów / Test report — v0.1.0 (2026-11-13)

Wyniki uruchomione lokalnie (Windows 11, PHP 8.4.20, Node 24.13, Composer 2.9.7) tuż przed wydaniem. Liczby pochodzą z faktycznych uruchomień, nie z deklaracji.
**Ograniczenie:** CI na macierzy (PHP 8.2/8.3, Node 22, Linux) jest sprawdzane osobno po wypchnięciu — zob. status w README/Actions. Lokalnie nie testowano PHP 8.2/8.3, Node 22 ani Linuksa.

## Testy bez dostępu do sieci KSeF (domyślne w CI)

| Pakiet | Wynik | Warstwy |
|---|---|---|
| PHP `luroweb/ksef-foundry` | **273 testy, 4978 asercji** — OK | unit 62 · contract 35 · fault-injection 66 · invoice (FA(3), XSD) 110; PHPStan level max (src) + 6 (testy), PSR-12 bez błędów |
| Laravel `luroweb/laravel-ksef-foundry` | **60 testów, 343 asercje** — OK (Laravel 12 / Testbench 10); Laravel 11 / Testbench 9 sprawdzono wcześniej (51 testów, przed ostatnimi poprawkami) | integracja frameworkowa (kontener, cache, kolejka bazodanowa, zdarzenia) + fault-injection; PHPStan max (larastan) |
| Przykład Laravel | **8 testów, 32 asercje** — OK | przez prawdziwy kernel HTTP |
| TypeScript `@luroweb/ksef-foundry` | **306 testów w 14 plikach** — OK | unit · contract · fault-injection · invoice (FA(3)); typecheck strict, ESLint, build, `verify:pack` (tarball, ESM import, `require(esm)`, publint, attw) |
| NestJS `@luroweb/nestjs-ksef-foundry` | **67 testów w 5 plikach** — OK | integracja frameworkowa (prawdziwy kontener DI, prawdziwe HTTP, supertest) + fault-injection; `verify:pack` |

Dodatkowo: równoważność PHP ↔ TypeScript — 13 wspólnych fixtures FA(3) daje **bajt w bajt identyczny XML**; 16 niepoprawnych fixtures odrzucanych z tymi samymi kodami reguł.

## Testy live na KSeF TEST (opt-in, `KSEF_LIVE_TEST=1`)

Dane syntetyczne (losowy NIP/PESEL z poprawną sumą kontrolną, certyfikat samopodpisany), brak sekretów, wyłącznie `api-test.ksef.mf.gov.pl`. Uruchomienie 2026-11-13 ok. 23:10 (poza oknem serwisowym 16:00–18:00).

| Pakiet | Wynik live |
|---|---|
| TypeScript | 19/19 — OK |
| PHP | 11/11 — OK (40 asercji) |
| NestJS (przez kontrolery przykładowej aplikacji) | 9/9 — OK |
| Laravel (pakiet / przykład) | 1/1 (15 asercji) / 1/1 (19 asercji) — OK |

Zakres live (wszystkie pakiety łącznie): klucze publiczne (weryfikacja `publicKeyId`), challenge, logowanie XAdES (cert samopodpisany RSA-2048 i ECDSA P-256), `POST /tokens`, logowanie tokenem KSeF, refresh, limity, sesje uwierzytelnienia, otwarcie/zamknięcie sesji z szyfrowaniem AES+RSA-OAEP, **wysyłka FA(3) do statusu 200 z numerem KSeF**, pobranie faktury (zgodność skrótu), UPO faktury i sesji, **odrzucenie duplikatu (440)**, zapytanie o metadane, sprzątanie danych testowych.

## Czego te testy NIE dowodzą

- **Brak weryfikacji na DEMO i PRODUCTION** — nigdy z nich nie korzystano (poza pobraniem publicznych plików `openapi.json` do wykrywania zmian kontraktu).
- **Niezweryfikowane live:** certyfikaty kwalifikowane/pieczęcie, certyfikat KSeF (Authentication), `certificateFingerprint`, kontekst `InternalId`/`NipVatUe`, RSA-PSS (niezaimplementowane), ECDSA P-384/P-521.
- **Fault-injection ≠ zgodność z KSeF:** 429 + `Retry-After`, 5xx, timeouty, reset połączenia, ucięte odpowiedzi, błędy 21470/21184 — sprawdzone wyłącznie na prawdziwym lokalnym serwerze HTTP, nie na prawdziwym KSeF.
- Podpis XAdES na UPO nie jest weryfikowany kryptograficznie (UPO jest walidowane XSD z dwoma znanymi odchyleniami TEST — zob. `findings-live-test.md` pkt 10).
- Brak testów obciążeniowych; Redis/baza jako store cache w Laravelu nie były uruchamiane (tylko array i file); Nest 10 i Octane/Horizon nie testowane.
- „Bez mocków": nie używamy obiektów mock/stub dla kryptografii, XSD, HTTP ani API TEST. Scenariusze awarii używają prawdziwych lokalnych serwerów (TCP/HTTP). Szczegóły i granice: `docs/pl/testowanie.md`.
