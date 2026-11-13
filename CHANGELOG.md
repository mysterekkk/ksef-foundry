# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/), wersjonowanie: [SemVer](https://semver.org/). Wersje 0.x mogą zawierać zmiany niezgodne wstecz w API publicznym (będą opisane).

## [0.1.0] — 2026-11-13

Pierwsze wydanie. Pakiety **przygotowane** do publikacji w Packagist/npm, ale **nieopublikowane**.

### Dodane
- **PHP SDK** `luroweb/ksef-foundry` (PHP >= 8.2): uwierzytelnianie tokenem KSeF i certyfikatem (XAdES-BES, RSA/ECDSA), cache JWT z odświeżaniem i izolacją kontekstów, sesja interaktywna z szyfrowaniem AES-256-CBC + RSA-OAEP-SHA256, bezpieczna wysyłka (brak ślepych ponowień, rekoncyliacja po skrócie), pobieranie faktur, zapytania o metadane, UPO, limity, zarządzanie tokenami KSeF, hierarchia wyjątków, wbudowany klient curl (PSR-18).
- **TypeScript SDK** `@luroweb/ksef-foundry` (Node >= 22, ESM): jak wyżej, z `AbortSignal`, timeoutami i polityką ponowień.
- **Builder FA(3) i walidator XSD offline** (PHP i TypeScript, bajt w bajt identyczny wynik): standardowa faktura VAT w PLN, dokładna arytmetyka dziesiętna.
- **Laravel** `luroweb/laravel-ksef-foundry` (Laravel 12): provider, konfiguracja, fasada, cache tokenów szyfrowany w spoczynku, joby kolejek (wysyłka, status, pobieranie), komenda `ksef:check`.
- **NestJS** `@luroweb/nestjs-ksef-foundry` (NestJS 11): `KsefModule.forRoot/forRootAsync`, `KsefService`, wielu klientów nazwanych, filtr wyjątków, health-check.
- Oficjalne kontrakty OpenAPI (2.9.0) i XSD w `schemas/`, skrypt wykrywania zmian kontraktu, CI, przykłady, dokumentacja PL/EN.

### Zweryfikowane na żywo (TEST)
Zob. [docs/dev/test-report.md](docs/dev/test-report.md). Nie zweryfikowano: DEMO, PRODUCTION, certyfikaty kwalifikowane/KSeF, `certificateFingerprint`.

### Nie zaimplementowano
Sesja wsadowa, eksport paczek, zarządzanie uprawnieniami, rejestracja certyfikatów KSeF, tryby offline, kody QR, faktury korygujące/zaliczkowe/inne warianty FA(3) poza standardową fakturą VAT w PLN (zob. [docs/dev/fa3-scope.md](docs/dev/fa3-scope.md)).

### Bezpieczeństwo
Niezależny przegląd bezpieczeństwa przed wydaniem; wszystkie potwierdzone ustalenia poziomu Medium naprawiono (sekrety w śladach stosu, token w zrzutach wyjątków, uszkodzona odpowiedź 2xx przy wysyłce, równoległe uruchomienia zadania wysyłki). Zob. [SECURITY.md](SECURITY.md).
