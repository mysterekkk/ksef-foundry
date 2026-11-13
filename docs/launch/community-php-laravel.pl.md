# Opis dla polskich społeczności PHP / Laravel

Język: polski. Szkic do ręcznej publikacji (grupy, fora, Discord/Slack). Przed wysłaniem przeczytaj regulamin danej społeczności dotyczący autopromocji, zaznacz, że jesteś autorem, i bądź gotów odpowiadać na pytania.

---

**KSeF Foundry v0.1.0: SDK do KSeF 2.0 dla PHP i Laravela (open source, MIT)**

Jestem autorem. Opublikowałem wczesną wersję (v0.1.0) biblioteki do integracji z Krajowym Systemem e-Faktur.

**Co to jest**

- `luroweb/ksef-foundry`: SDK PHP 8.2+ (PSR-17/18, `ext-openssl`, `ext-dom`, `ext-bcmath`)
- `luroweb/laravel-ksef-foundry`: adapter dla Laravel 12 (service provider, fasada, cache tokenów, joby kolejki, komenda `php artisan ksef:check`)
- ten sam projekt zawiera SDK TypeScript i adapter NestJS o identycznym zachowaniu, co jest przydatne, gdy firma ma backendy w obu technologiach

**Co potrafi**

- logowanie tokenem KSeF i certyfikatem (XAdES), cache i odświeżanie JWT
- sesja interaktywna z szyfrowaniem AES i RSA-OAEP SHA-256 (w PHP przez phpseclib, bo `openssl_public_encrypt` w OAEP używa tylko SHA-1)
- wysyłka FA(3) do statusu końcowego, numer KSeF, UPO, pobranie faktury z kontrolą skrótu
- wysyłka odporna na duplikaty: HTTP 202 to nie przyjęcie, a po niejednoznacznym wyniku SDK uzgadnia stan po skrócie faktury, zamiast wysyłać ponownie
- builder FA(3) z dokładną arytmetyką dziesiętną i offline'owa walidacja XSD

**Czym się różni**

Nie jest pierwsze. Są oficjalne klienty C# i Java Ministerstwa Finansów oraz niezależne klienty PHP i TypeScript. Moje założenia to PHP i TypeScript w jednym projekcie z identycznym zachowaniem, gotowe adaptery do frameworków i polityka testów bez mocków (z jawnie opisanymi granicami).

**Uczciwy status**

- Zweryfikowane na żywo tylko w środowisku TEST. DEMO i PRODUKCJI nigdy nie dotykałem.
- Certyfikaty kwalifikowane i wydane przez KSeF są niezweryfikowane (sprawdzony jest certyfikat samopodpisany w TEST).
- Nie ma sesji wsadowych, trybów offline, kodów QR ani faktur korygujących i zaliczkowych. FA(3) obejmuje standardową fakturę VAT w PLN.
- Pakiety są przygotowane, ale jeszcze nie opublikowane w Packagist, więc na razie instalacja z klonu repozytorium (repozytorium `path` w Composerze). Przykłady: `composer quickstart`, `composer send` w `examples/php`.
- Wersja 0.x, API może się zmieniać.

**Link i kontakt**

Repozytorium: https://github.com/mysterekkk/ksef-foundry
Uwagi i błędy: https://github.com/mysterekkk/ksef-foundry/issues
Autor: https://github.com/mysterekkk (LuroWeb)

Link do wyników testów dla tego wydania: [WSTAW LINK DO RAPORTU PRZED PUBLIKACJĄ]
