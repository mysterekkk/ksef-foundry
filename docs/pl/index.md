# Dokumentacja KSeF Foundry

KSeF Foundry to otwartoźródłowe (MIT) SDK dla polskiego Krajowego Systemu e-Faktur w wersji **KSeF API 2.0**. Repozytorium zawiera cztery pakiety: rdzeń PHP, adapter Laravel, rdzeń TypeScript i adapter NestJS. Repozytorium: <https://github.com/mysterekkk/ksef-foundry>.

> Wersja 0.1 (pre-release). Kontrakt API zweryfikowany względem KSeF API **2.9.0** (stan na 2026-11-13). Testy na żywo wykonano wyłącznie na środowisku **TEST** z danymi syntetycznymi. Środowiska DEMO i PRODUCTION nigdy nie były używane przez testy ani przykłady.

## Spis treści

| Przewodnik | Typ (Diátaxis) | Dla kogo |
|---|---|---|
| [Szybki start](szybki-start.md) | tutorial | pierwsze uruchomienie w PHP, Laravel, TypeScript, NestJS |
| [Środowiska](srodowiska.md) | reference | wybór TEST / DEMO / PRODUCTION |
| [Uwierzytelnianie](uwierzytelnianie.md) | explanation + reference | token KSeF vs certyfikat, JWT, izolacja podatników |
| [Faktura FA(3)](faktura-fa3.md) | how-to + reference | budowanie i walidacja faktur |
| [Cykl życia faktury](cykl-zycia-faktury.md) | explanation + how-to | wysyłka, statusy, UPO, duplikaty, zapytania |
| [Obsługa błędów](obsluga-bledow.md) | reference | wyjątki, ponawianie, 429, 403 |
| [Bezpieczeństwo](bezpieczenstwo.md) | explanation | zasady bezpiecznego użycia |
| [Laravel](laravel.md) | how-to | adapter Laravel |
| [NestJS](nestjs.md) | how-to | adapter NestJS |
| [Testowanie](testowanie.md) | explanation + reference | warstwy testów i granice dowodów |
| [Aktualizacja kontraktu](aktualizacja-kontraktu.md) | how-to | reakcja na zmiany OpenAPI Ministerstwa Finansów |

Dokumentacja w języku angielskim: [docs/en](../en/index.md). Notatki projektowe dla współtwórców: `docs/dev/` (po angielsku).

## Pakiety

| Pakiet | Nazwa w rejestrze | Wersja | Wymagania | Rola |
|---|---|---|---|---|
| `packages/php` | `luroweb/ksef-foundry` | 0.1.0 (pre-release) | PHP >= 8.2, `ext-bcmath`, `ext-dom`, `ext-json`, `ext-libxml`, `ext-mbstring`, `ext-openssl` | rdzeń SDK dla PHP |
| `packages/laravel` | `luroweb/laravel-ksef-foundry` | 0.1.0 (pre-release) | PHP >= 8.2, Laravel 12 | provider, cache tokenów, joby, komenda `ksef:check` |
| `packages/typescript` | `@luroweb/ksef-foundry` | 0.1.0 | Node >= 22, ESM | rdzeń SDK dla TypeScript / Node |
| `packages/nestjs` | `@luroweb/nestjs-ksef-foundry` | 0.1.0 | Node >= 22, NestJS `^11` | moduł, serwis, filtr wyjątków, health |

**Publikacja:** pakiety są przygotowane do publikacji, ale **nie zostały jeszcze opublikowane** w Packagist ani npm. Polecenia `composer require` / `npm install` z rejestru zadziałają dopiero po publikacji. Do tego czasu użyj instalacji z klona repozytorium (opis w [Szybkim starcie](szybki-start.md)).

## Status funkcji

Legenda: **Live TEST** = zaimplementowane i sprawdzone na żywo na środowisku TEST (2026-11-13). **Bez dowodu live** = zaimplementowane, ale nie sprawdzone na żywo (testy tylko na lokalnym serwerze fault-injection lub kontraktowe). **Brak** = niezaimplementowane.

| Obszar | Status |
|---|---|
| Logowanie certyfikatem (XAdES-BES), certyfikat samopodpisany, RSA 2048 i ECDSA P-256 | Live TEST |
| Logowanie tokenem KSeF, odświeżanie JWT | Live TEST |
| Tworzenie i listowanie tokenów KSeF (`/tokens`) | Live TEST |
| Sesja interaktywna: otwarcie, szyfrowana wysyłka FA(3), zamknięcie | Live TEST |
| Status faktury, numer KSeF, pobranie faktury, UPO faktury i sesji | Live TEST |
| Wykrywanie duplikatów (kod 440) | Live TEST |
| Zapytania o metadane faktur, limity, sesje uwierzytelnienia | Live TEST |
| Builder i walidator FA(3) (standardowa faktura VAT w PLN) | przyjęte przez TEST w teście live (faktura próbna); zakres: [Faktura FA(3)](faktura-fa3.md) |
| Prawdziwy HTTP 429 z `Retry-After`, błędy 21470 / 21184, 5xx | Bez dowodu live (lokalny serwer fault-injection) |
| Certyfikaty kwalifikowane i certyfikat uwierzytelniający wydany przez KSeF, `certificateFingerprint` | Bez dowodu live |
| Konteksty inne niż NIP (`InternalId`, `NipVatUe`, `PeppolId`) | Bez dowodu live |
| Środowiska DEMO i PRODUCTION | Bez dowodu live (nigdy nie używane) |
| Lista sesji (`GET /sessions`) | TypeScript: zaimplementowane; PHP: **brak** |
| Sesja wsadowa (batch), eksporty paczek, zarządzanie uprawnieniami, rejestracja certyfikatów KSeF (enrollment), identyfikatory zbiorcze, Peppol, tryby offline, kody QR | Brak |
| Faktury korygujące, zaliczkowe, rozliczeniowe, waluty obce, split payment, FA(2), PEF, FA_RR | Brak ([zakres FA(3)](faktura-fa3.md)) |

W kodzie źródłowym nie znaleziono znaczników `@experimental`. Wszystko, co nie ma dowodu live, jest opisane w tej dokumentacji jako ograniczenie.

## Co dalej

- Nowy użytkownik: [Szybki start](szybki-start.md), potem [Cykl życia faktury](cykl-zycia-faktury.md).
- Przed wdrożeniem produkcyjnym: [Środowiska](srodowiska.md), [Uwierzytelnianie](uwierzytelnianie.md), [Bezpieczeństwo](bezpieczenstwo.md).
- Usługi wdrożeniowe: opcjonalnie, przez profil autora <https://github.com/mysterekkk>. SDK jest narzędziem programistycznym, nie gotowym systemem ERP.

---

Built and maintained by LuroWeb.
