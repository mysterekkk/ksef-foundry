# Środowiska: TEST, DEMO, PRODUCTION

KSeF udostępnia trzy środowiska. SDK zna je wszystkie, ale **domyślnie używa TEST**, a PRODUCTION wymaga jawnego wyboru. Poniżej referencja oraz zasady.

## Spis treści

- [Adresy i wersje](#adresy-i-wersje)
- [Wybór środowiska w kodzie](#wybór-środowiska-w-kodzie)
- [Co dopuszcza każde środowisko](#co-dopuszcza-każde-środowisko)
- [Zasady korzystania ze wspólnego TEST](#zasady-korzystania-ze-wspólnego-test)
- [Tokeny i certyfikaty nie są przenośne](#tokeny-i-certyfikaty-nie-są-przenośne)
- [Środowisko własne (`custom`)](#środowisko-własne-custom)
- [Lista kontrolna przed PRODUCTION](#lista-kontrolna-przed-production)

## Adresy i wersje

| Środowisko | Adres bazowy | Wersja API (changelog MF) | Weryfikacja kontraktu |
|---|---|---|---|
| TEST (RC) | `https://api-test.ksef.mf.gov.pl/v2` | 2.9.0 | 2026-11-13 |
| DEMO | `https://api-demo.ksef.mf.gov.pl/v2` | 2.9.0 | 2026-11-13 |
| PRODUCTION | `https://api.ksef.mf.gov.pl/v2` | 2.9.0 | 2026-11-13 |

Kopie kontraktów OpenAPI trzymamy w `schemas/openapi/{test,demo,prod}.json`. Plik OpenAPI zawiera w `info.version` tylko `v2`; numer 2.9.0 pochodzi z oficjalnego changelogu MF. TEST ma 78 ścieżek (63 wspólne plus endpointy `/testdata/*`), DEMO i PRODUCTION po 63. Nie zakładamy, że TEST i PRODUCTION mają zawsze tę samą wersję: skrypt wykrywania zmian porównuje je osobno ([Aktualizacja kontraktu](aktualizacja-kontraktu.md)).

**Co zweryfikowano w praktyce:** testy live uruchomiono wyłącznie na **TEST**. DEMO i PRODUCTION nigdy nie były używane przez testy, przykłady ani CI. Zgodność SDK z tymi dwoma środowiskami wynika z kontraktu OpenAPI, nie z prób na żywo.

## Wybór środowiska w kodzie

```php
// PHP
use LuroWeb\KsefFoundry\Environment;

KsefClient::create($credentials);                          // TEST (domyślnie)
KsefClient::create($credentials, Environment::production()); // jawnie PRODUCTION
// dostępne: Environment::test(), ::demo(), ::production(), ::custom($baseUrl)
```

```ts
// TypeScript
import { Environment, KsefClient } from '@luroweb/ksef-foundry';

KsefClient.create({ credentials });                                        // TEST (domyślnie)
KsefClient.create({ credentials, environment: Environment.PRODUCTION });   // jawnie PRODUCTION
// dostępne: Environment.TEST, .DEMO, .PRODUCTION, Environment.custom(baseUrl)
```

**Laravel:** `KSEF_ENVIRONMENT` (puste lub brak = `test`; `test|demo|production|custom`, wielkość liter i spacje nie mają znaczenia; każda inna wartość powoduje błąd). Komenda `php artisan ksef:check` ostrzega napisem „PRODUCTION environment selected.", gdy połączenie wskazuje PRODUCTION.

**NestJS:** opcja `environment` w `KsefModule.forRoot` (domyślnie `Environment.TEST`). Przykładowa aplikacja `examples/nestjs` celowo odrzuca wszystko poza `KSEF_ENVIRONMENT=test`.

Pomocnik `testData` (`/testdata/*`) istnieje tylko dla TEST: w PHP wywołanie `$client->testData()` na innym środowisku zgłasza `ConfigurationException`.

## Co dopuszcza każde środowisko

Dozwolone formaty faktur według kontraktu 2.9.0:

| Środowisko | FA(2) | FA(3) | FA_PEF(3) | FA_KOR_PEF(3) |
|---|---|---|---|---|
| TEST | tak | tak | tak | tak |
| DEMO | nie | tak | tak | tak |
| PRODUCTION | nie | tak | tak | tak |

SDK generuje i wysyła wyłącznie **FA(3)** (standardowa faktura VAT w PLN, patrz [Faktura FA(3)](faktura-fa3.md)). Formaty PEF oraz FA(2) nie są zaimplementowane.

Różnice, które warto znać:

- **Certyfikaty samopodpisane** (używane przez przykłady i testy) działają tylko na TEST. Na DEMO i PRODUCTION potrzebny jest certyfikat uwierzytelniający wydany w KSeF lub kwalifikowany; to nie zostało zweryfikowane na żywo ([Uwierzytelnianie](uwierzytelnianie.md)).
- **UPO z TEST nie przechodzi walidacji `upo-v4-3.xsd`**: nazwa podmiotu przyjmującego brzmi „Ministerstwo Finansów - środowisko testowe (TE)", a dokument zawiera podpis `ds:Signature`, którego XSD nie deklaruje. Podpis UPO nie jest przez SDK weryfikowany kryptograficznie.
- XSD nie sprawdza sumy kontrolnej NIP, a PRODUCTION ją sprawdza (według notatek projektu). Builder nie wymusza sumy kontrolnej; udostępnia pomocnik `Fa3InvoiceBuilder::isValidNipChecksum()` (PHP) / `Fa3InvoiceBuilder.isValidNipChecksum()` (TypeScript), z którego warto skorzystać przed wysyłką na PRODUCTION.

## Zasady korzystania ze wspólnego TEST

TEST jest wspólny dla wszystkich integratorów. Zachowuj się tak, jak byś chciał, żeby zachowywali się inni:

- **Dane syntetyczne.** Losowy NIP i PESEL (z poprawną sumą kontrolną), nigdy prawdziwe. Pomocniki: `SyntheticIdentity::nip()` / `pesel()` (PHP), `generateTestNip()` / `generateTestPesel()` (TypeScript).
- **Losowe numery faktur.** KSeF odrzuca duplikaty (klucz: NIP sprzedawcy, rodzaj faktury, numer faktury; kod 440). Przykłady używają `FV/EX/<czas>`.
- **Niski wolumen.** Nie uruchamiaj pętli obciążeniowych. Otwierasz i zamykasz sesje oszczędnie: walidacja XML odbywa się przed otwarciem sesji, żeby błędny dokument nie kosztował sesji.
- **Okno serwisowe 16:00-18:00 czasu Europe/Warsaw.** Testy live w repozytorium same się wtedy pomijają (wynik SKIPPED). To okno przyjęto w projekcie jako okno serwisowe środowiska TEST; sprawdzaj bieżące komunikaty MF, bo harmonogram może się zmienić.
- **Sprzątanie.** Przykłady na koniec wołają `removePerson` (best effort).
- **Testy live są ręczne.** Workflow `Live TEST (manual)` uruchamia się wyłącznie na żądanie ([Testowanie](testowanie.md)).

## Tokeny i certyfikaty nie są przenośne

Token KSeF i certyfikat uwierzytelniający są wystawiane **w obrębie jednego środowiska**. Token z TEST nie zadziała na DEMO ani PRODUCTION i odwrotnie; to samo dotyczy tokenów tworzonych przez `POST /tokens`.

SDK wzmacnia tę granicę: klucz pamięci podręcznej JWT (*tenant key*) zawiera nazwę i adres środowiska, typ i wartość kontekstu, metodę uwierzytelniania oraz odcisk poświadczeń. Zmiana środowiska nigdy więc nie użyje cudzego JWT z cache. Dobra praktyka: trzymaj osobne sekrety i osobną konfigurację dla każdego środowiska, nigdy w jednym pliku `.env` bez wyraźnego oznaczenia.

## Środowisko własne (`custom`)

`Environment::custom($baseUrl)` / `Environment.custom(baseUrl)` istnieje wyłącznie dla serwerów fault-injection i ewentualnych przyszłych środowisk MF. Adres musi używać `https`; wyjątkiem są hosty pętli zwrotnej (`127.0.0.1`, `::1`, `localhost`), które mogą używać `http`. Adres nie może zawierać danych logowania, a w PHP także zapytania ani fragmentu. Nazwa środowiska to `CUSTOM`. **Nie używaj go z prawdziwymi danymi.** W Laravelu wymaga `base_url` w konfiguracji połączenia.

SDK wywołuje wyłącznie adresy wyprowadzone z adresu bazowego wybranego środowiska i nie podąża za adresami bezwzględnymi z odpowiedzi API ([Bezpieczeństwo](bezpieczenstwo.md)).

## Lista kontrolna przed PRODUCTION

1. Przeczytaj [Uwierzytelnianie](uwierzytelnianie.md): dla nowych integracji zalecany jest certyfikat KSeF (Authentication); status prawny tokenów jest niejednoznaczny.
2. Pamiętaj, że logowanie kwalifikowanym lub wystawionym w KSeF certyfikatem nie zostało zweryfikowane na żywo.
3. Ustaw środowisko jawnie i zweryfikuj komendą `php artisan ksef:check` (Laravel) lub własnym health checkiem ([NestJS](nestjs.md)).
4. Przejdź [Bezpieczeństwo](bezpieczenstwo.md): sekrety w menedżerze sekretów, własny `TokenStorage` szyfrowany w spoczynku, wyłączone logowanie treści faktur.
5. Zacznij od pojedynczej faktury i obserwuj status; pamiętaj, że HTTP 202 przy wysyłce nie oznacza przyjęcia ([Cykl życia faktury](cykl-zycia-faktury.md)).
6. Zakres FA(3) jest wąski: sprawdź, czy Twoje faktury się w nim mieszczą.

---

Built and maintained by LuroWeb.
